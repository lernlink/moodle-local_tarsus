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

/**
 * Upgrade steps for local_tarsus.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run the local_tarsus upgrade steps.
 *
 * Defensively (re)creates every guardrail/state table on each upgrade — the
 * explicit regression guard for the prior runaway-loop incident, where an
 * upgrade left the back-off tables absent at runtime. Definitions live in
 * {@see \local_tarsus\schema} so install.xml and upgrade.php cannot drift.
 *
 * @param int $oldversion The currently installed plugin version.
 * @return bool Always true on success.
 */
function xmldb_local_tarsus_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    // Guarantee all tables exist regardless of upgrade history.
    \local_tarsus\schema::ensure_tables($dbman);

    if ($oldversion < 2026073102) {
        // File bits used to be keyed by their content hash, so replacing a
        // file orphaned the previous bit in the brain. Withdraw those ids;
        // the material will be re-indexed under its stable id on request.
        try {
            $retired = \local_tarsus\index\legacy_ids::retire();
            if ($retired > 0) {
                mtrace("local_tarsus: retired {$retired} content-addressed resource ids");
            }
        } catch (moodle_exception $e) {
            // An unreachable or unconfigured brain must not block the upgrade.
            // The rows keep their pendingdeletion status for a later sweep.
            mtrace('local_tarsus: could not withdraw legacy resource ids: ' . $e->getMessage());
        }

        upgrade_plugin_savepoint(true, 2026073102, 'local', 'tarsus');
    }

    return true;
}
