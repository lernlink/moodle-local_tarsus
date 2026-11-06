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
use local_tarsus\tracker;

/**
 * Tests for finishing deletions the brain never confirmed.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\index\deletion_sweeper
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deletion_sweeper_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        deletion_sweeper::reset();
    }

    /**
     * Record a resource as deleted in Moodle but not yet in the brain.
     *
     * @param repository $repo       The repository.
     * @param string     $resourceid The resource id.
     * @return void
     */
    private function strand(repository $repo, string $resourceid): void {
        $repo->ensure($resourceid, 100, 'mod_page', 7);
        $repo->set_status($resourceid, status::PENDING);
        $repo->mark_indexed($resourceid, 'bit-1', 'hash-1');
        $repo->set_status($resourceid, status::PENDING_DELETION);
    }

    /**
     * A stranded deletion is completed on the next sweep.
     *
     * @return void
     */
    public function test_sweep_finishes_stranded_deletions(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $this->strand($repo, 'site:1:mod_page:7:main');

        $cleared = deletion_sweeper::sweep(
            repo: $repo,
            tracker: new tracker(repo: $repo, client: new mock_client())
        );

        $this->assertSame(1, $cleared);
        $this->assertNull($repo->get('site:1:mod_page:7:main'));
    }

    /**
     * A brain still refusing leaves the work for next time.
     *
     * @return void
     */
    public function test_sweep_keeps_the_row_when_the_brain_refuses(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $this->strand($repo, 'site:1:mod_page:7:main');

        $cleared = deletion_sweeper::sweep(
            repo: $repo,
            tracker: new tracker(repo: $repo, client: new mock_client(simulatestatus: 500))
        );

        $this->assertSame(0, $cleared);
        $this->assertSame(status::PENDING_DELETION, $repo->get('site:1:mod_page:7:main')->status);
    }

    /**
     * Only the first sweep of a request does any work.
     *
     * @return void
     */
    public function test_sweep_runs_once_per_request(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $this->strand($repo, 'site:1:mod_page:7:main');
        $tracker = new tracker(repo: $repo, client: new mock_client());

        $this->assertSame(1, deletion_sweeper::sweep(repo: $repo, tracker: $tracker));

        $this->strand($repo, 'site:1:mod_page:8:main');
        $this->assertSame(0, deletion_sweeper::sweep(repo: $repo, tracker: $tracker));

        // The admin page asks explicitly, so it is not held back.
        $this->assertSame(1, deletion_sweeper::run(repo: $repo, tracker: $tracker));
    }

    /**
     * Nothing outstanding costs nothing.
     *
     * @return void
     */
    public function test_sweep_with_nothing_to_do(): void {
        $this->resetAfterTest(true);

        $repo = new repository();
        $client = new class extends mock_client {
            /** @var bool Whether the brain was contacted at all. */
            public bool $called = false;

            #[\Override]
            public function untrack_resource(array $resourceids): array {
                $this->called = true;
                return parent::untrack_resource($resourceids);
            }
        };

        $cleared = deletion_sweeper::sweep(
            repo: $repo,
            tracker: new tracker(repo: $repo, client: $client)
        );

        $this->assertSame(0, $cleared);
        $this->assertFalse($client->called);
        $this->assertSame([], deletion_sweeper::outstanding($repo));
    }
}
