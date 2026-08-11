<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace local_tarsus\client;

use local_tarsus\index\status;
use local_tarsus\material\bit_type;

/**
 * Real Tarsus (Flexudy Brain) HTTP client.
 *
 * Speaks the /api/v1/brain/access/* surface: track/{text,url,document,audio,
 * video}-bits, untrack/multiple-resources and check/tracked-resources. Every
 * call is form-data with api_key + brain_id; the brain ingests asynchronously,
 * so track only ever confirms receipt and the batch check drives the state
 * machine afterwards.
 *
 * All transport and protocol failures surface as {@see transport_exception} so
 * the guardrails pipeline and the retry/backoff budget stay in control.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_client implements client_interface {

    /** @var string Common prefix of every brain endpoint. */
    private const API_PREFIX = '/api/v1/brain/';

    /** @var int Seconds allowed for a plain JSON call. */
    private const TIMEOUT = 60;

    /** @var int Seconds allowed for a call that uploads a file. */
    private const UPLOAD_TIMEOUT = 300;

    /** @var int Seconds allowed to establish the connection. */
    private const CONNECT_TIMEOUT = 10;

    /**
     * Constructor.
     *
     * @param string $baseurl Tarsus base URL.
     * @param string $apikey  Tarsus API key.
     * @param string $brainid Configured brain id (one per client).
     */
    public function __construct(
        protected string $baseurl,
        protected string $apikey,
        protected string $brainid
    ) {
    }

    #[\Override]
    public function search_grounded(string $query, string $userid, array $allowedresourceids): array {
        $body = $this->send('POST', 'access/search/bits', [
            'brain_id' => $this->brain(),
            'query' => $query,
            'user_id' => $userid,
            'with_arguments' => 'true',
            'allowed_resource_ids' => json_encode(array_values($allowedresourceids)),
        ]);

        $argument = $body['access']['arguments'][0] ?? [];
        $answered = !empty($argument['answered']);

        // The brain may return low-relevance bits even when it declined to
        // answer, so sources are only reported for a grounded answer — and
        // only ever from the allowed set, whatever came back.
        $sources = [];
        if ($answered) {
            $allowed = array_flip($allowedresourceids);
            foreach (($body['access']['bits'] ?? []) as $bit) {
                $sourceid = (string) ($bit['metadata']['sourceId'] ?? '');
                if ($sourceid === '' || !isset($allowed[$sourceid]) || isset($sources[$sourceid])) {
                    continue;
                }
                $sources[$sourceid] = [
                    'resourceid' => $sourceid,
                    'title' => (string) ($bit['title'] ?? ''),
                ];
            }
        }

        return [
            'answer' => (string) ($argument['text'] ?? ''),
            'answered' => $answered,
            'sources' => array_values($sources),
            // Spec §6 prices a grounded answer at ~6 credits; the response
            // itself carries no figure.
            'credits' => 6,
        ];
    }

    #[\Override]
    public function track_resource(array $resource): array {
        [$path, $params, $tempfile] = $this->build_track_request($resource);

        try {
            $body = $this->send('POST', $path, $params, $tempfile !== null);
        } finally {
            if ($tempfile !== null && file_exists($tempfile)) {
                @unlink($tempfile);
            }
        }

        // A missing ack is tolerated; only an explicit false means the brain
        // never got the bytes. HTTP 2xx already carries the real signal.
        if (isset($body['access']['received']) && $body['access']['received'] === false) {
            throw new transport_exception(0, 'brain did not acknowledge receipt of the resource');
        }

        // Ingestion is asynchronous: receipt is not indexation.
        return [
            'status' => status::PENDING_CONFIRMATION,
            'bitref' => '',
            'credits' => 0,
        ];
    }

    #[\Override]
    public function untrack_resource(array $resourceids): array {
        $result = [];

        foreach ($this->chunk($resourceids) as $chunk) {
            $body = $this->send('DELETE', 'access/untrack/multiple-resources', [
                'brain_id' => $this->brain(),
                'resource_ids' => json_encode(array_values($chunk)),
            ]);

            $untracked = $body['access']['untracked']['resource_ids'] ?? [];
            $untracked = is_array($untracked) ? array_flip($untracked) : [];

            foreach ($chunk as $resourceid) {
                $result[$resourceid] = isset($untracked[$resourceid]) ? 'removed' : 'unknown';
            }
        }

        return $result;
    }

    #[\Override]
    public function untrack_assets(array $assetids): array {
        $result = [];

        foreach ($this->chunk($assetids) as $chunk) {
            $body = $this->send('DELETE', 'access/untrack/multiple-assets', [
                'brain_id' => $this->brain(),
                'asset_ids' => json_encode(array_values($chunk)),
            ]);

            $untracked = $body['access']['untracked']['asset_ids'] ?? [];
            $untracked = is_array($untracked) ? array_flip($untracked) : [];

            foreach ($chunk as $assetid) {
                $result[$assetid] = isset($untracked[$assetid]) ? 'removed' : 'unknown';
            }
        }

        return $result;
    }

    #[\Override]
    public function get_resource_status(string $resourceid): array {
        // The plural endpoint reports a brain-side status; the singular one does not.
        $checked = $this->check_tracked_resources([$resourceid]);

        return $checked[$resourceid]
            ?? ['status' => status::PENDING_CONFIRMATION, 'tracked' => false, 'brainstatus' => ''];
    }

    #[\Override]
    public function check_tracked_resources(array $resourceids): array {
        $result = [];

        foreach ($this->chunk($resourceids) as $chunk) {
            $body = $this->send('GET', 'access/check/tracked-resources', [
                'brain_id' => $this->brain(),
                'resource_ids' => json_encode(array_values($chunk)),
            ]);

            foreach (($body['access'] ?? []) as $entry) {
                if (!is_array($entry) || !isset($entry['resourceId'])) {
                    continue;
                }
                $result[(string) $entry['resourceId']] = [
                    'status' => $this->map_status($entry),
                    'tracked' => !empty($entry['tracked']),
                    'brainstatus' => (string) ($entry['status'] ?? ''),
                ];
            }
        }

        return $result;
    }

    #[\Override]
    public function list_brains(): array {
        $body = $this->send('GET', 'awareness/list/memories', []);

        $brains = [];
        foreach (($body['awareness']['brain_ids'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['_id'])) {
                continue;
            }
            $brains[] = [
                'brainid' => (string) $entry['_id'],
                'active' => !empty($entry['active']),
                'language' => (string) ($entry['brainLanguage'] ?? ''),
                'tag' => (string) ($entry['brainTag'] ?? ''),
                'timecreated' => (int) ($entry['brainCreatedDateTime'] ?? 0),
            ];
        }

        return $brains;
    }

    #[\Override]
    public function generate_widgets(string $contextid, array $params): array {
        // Phase 2.
        throw new \coding_exception('generate_widgets is not implemented in Phase 1.');
    }

    #[\Override]
    public function evaluate_answer(string $contextid, array $params): array {
        // Phase 2.
        throw new \coding_exception('evaluate_answer is not implemented in Phase 1.');
    }

    #[\Override]
    public function get_usage(): array {
        // Phase 2.
        throw new \coding_exception('get_usage is not implemented in Phase 1.');
    }

    /**
     * Translate a brain status entry into the local state-machine vocabulary.
     *
     * The brain reports exactly three values (confirmed by Tarsus): done,
     * pending and failed. "failed" means the resource is simply not in the
     * brain, not that something is broken. The `tracked` flag is redundant
     * next to `status`, so it is not consulted. An unrecognised value is
     * treated as "still working", never as success, so an API change can only
     * ever cost an extra poll.
     *
     * @param array $entry One element of the check/tracked-resources response.
     * @return string A {@see status} constant.
     */
    private function map_status(array $entry): string {
        return match (strtolower((string) ($entry['status'] ?? ''))) {
            'done' => status::INDEXED,
            'failed' => status::NOT_INDEXED,
            default => status::PENDING_CONFIRMATION,
        };
    }

    /**
     * Pick the endpoint and form fields for one material payload.
     *
     * @param array $resource Payload from {@see \local_tarsus\material\material::to_payload()}.
     * @return array{0: string, 1: array, 2: string|null} Path, form fields, temp file to clean up.
     * @throws transport_exception When a file bit no longer resolves to a stored file.
     */
    private function build_track_request(array $resource): array {
        $title = (string) ($resource['title'] ?? '');
        $type = (string) ($resource['type'] ?? bit_type::TEXT);

        $common = [
            'brain_id' => $this->brain(),
            'resource_id' => (string) ($resource['resource_id'] ?? ''),
            'asset_id' => (string) ($resource['context_id'] ?? ''),
        ];

        if ($type === bit_type::URL) {
            return ['access/track/url-bits', $common + ['url' => (string) ($resource['url'] ?? '')], null];
        }

        if ($type === bit_type::DOCUMENT || $type === bit_type::MEDIA) {
            return $this->build_file_track_request($resource, $common, $title, $type);
        }

        return [
            'access/track/text-bits',
            $common + ['text' => (string) ($resource['text'] ?? ''), 'title' => $title],
            null,
        ];
    }

    /**
     * Build the multipart request for a document or media bit.
     *
     * @param array  $resource The material payload.
     * @param array  $common   Fields shared by every track call.
     * @param string $title    Human-readable title.
     * @param string $type     The bit type.
     * @return array{0: string, 1: array, 2: string} Path, form fields, temp file path.
     * @throws transport_exception When the stored file cannot be resolved.
     */
    private function build_file_track_request(array $resource, array $common, string $title, string $type): array {
        $file = $this->stored_file($resource);
        if ($file === null) {
            throw new transport_exception(0, 'stored file for the resource no longer exists');
        }

        $temppath = $file->copy_content_to_temp('local_tarsus', 'track');
        $curlfile = new \CURLFile($temppath, (string) $file->get_mimetype(), $file->get_filename());

        if ($type === bit_type::DOCUMENT) {
            return ['access/track/document-bits', $common + ['document' => $curlfile], $temppath];
        }

        $isaudio = str_starts_with((string) $file->get_mimetype(), 'audio/');

        return [
            $isaudio ? 'access/track/audio-bits' : 'access/track/video-bits',
            $common + ['title' => $title, ($isaudio ? 'audio_content' : 'video_content') => $curlfile],
            $temppath,
        ];
    }

    /**
     * Resolve the stored file behind a document/media payload.
     *
     * @param array $resource The material payload.
     * @return \stored_file|null The file, or null when it has been deleted.
     */
    private function stored_file(array $resource): ?\stored_file {
        $pathnamehash = (string) ($resource['pathnamehash'] ?? '');
        if ($pathnamehash === '') {
            return null;
        }

        $file = get_file_storage()->get_file_by_hash($pathnamehash);

        return ($file && !$file->is_directory()) ? $file : null;
    }

    /**
     * The configured brain id, or fail loudly.
     *
     * Listing brains deliberately does not go through here: an admin must be
     * able to discover the available brains before one has been chosen.
     *
     * @return string
     * @throws \moodle_exception When no brain is configured.
     */
    private function brain(): string {
        $brainid = trim($this->brainid);
        if ($brainid === '') {
            throw new \moodle_exception('nobrainselected', 'local_tarsus');
        }

        return $brainid;
    }

    /**
     * Split ids into wire-sized chunks, dropping empties and duplicates.
     *
     * @param string[] $resourceids Namespaced resource ids.
     * @return array<int, string[]> Chunks of at most {@see self::BATCH_LIMIT} ids.
     */
    private function chunk(array $resourceids): array {
        $ids = array_values(array_unique(array_filter(array_map('strval', $resourceids), 'strlen')));

        return $ids === [] ? [] : array_chunk($ids, self::BATCH_LIMIT);
    }

    /**
     * Perform one form-data call against the brain and decode its JSON body.
     *
     * The API takes form-data even for GET and DELETE, so the method is set
     * via CURLOPT_CUSTOMREQUEST while the fields travel in the body.
     *
     * @param string $method    HTTP method.
     * @param string $path      Path relative to the brain API prefix.
     * @param array  $params    Form fields (credentials are added here).
     * @param bool   $uploading Whether the call carries a file.
     * @return array The decoded response body.
     * @throws transport_exception On any transport, HTTP or protocol failure.
     * @throws \moodle_exception When the plugin is not configured.
     */
    private function send(string $method, string $path, array $params, bool $uploading = false): array {
        $baseurl = rtrim(trim($this->baseurl), '/');

        if ($baseurl === '' || trim($this->apikey) === '') {
            throw new \moodle_exception('notconfigured', 'local_tarsus');
        }

        $params['api_key'] = $this->apikey;

        // The brain expects form-data on every verb, including GET and DELETE.
        // curl::get() would move the fields into the query string and curl::delete()
        // drops them entirely, so post() carries the body and CURLOPT_CUSTOMREQUEST
        // relabels the verb, exactly as "curl --request GET --form" does.
        $curl = new \curl();
        $response = $curl->post($baseurl . self::API_PREFIX . $path, $params, [
            'CURLOPT_CUSTOMREQUEST' => $method,
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT' => $uploading ? self::UPLOAD_TIMEOUT : self::TIMEOUT,
        ]);

        if ($curl->get_errno()) {
            // Never echo the request: it carries the API key.
            throw new transport_exception(0, 'connection failed calling ' . $path);
        }

        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($httpcode < 200 || $httpcode >= 300) {
            throw new transport_exception($httpcode, 'unexpected HTTP status calling ' . $path);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new transport_exception(0, 'malformed JSON response from ' . $path);
        }

        return $decoded;
    }
}
