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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_tarsus\material;

/**
 * One extracted unit of material — a single bit destined for the brain.
 *
 * Immutable: extractors produce it from current Moodle state, and both the
 * content hash (change detection, §5) and the track payload are derived from
 * the same fields, so they can never drift apart.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class material {
    /**
     * Constructor.
     *
     * @param string      $resourceid Namespaced resource id (see {@see resource_id}).
     * @param string      $type       Bit type (see {@see bit_type}).
     * @param int         $contextid  Owning (course) context id.
     * @param string      $component  Frankenstyle component of the source (e.g. mod_page).
     * @param int         $cmid       Owning course module id.
     * @param string      $title      Human-readable title.
     * @param string      $body       Inline text/url payload ('' for file bits).
     * @param string|null $filehash   Source file content hash ('' / null for inline bits).
     * @param string|null $pathnamehash Stored-file path hash, so the client can re-open the file to upload it.
     */
    public function __construct(
        /** @var string The brain resource id */
        public readonly string $resourceid,
        /** @var string The material type (the bit type it is tracked as) */
        public readonly string $type,
        /** @var int The id of the context the material belongs to */
        public readonly int $contextid,
        /** @var string The frankenstyle component owning the material */
        public readonly string $component,
        /** @var int The course module id */
        public readonly int $cmid,
        /** @var string The title shown to the brain */
        public readonly string $title,
        /** @var string The text body, empty for binary files */
        public readonly string $body = '',
        /** @var ?string The content hash of the file, null for text */
        public readonly ?string $filehash = null,
        /** @var ?string The file's pathname hash, null for text */
        public readonly ?string $pathnamehash = null
    ) {
    }

    /**
     * A stable hash of this material's content, used to detect change.
     *
     * Re-index keys on this value: an unchanged hash means no API call.
     *
     * @return string A 64-char sha256 hex digest.
     */
    public function content_hash(): string {
        $canonical = implode("\x1f", [
            $this->type,
            trim($this->title),
            trim($this->body),
            (string) $this->filehash,
        ]);
        return hash('sha256', $canonical);
    }

    /**
     * The payload sent to the brain's track endpoint for this bit.
     *
     * @return array<string, mixed>
     */
    public function to_payload(): array {
        $payload = [
            'resource_id' => $this->resourceid,
            'context_id' => (string) $this->contextid,
            'type' => $this->type,
            'title' => $this->title,
        ];

        switch ($this->type) {
            case bit_type::TEXT:
                $payload['text'] = $this->body;
                break;
            case bit_type::URL:
                $payload['url'] = $this->body;
                break;
            case bit_type::DOCUMENT:
            case bit_type::MEDIA:
                $payload['contenthash'] = (string) $this->filehash;
                $payload['pathnamehash'] = (string) $this->pathnamehash;
                $payload['filename'] = $this->title;
                break;
        }

        return $payload;
    }
}
