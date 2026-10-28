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

namespace local_tarsus\index;

/**
 * Tests for the resource reference store.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\index\reference_store
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_store_test extends \advanced_testcase {

    /** @var string A sample namespaced resource id. */
    private const RID = 'site:1:course:7:file:99';

    /**
     * Two owners referencing one resource count as two; add is idempotent.
     *
     * @return void
     */
    public function test_count_and_idempotent_add(): void {
        $this->resetAfterTest(true);

        $store = new reference_store();
        $store->add(self::RID, 'mod_tarsus', 11, 100);
        $store->add(self::RID, 'mod_tarsus', 22, 100);
        $store->add(self::RID, 'mod_tarsus', 22, 100); // Duplicate, ignored.

        $this->assertSame(2, $store->count(self::RID));
        $this->assertTrue($store->has_references(self::RID));
        $this->assertFalse($store->safe_to_untrack(self::RID));
    }

    /**
     * Untrack becomes safe only once the last reference is removed.
     *
     * @return void
     */
    public function test_safe_to_untrack_after_last_reference(): void {
        $this->resetAfterTest(true);

        $store = new reference_store();
        $store->add(self::RID, 'mod_tarsus', 11, 100);
        $store->add(self::RID, 'mod_tarsus', 22, 100);

        $store->remove(self::RID, 'mod_tarsus', 11);
        $this->assertFalse($store->safe_to_untrack(self::RID), 'Still referenced by owner 22.');

        $store->remove(self::RID, 'mod_tarsus', 22);
        $this->assertTrue($store->safe_to_untrack(self::RID));
    }

    /**
     * Removing an owner returns the resources it had referenced.
     *
     * @return void
     */
    public function test_remove_all_for_owner(): void {
        $this->resetAfterTest(true);

        $store = new reference_store();
        $store->add('rid-a', 'mod_tarsus', 11, 100);
        $store->add('rid-b', 'mod_tarsus', 11, 100);
        $store->add('rid-a', 'mod_tarsus', 22, 100);

        $freed = $store->remove_all_for_owner('mod_tarsus', 11);
        sort($freed);

        $this->assertSame(['rid-a', 'rid-b'], $freed);
        // rid-a is still referenced by owner 22; rid-b is now orphaned.
        $this->assertSame(1, $store->count('rid-a'));
        $this->assertSame(0, $store->count('rid-b'));
    }
}
