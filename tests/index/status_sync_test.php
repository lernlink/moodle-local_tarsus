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

namespace local_tarsus\index;

use local_tarsus\client\mock_client;

/**
 * Tests for the batch status reconciliation against the brain.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\index\status_sync
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status_sync_test extends \advanced_testcase {
    /** @var string A sample namespaced resource id. */
    private const RID = 'site:1:mod_resource:42';

    /**
     * Build a client whose batch check answers from a fixed script.
     *
     * @param array $report Response per resource id (resource id => response array).
     * @return mock_client
     */
    private function make_client(array $report): mock_client {
        return new class ($report) extends mock_client {
            /**
             * Constructor.
             *
             * @param array $report Response per resource id (resource id => response array).
             */
            public function __construct(
                /** @var array<string, array<string, mixed>> Response per resource id. */
                private array $report
            ) {
                parent::__construct();
            }

            #[\Override]
            public function check_tracked_resources(array $resourceids): array {
                return array_intersect_key($this->report, array_flip($resourceids));
            }
        };
    }

    /**
     * Create the index row for the sample resource.
     *
     * @param repository $repo The repository.
     * @return void
     */
    private function ensure_row(repository $repo): void {
        $repo->ensure(self::RID, 100, 'mod_resource', 42);
    }

    /**
     * A queued re-index must never be cancelled by the brain's answer.
     *
     * The brain reports "done" for the *previous* content, so adopting it
     * would silently drop the queued upload and leave a stale bit live.
     *
     * @return void
     */
    public function test_pending_reindex_is_not_overridden_by_brain(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $this->ensure_row($repo);
        $repo->set_status(self::RID, status::PENDING);
        $repo->mark_uploaded(self::RID, 'hash-of-old-content');
        $repo->mark_indexed(self::RID, 'bit-1', 'hash-of-old-content');
        $repo->set_status(self::RID, status::PENDING_REINDEX);

        $client = $this->make_client([
            self::RID => ['status' => status::INDEXED, 'tracked' => true, 'brainstatus' => 'done'],
        ]);
        $changed = (new status_sync(repo: $repo, client: $client))->refresh([self::RID]);

        $this->assertSame([], $changed);
        $this->assertSame(status::PENDING_REINDEX, $repo->get(self::RID)->status);
    }

    /**
     * A queued first upload is equally off-limits for the brain's answer.
     *
     * @return void
     */
    public function test_pending_upload_is_not_overridden_by_brain(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $this->ensure_row($repo);
        $repo->set_status(self::RID, status::PENDING);

        $client = $this->make_client([
            self::RID => ['status' => status::INDEXED, 'tracked' => true, 'brainstatus' => 'done'],
        ]);
        $changed = (new status_sync(repo: $repo, client: $client))->refresh([self::RID]);

        $this->assertSame([], $changed);
        $this->assertSame(status::PENDING, $repo->get(self::RID)->status);
    }

    /**
     * Confirmation keeps the hash of the uploaded content, not a fresh one.
     *
     * @return void
     */
    public function test_confirmation_adopts_uploaded_hash(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $this->ensure_row($repo);
        $repo->set_status(self::RID, status::PENDING);
        $repo->mark_uploaded(self::RID, 'hash-of-uploaded-content');

        $client = $this->make_client([
            self::RID => ['status' => status::INDEXED, 'tracked' => true, 'brainstatus' => 'done'],
        ]);
        $changed = (new status_sync(repo: $repo, client: $client))->refresh([self::RID]);

        $this->assertSame([self::RID => status::INDEXED], $changed);
        $row = $repo->get(self::RID);
        $this->assertSame(status::INDEXED, $row->status);
        $this->assertSame('hash-of-uploaded-content', $row->contenthash);
    }

    /**
     * A row that errored locally self-heals when the brain reports "done".
     *
     * @return void
     */
    public function test_error_row_heals_when_brain_confirms(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $this->ensure_row($repo);
        $repo->set_status(self::RID, status::PENDING);
        $repo->mark_uploaded(self::RID, 'hash-of-uploaded-content');
        $repo->set_status(self::RID, status::ERROR);

        $client = $this->make_client([
            self::RID => ['status' => status::INDEXED, 'tracked' => true, 'brainstatus' => 'done'],
        ]);
        $changed = (new status_sync(repo: $repo, client: $client))->refresh([self::RID]);

        $this->assertSame([self::RID => status::INDEXED], $changed);
        $row = $repo->get(self::RID);
        $this->assertSame(status::INDEXED, $row->status);
        $this->assertSame('hash-of-uploaded-content', $row->contenthash);
    }

    /**
     * A "failed" report (absent from the brain) drops a waiting row back to start.
     *
     * @return void
     */
    public function test_failed_report_returns_row_to_notindexed(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $this->ensure_row($repo);
        $repo->set_status(self::RID, status::PENDING);
        $repo->mark_uploaded(self::RID, 'hash-of-uploaded-content');

        $client = $this->make_client([
            self::RID => ['status' => status::NOT_INDEXED, 'tracked' => false, 'brainstatus' => 'failed'],
        ]);
        $changed = (new status_sync(repo: $repo, client: $client))->refresh([self::RID]);

        $this->assertSame([self::RID => status::NOT_INDEXED], $changed);
        $this->assertSame(status::NOT_INDEXED, $repo->get(self::RID)->status);
    }
}
