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

namespace local_tarsus;

/**
 * Local per-context usage ledger.
 *
 * The shared brain's API usage cannot be broken down by course, so this local
 * ledger provides credit/operation attribution per context and user.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_meter {
    /**
     * Record one operation against the ledger.
     *
     * @param int    $contextid The context the operation belongs to.
     * @param int    $userid    The Moodle user id (0 for system).
     * @param string $operation Short operation key (e.g. 'search', 'track').
     * @param int    $credits   aiCredits consumed (0 for indexing).
     * @return int The new ledger row id.
     */
    public function record(int $contextid, int $userid, string $operation, int $credits = 0): int {
        global $DB;

        return $DB->insert_record('local_tarsus_usage', (object) [
            'contextid' => $contextid,
            'userid' => $userid,
            'operation' => $operation,
            'credits' => $credits,
            'timecreated' => time(),
        ]);
    }

    /**
     * Total credits spent within a context.
     *
     * @param int $contextid The context id.
     * @return int Sum of credits.
     */
    public function total_for_context(int $contextid): int {
        global $DB;

        $sql = 'SELECT COALESCE(SUM(credits), 0)
                  FROM {local_tarsus_usage}
                 WHERE contextid = :contextid';

        return (int) $DB->get_field_sql($sql, ['contextid' => $contextid]);
    }

    /**
     * Total credits spent site-wide.
     *
     * @return int Sum of credits.
     */
    public function total(): int {
        global $DB;

        $sql = 'SELECT COALESCE(SUM(credits), 0) FROM {local_tarsus_usage}';

        return (int) $DB->get_field_sql($sql);
    }
}
