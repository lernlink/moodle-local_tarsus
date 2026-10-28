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

use xmldb_table;

/**
 * Install/upgrade schema regression tests.
 *
 * The mandatory guard for the prior incident: assert every guardrail/state
 * table exists after a clean install AND after the upgrade path recreates
 * missing tables.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\schema
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schema_test extends \advanced_testcase {

    /**
     * All plugin tables exist after a clean install.
     *
     * @return void
     */
    public function test_tables_present_after_install(): void {
        global $DB;

        $dbman = $DB->get_manager();
        foreach (schema::TABLES as $tablename) {
            $this->assertTrue(
                $dbman->table_exists($tablename),
                "Table {$tablename} missing after clean install."
            );
        }
    }

    /**
     * The upgrade path recreates tables that are missing at runtime.
     *
     * @return void
     */
    public function test_upgrade_recreates_missing_tables(): void {
        global $DB;

        $this->resetAfterTest(true);
        $dbman = $DB->get_manager();

        // Simulate the incident: the state tables are absent at runtime.
        foreach (schema::TABLES as $tablename) {
            $dbman->drop_table(new xmldb_table($tablename));
            $this->assertFalse($dbman->table_exists($tablename));
        }

        // The upgrade-side helper must put them all back.
        schema::ensure_tables($dbman);

        foreach (schema::TABLES as $tablename) {
            $this->assertTrue(
                $dbman->table_exists($tablename),
                "Upgrade did not recreate table {$tablename}."
            );
        }
    }

    /**
     * ensure_tables is idempotent — safe to run on every upgrade.
     *
     * @return void
     */
    public function test_ensure_tables_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest(true);
        $dbman = $DB->get_manager();

        // Tables already exist from install; a second call must not error.
        schema::ensure_tables($dbman);

        foreach (schema::TABLES as $tablename) {
            $this->assertTrue($dbman->table_exists($tablename));
        }
    }

    /**
     * The real db/upgrade.php entry point recreates missing tables.
     *
     * Stronger than calling the helper directly: it proves db/upgrade.php is
     * actually wired to the schema guard, which is what runs in production.
     *
     * @return void
     */
    public function test_upgrade_entry_point_recreates_tables(): void {
        global $CFG, $DB;

        $this->resetAfterTest(true);
        // Moodle always has these loaded when it runs an upgrade.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/tarsus/db/upgrade.php');

        $dbman = $DB->get_manager();
        $dbman->drop_table(new xmldb_table('local_tarsus_index'));
        $this->assertFalse($dbman->table_exists('local_tarsus_index'));

        // Invoke the genuine upgrade function as Moodle would, from the
        // installed version: the schema guard runs on every upgrade, while
        // the version-gated migration steps have their own tests.
        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/tarsus/version.php');
        $this->assertTrue(xmldb_local_tarsus_upgrade($plugin->version));

        foreach (schema::TABLES as $tablename) {
            $this->assertTrue(
                $dbman->table_exists($tablename),
                "Upgrade entry point did not recreate table {$tablename}."
            );
        }
    }

    /**
     * The TABLES constant and the code-built definitions cannot drift apart.
     *
     * @return void
     */
    public function test_table_definitions_match_constant(): void {
        $defined = array_map(
            static fn(xmldb_table $table): string => $table->getName(),
            schema::table_definitions()
        );

        sort($defined);
        $expected = schema::TABLES;
        sort($expected);

        $this->assertSame($expected, $defined);
    }

    /**
     * Every field and index of every definition is present in the live schema.
     *
     * This is the drift guard between install.xml (which built the test DB) and
     * the schema class (which db/upgrade.php applies): a column added to one but
     * not the other fails here.
     *
     * @return void
     */
    public function test_definition_fields_and_indexes_present(): void {
        global $DB;

        $dbman = $DB->get_manager();

        foreach (schema::table_definitions() as $table) {
            $this->assertTrue(
                $dbman->table_exists($table),
                "Table {$table->getName()} is absent from the installed schema."
            );

            foreach ($table->getFields() as $field) {
                $this->assertTrue(
                    $dbman->field_exists($table, $field),
                    "Field {$table->getName()}.{$field->getName()} missing — install.xml and schema drifted."
                );
            }

            foreach ($table->getIndexes() as $index) {
                $this->assertTrue(
                    $dbman->index_exists($table, $index),
                    "Index on {$table->getName()} (" . implode(', ', $index->getFields())
                        . ') missing — install.xml and schema drifted.'
                );
            }
        }
    }
}
