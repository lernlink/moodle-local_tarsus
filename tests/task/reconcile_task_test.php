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

namespace local_tarsus\task;

use local_tarsus\index\reference_store;
use local_tarsus\index\repository;
use local_tarsus\index\status;

/**
 * Tests for the reconcile janitor task.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\task\reconcile_task
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reconcile_task_test extends \advanced_testcase {

    /** @var string A sample namespaced resource id. */
    private const RID = 'site:1:mod_resource:42';

    /**
     * An indexed row with no references is queued for deletion.
     *
     * @return void
     */
    public function test_orphan_is_queued_for_deletion(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);
        $repo->mark_indexed(self::RID, 'mock-bit', sha1(self::RID));

        (new reconcile_task())->execute();

        $this->assertSame(status::PENDING_DELETION, $repo->get(self::RID)->status);
    }

    /**
     * A referenced indexed row is left alone.
     *
     * @return void
     */
    public function test_referenced_row_is_left_indexed(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);
        $repo->mark_indexed(self::RID, 'mock-bit', sha1(self::RID));

        (new reference_store())->add(self::RID, 'mod_page', 7, 100);

        (new reconcile_task())->execute();

        $this->assertSame(status::INDEXED, $repo->get(self::RID)->status);
    }
}
