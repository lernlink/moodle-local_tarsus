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
 * Tests for the index repository.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\index\repository
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class repository_test extends \advanced_testcase {

    /** @var string A sample namespaced resource id. */
    private const RID = 'site:1:mod_resource:42';

    /**
     * ensure() is idempotent and starts notindexed.
     *
     * @return void
     */
    public function test_ensure_is_idempotent(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $first = $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $second = $repo->ensure(self::RID, 100, 'mod_resource', 42);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(status::NOT_INDEXED, $first->status);
    }

    /**
     * A legal transition is persisted; an illegal one throws.
     *
     * @return void
     */
    public function test_set_status_enforces_transitions(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $repo->ensure(self::RID, 100, 'mod_resource', 42);

        $repo->set_status(self::RID, status::PENDING);
        $this->assertSame(status::PENDING, $repo->get(self::RID)->status);

        // pending -> notindexed is not a legal transition.
        $this->expectException(\coding_exception::class);
        $repo->set_status(self::RID, status::NOT_INDEXED);
    }

    /**
     * mark_indexed stores the bit ref and hash and clears retries.
     *
     * @return void
     */
    public function test_mark_indexed(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);
        $repo->mark_indexed(self::RID, 'bit-xyz', 'hash-123');

        $row = $repo->get(self::RID);
        $this->assertSame(status::INDEXED, $row->status);
        $this->assertSame('bit-xyz', $row->bitref);
        $this->assertSame('hash-123', $row->contenthash);
        $this->assertSame(0, (int) $row->attempts);
    }

    /**
     * Failures schedule retries until the budget is spent, then error.
     *
     * @return void
     */
    public function test_record_failure_then_error(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);

        for ($i = 1; $i < backoff::RETRY_LIMIT; $i++) {
            $row = $repo->record_failure(self::RID);
            $this->assertSame(status::PENDING, $row->status);
            $this->assertGreaterThan(0, (int) $row->nextretry);
        }

        $row = $repo->record_failure(self::RID);
        $this->assertSame(status::ERROR, $row->status);
        $this->assertSame(backoff::RETRY_LIMIT, (int) $row->attempts);
    }

    /**
     * Content change detection compares the stored hash.
     *
     * @return void
     */
    public function test_content_changed(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $this->assertTrue($repo->content_changed(self::RID, 'h1'), 'Missing row counts as changed.');

        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);
        $repo->mark_indexed(self::RID, 'bit', 'h1');

        $this->assertFalse($repo->content_changed(self::RID, 'h1'));
        $this->assertTrue($repo->content_changed(self::RID, 'h2'));
    }

    /**
     * get_due returns only due rows in the requested status.
     *
     * @return void
     */
    public function test_get_due_filters_by_status_and_time(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);

        $due = $repo->get_due(status::PENDING);
        $this->assertCount(1, $due);

        $this->assertCount(0, $repo->get_due(status::INDEXED));
    }
}
