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

/**
 * Deterministic mock Tarsus client.
 *
 * Backs the entire build until the live API is granted. Returns stable
 * fixtures and can simulate transport failures and both synchronous and
 * asynchronous track results, so guardrail tests and the indexation state
 * machine are exercised without any network I/O.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mock_client implements client_interface {

    /**
     * Constructor.
     *
     * @param bool $async          When true, track returns 'pending-confirmation'
     *                             (async indexing); when false it returns 'indexed'
     *                             immediately (synchronous track).
     * @param int  $simulatestatus When non-zero, every call throws a
     *                             {@see transport_exception} with this HTTP status
     *                             (e.g. 429 / 500) for guardrail tests.
     */
    public function __construct(
        protected bool $async = true,
        protected int $simulatestatus = 0
    ) {
    }

    /**
     * Raise the simulated transport failure when configured.
     *
     * @return void
     * @throws transport_exception
     */
    protected function maybe_fail(): void {
        if ($this->simulatestatus !== 0) {
            throw new transport_exception($this->simulatestatus, 'mock simulated failure');
        }
    }

    #[\Override]
    public function search_grounded(string $query, string $userid, array $allowedresourceids): array {
        $this->maybe_fail();

        // Echo the allowed scope back as sources, so tests can assert that
        // exactly the included resources were offered to the brain.
        $sources = [];
        foreach ($allowedresourceids as $i => $resourceid) {
            $sources[] = [
                'title' => 'Mock source ' . ($i + 1),
                'resourceid' => (string) $resourceid,
            ];
        }

        return [
            'answer' => 'Mock grounded answer for: ' . $query,
            'answered' => true,
            'sources' => $sources,
            'credits' => 6,
        ];
    }

    #[\Override]
    public function track_resource(array $resource): array {
        $this->maybe_fail();

        $resourceid = $resource['resource_id'] ?? 'mock-resource';

        return [
            'status' => $this->async ? 'pending-confirmation' : 'indexed',
            'bitref' => 'mock-bit-' . sha1($resourceid),
            'credits' => 0,
        ];
    }

    #[\Override]
    public function untrack_resource(array $resourceids): array {
        $this->maybe_fail();

        $result = [];
        foreach ($resourceids as $resourceid) {
            $result[$resourceid] = 'removed';
        }

        return $result;
    }

    #[\Override]
    public function untrack_assets(array $assetids): array {
        $this->maybe_fail();

        $result = [];
        foreach ($assetids as $assetid) {
            $result[$assetid] = 'removed';
        }

        return $result;
    }

    #[\Override]
    public function get_resource_status(string $resourceid): array {
        $this->maybe_fail();

        return [
            'status' => 'indexed',
            'bitref' => 'mock-bit-' . sha1($resourceid),
        ];
    }

    #[\Override]
    public function check_tracked_resources(array $resourceids): array {
        $this->maybe_fail();

        $result = [];
        foreach (array_unique($resourceids) as $resourceid) {
            $result[$resourceid] = [
                'status' => 'indexed',
                'tracked' => true,
                'brainstatus' => 'done',
            ];
        }

        return $result;
    }

    #[\Override]
    public function list_brains(): array {
        $this->maybe_fail();

        return [
            [
                'brainid' => 'mock-brain-default',
                'active' => true,
                'language' => 'de',
                'tag' => 'default',
                'timecreated' => 1726722755,
            ],
            [
                'brainid' => 'mock-brain-retired',
                'active' => false,
                'language' => 'en',
                'tag' => 'default',
                'timecreated' => 1700000000,
            ],
            [
                'brainid' => 'deleted-17cff6a1-4850-4d71-987a-97ceea0e0cba-mock-brain-old',
                'active' => false,
                'language' => 'de',
                'tag' => 'default',
                'timecreated' => 1690000000,
            ],
        ];
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
}
