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

use local_tarsus\client\mock_client;
use local_tarsus\index\reference_store;
use local_tarsus\index\repository;
use local_tarsus\index\status;

/**
 * Tests for the indexation tracker orchestration service.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\tracker
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tracker_test extends \advanced_testcase {

    /** @var string A sample namespaced resource id. */
    private const RID = 'site:1:mod_resource:42';

    /**
     * Build a tracker bound to a given mock client.
     *
     * @param mock_client $client The client to inject.
     * @return array{0: tracker, 1: repository} The tracker and its repository.
     */
    private function make(mock_client $client): array {
        $repo = new repository();
        $tracker = new tracker(repo: $repo, client: $client);
        return [$tracker, $repo];
    }

    /**
     * Async track leaves the row awaiting confirmation.
     *
     * @return void
     */
    public function test_upload_async_goes_to_confirmation(): void {
        $this->resetAfterTest(true);

        [$tracker, $repo] = $this->make(new mock_client(async: true));
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);

        $tracker->process($repo->get(self::RID));

        $this->assertSame(status::PENDING_CONFIRMATION, $repo->get(self::RID)->status);
    }

    /**
     * Sync track marks the row indexed with a bitref and hash.
     *
     * @return void
     */
    public function test_upload_sync_marks_indexed(): void {
        $this->resetAfterTest(true);

        [$tracker, $repo] = $this->make(new mock_client(async: false));
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);

        $tracker->process($repo->get(self::RID));

        $row = $repo->get(self::RID);
        $this->assertSame(status::INDEXED, $row->status);
        $this->assertNotEmpty($row->bitref);
        $this->assertNotEmpty($row->contenthash);
    }

    /**
     * A transport failure records an attempt and schedules a retry.
     *
     * @return void
     */
    public function test_upload_transport_failure_records_attempt(): void {
        $this->resetAfterTest(true);

        [$tracker, $repo] = $this->make(new mock_client(simulatestatus: 500));
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);

        $tracker->process($repo->get(self::RID));

        $row = $repo->get(self::RID);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertGreaterThan(time(), (int) $row->nextretry);
        $this->assertSame(status::PENDING, $row->status);
    }

    /**
     * Confirmation polling promotes a confirmed resource to indexed.
     *
     * @return void
     */
    public function test_confirmation_promotes_to_indexed(): void {
        $this->resetAfterTest(true);

        [$tracker, $repo] = $this->make(new mock_client(async: true));
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);
        $repo->set_status(self::RID, status::PENDING_CONFIRMATION);

        $tracker->process($repo->get(self::RID));

        $this->assertSame(status::INDEXED, $repo->get(self::RID)->status);
    }

    /**
     * Deletion of an orphaned resource removes the row.
     *
     * @return void
     */
    public function test_deletion_removes_orphan(): void {
        $this->resetAfterTest(true);

        [$tracker, $repo] = $this->make(new mock_client());
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING_DELETION);

        $tracker->process($repo->get(self::RID));

        $this->assertNull($repo->get(self::RID));
    }

    /**
     * Deletion is refused while another owner still references the resource.
     *
     * @return void
     */
    public function test_deletion_reverts_when_still_referenced(): void {
        $this->resetAfterTest(true);

        [$tracker, $repo] = $this->make(new mock_client());
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING_DELETION);

        (new reference_store())->add(self::RID, 'mod_page', 7, 100);

        $tracker->process($repo->get(self::RID));

        $this->assertSame(status::INDEXED, $repo->get(self::RID)->status);
    }

    /**
     * The kill switch blocks the call and leaves the row untouched.
     *
     * @return void
     */
    public function test_killswitch_holds_the_queue(): void {
        $this->resetAfterTest(true);

        set_config('killswitch', 1, 'local_tarsus');

        [$tracker, $repo] = $this->make(new mock_client(async: false));
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
        $repo->set_status(self::RID, status::PENDING);

        $tracker->process($repo->get(self::RID));

        $this->assertSame(status::PENDING, $repo->get(self::RID)->status);
    }
}
