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

namespace local_tarsus\output;

use local_tarsus\client\client_factory;
use renderable;
use renderer_base;
use templatable;

/**
 * The admin brain picker: every brain reachable by the API key, with its state.
 *
 * A brain is chosen, not created, here — activating one only points this site
 * at it. Inactive brains are listed but cannot be selected, so a site can never
 * be wired to a brain the organisation has retired.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class brain_list implements renderable, templatable {
    /** @var string A deleted brain keeps its row, renamed to deleted-<uuid>-<original id>. */
    private const DELETED_PATTERN = '/^deleted-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}-(.+)$/i';

    /**
     * Constructor.
     *
     * @param array<int, array<string, mixed>> $brains  Brains as returned by the client.
     * @param string                           $current The brain id this site currently uses.
     */
    public function __construct(
        /** @var array The brains the site may choose from, as the brain returns them */
        private array $brains,
        /** @var string The id of the brain currently configured */
        private string $current
    ) {
    }

    /**
     * Fetch the brains for the configured API key.
     *
     * @return self
     * @throws \moodle_exception When the plugin is not configured.
     * @throws \local_tarsus\client\transport_exception When the call fails.
     */
    public static function fetch(): self {
        return new self(
            client_factory::get_client()->list_brains(),
            (string) get_config('local_tarsus', 'brain_id')
        );
    }

    /**
     * Whether this site may be pointed at the given brain.
     *
     * @param string $brainid The brain id to check.
     * @return bool
     */
    public function can_activate(string $brainid): bool {
        foreach ($this->rows() as $row) {
            if ($row['brainid'] === $brainid) {
                return $row['canactivate'];
            }
        }

        return false;
    }

    /**
     * Export the brain table for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array<string, mixed>
     */
    public function export_for_template(renderer_base $output): array {
        $rows = $this->rows();
        $known = in_array($this->current, array_column($rows, 'brainid'), true);

        return [
            'brains' => $rows,
            'hasbrains' => $rows !== [],
            'hascurrent' => $this->current !== '',
            'currentmissing' => $this->current !== '' && !$known,
            'current' => $this->current,
        ];
    }

    /**
     * Build one display row per brain.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array {
        $rows = [];

        foreach ($this->brains as $brain) {
            $brainid = (string) ($brain['brainid'] ?? '');
            $active = !empty($brain['active']);
            $selected = ($brainid !== '' && $brainid === $this->current);
            $deleted = (bool) preg_match(self::DELETED_PATTERN, $brainid, $matches);

            if ($deleted) {
                $state = 'deleted';
                $badge = 'badge-danger';
            } else if ($active) {
                $state = 'active';
                $badge = 'badge-success';
            } else {
                $state = 'inactive';
                $badge = 'badge-secondary';
            }

            $rows[] = [
                'brainid' => $brainid,
                'displayname' => $deleted ? $matches[1] : $brainid,
                'renamed' => $deleted,
                'language' => (string) ($brain['language'] ?? ''),
                'tag' => (string) ($brain['tag'] ?? ''),
                'created' => empty($brain['timecreated']) ? '' : userdate((int) $brain['timecreated']),
                'statuslabel' => get_string('brain_' . $state, 'local_tarsus'),
                'badgeclass' => $badge,
                'selected' => $selected,
                'canactivate' => $active && !$deleted && !$selected,
                'activateurl' => (new \moodle_url('/local/tarsus/brains.php', [
                    'action' => 'activate',
                    'brainid' => $brainid,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }

        return $rows;
    }
}
