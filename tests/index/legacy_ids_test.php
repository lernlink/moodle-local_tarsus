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

use local_tarsus\client\mock_client;
use local_tarsus\material\resource_id;
use local_tarsus\tracker;

/**
 * Tests for retiring content-addressed resource ids.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\index\legacy_ids
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class legacy_ids_test extends \advanced_testcase {

    /** @var string A resource id from the content-addressed scheme. */
    private const LEGACY_RID = 'site1234:6:mod_resource:29:a8df9fed4573673066fa47ec5bcf7525d914ac6b';

    /**
     * Build a tracker whose client records what it was asked to untrack.
     *
     * @param repository $repo The repository to share.
     * @return array{0: tracker, 1: object} The tracker and its recording client.
     */
    private function make_tracker(repository $repo): array {
        $client = new class extends mock_client {
            /** @var string[] Every resource id passed to untrack. */
            public array $untracked = [];

            #[\Override]
            public function untrack_resource(array $resourceids): array {
                $this->untracked = array_merge($this->untracked, $resourceids);
                return parent::untrack_resource($resourceids);
            }
        };

        return [new tracker(repo: $repo, client: $client), $client];
    }

    /**
     * An id ending in a content hash is recognised; a stable one is not.
     *
     * @return void
     */
    public function test_detection(): void {
        $this->assertTrue(legacy_ids::is_legacy(self::LEGACY_RID));
        $this->assertFalse(legacy_ids::is_legacy('site1234:6:mod_resource:29:main'));
        $this->assertFalse(legacy_ids::is_legacy('site1234:6:mod_resource:29:handout.pdf'));
        $this->assertFalse(legacy_ids::is_legacy('not a resource id'));
    }

    /**
     * A legacy row that reached the brain is untracked and dropped.
     *
     * @return void
     */
    public function test_indexed_legacy_row_is_untracked(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $repo->ensure(self::LEGACY_RID, 100, 'mod_resource', 29);
        $repo->set_status(self::LEGACY_RID, status::PENDING);
        $repo->mark_indexed(self::LEGACY_RID, 'bit-1', 'hash-1');
        (new reference_store())->add(self::LEGACY_RID, 'mod_resource', 29, 100);

        [$tracker, $client] = $this->make_tracker($repo);
        $retired = legacy_ids::retire(repo: $repo, tracker: $tracker);

        $this->assertSame(1, $retired);
        $this->assertSame([self::LEGACY_RID], $client->untracked);
        $this->assertNull($repo->get(self::LEGACY_RID));
        $this->assertFalse((new reference_store())->has_references(self::LEGACY_RID));
    }

    /**
     * A legacy row the brain never received costs no API call.
     *
     * @return void
     */
    public function test_untracked_legacy_row_is_dropped_locally(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $repo->ensure(self::LEGACY_RID, 100, 'mod_resource', 29);

        [$tracker, $client] = $this->make_tracker($repo);
        $retired = legacy_ids::retire(repo: $repo, tracker: $tracker);

        $this->assertSame(1, $retired);
        $this->assertSame([], $client->untracked);
        $this->assertNull($repo->get(self::LEGACY_RID));
    }

    /**
     * Rows already using a stable id are left alone.
     *
     * @return void
     */
    public function test_stable_rows_are_untouched(): void {
        $this->resetAfterTest(true);

        $stable = resource_id::build(6, 'mod_page', 15);

        $repo = new repository();
        $repo->ensure($stable, 100, 'mod_page', 15);
        $repo->set_status($stable, status::PENDING);
        $repo->mark_indexed($stable, 'bit-2', 'hash-2');

        [$tracker, $client] = $this->make_tracker($repo);
        $retired = legacy_ids::retire(repo: $repo, tracker: $tracker);

        $this->assertSame(0, $retired);
        $this->assertSame([], $client->untracked);
        $this->assertSame(status::INDEXED, $repo->get($stable)->status);
    }
}
