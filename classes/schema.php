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

namespace local_tarsus;

use database_manager;
use xmldb_table;

/**
 * Single source of truth for the local_tarsus table definitions.
 *
 * The prior plugin looped because a faulty upgrade never created its
 * back-off state tables — the safeguard existed in code but not at runtime.
 * To guarantee the tables exist after both clean install and upgrade, the
 * definitions live here and are applied by db/upgrade.php (and mirrored by
 * db/install.xml). Install/upgrade tests assert their presence either way.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema {

    /** @var string[] The plugin's table names. */
    public const TABLES = [
        'local_tarsus_index',
        'local_tarsus_guardrail',
        'local_tarsus_usage',
    ];

    /**
     * Create any of the plugin tables that are missing.
     *
     * Idempotent: safe to call on every upgrade and from tests.
     *
     * @param database_manager $dbman The DDL manager.
     * @return void
     */
    public static function ensure_tables(database_manager $dbman): void {
        global $CFG;
        require_once($CFG->libdir . '/ddllib.php');

        foreach (self::table_definitions() as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }
    }

    /**
     * Build the XMLDB table definitions.
     *
     * @return xmldb_table[] One definition per plugin table.
     */
    public static function table_definitions(): array {
        return [
            self::index_table(),
            self::guardrail_table(),
            self::usage_table(),
        ];
    }

    /**
     * Local index map: this Moodle resource's status relative to the brain.
     *
     * @return xmldb_table
     */
    private static function index_table(): xmldb_table {
        $table = new xmldb_table('local_tarsus_index');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('resourceid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('contenthash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('bitref', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'notindexed');
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('nextretry', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('resourceid', XMLDB_INDEX_UNIQUE, ['resourceid']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);

        return $table;
    }

    /**
     * Guardrail state: circuit breaker, token bucket and credit budget.
     *
     * Mutated under a lock by concurrent CRON workers (see guardrails pipeline).
     *
     * @return xmldb_table
     */
    private static function guardrail_table(): xmldb_table {
        $table = new xmldb_table('local_tarsus_guardrail');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('state', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'closed');
        $table->add_field('failurecount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('opensince', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tokens', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastrefill', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('creditsused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('periodstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('name', XMLDB_INDEX_UNIQUE, ['name']);

        return $table;
    }

    /**
     * Local per-context usage ledger (credit/operation attribution).
     *
     * @return xmldb_table
     */
    private static function usage_table(): xmldb_table {
        $table = new xmldb_table('local_tarsus_usage');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('operation', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('credits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('contextid', XMLDB_INDEX_NOTUNIQUE, ['contextid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);

        return $table;
    }
}
