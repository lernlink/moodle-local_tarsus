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
use local_tarsus\material\resolver;

/**
 * Tests for the indexer entry point (resolve → hash → decide).
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\indexer
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class indexer_test extends \advanced_testcase {

    /**
     * Create a page and return its cm_info.
     *
     * @param int    $courseid The course id.
     * @param string $content  The page body.
     * @return \cm_info
     */
    private function make_page(int $courseid, string $content): \cm_info {
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $courseid,
            'content' => $content,
            'contentformat' => FORMAT_HTML,
        ]);
        return get_fast_modinfo($courseid)->get_cm($page->cmid);
    }

    /**
     * A new material is queued as pending and gains an owner reference.
     *
     * @return void
     */
    public function test_new_material_is_queued(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Alpha</p>');

        $queued = (new indexer())->index_cm($cm);

        $this->assertCount(1, $queued);
        $resourceid = $queued[0];
        $this->assertSame(status::PENDING, (new repository())->get($resourceid)->status);
        $this->assertTrue((new reference_store())->has_references($resourceid));
    }

    /**
     * Re-indexing unchanged content makes no API work.
     *
     * @return void
     */
    public function test_unchanged_content_is_skipped(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Beta</p>');

        $indexer = new indexer();
        $resourceid = $indexer->index_cm($cm)[0];

        // Simulate a successful index at the current hash.
        $material = (new resolver())->resolve($cm)[0];
        (new repository())->mark_indexed($resourceid, 'bit-1', $material->content_hash());

        $this->assertSame([], $indexer->index_cm($cm));
        $this->assertSame(status::INDEXED, (new repository())->get($resourceid)->status);
    }

    /**
     * Changed content is queued for re-indexing.
     *
     * @return void
     */
    public function test_changed_content_triggers_reindex(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Gamma</p>');

        $indexer = new indexer();
        $resourceid = $indexer->index_cm($cm)[0];
        (new repository())->mark_indexed($resourceid, 'bit-1', 'a-stale-hash');

        // Mutate the page body; the cm stays valid (extractor reads fresh).
        $DB->set_field('page', 'content', '<p>Delta changed</p>', ['id' => $cm->instance]);

        $queued = $indexer->index_cm($cm);

        $this->assertSame([$resourceid], $queued);
        $this->assertSame(status::PENDING_REINDEX, (new repository())->get($resourceid)->status);
    }

    /**
     * Bulk course indexing queues every supported activity.
     *
     * @return void
     */
    public function test_index_course_covers_supported_modules(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->make_page($course->id, '<p>Epsilon</p>');
        $this->getDataGenerator()->create_module('url', [
            'course' => $course->id,
            'externalurl' => 'https://example.org/zeta',
        ]);
        // An unsupported module must be ignored.
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $queued = (new indexer())->index_course($course->id);

        $this->assertCount(2, $queued);
        foreach ($queued as $resourceid) {
            $this->assertSame(status::PENDING, (new repository())->get($resourceid)->status);
        }
    }

    /**
     * Removing a resource releases the owner's own reference, so the
     * deletion actually reaches the brain instead of being reverted by
     * the orphan check tripping over the owner's own claim.
     *
     * @return void
     */
    public function test_remove_resource_releases_own_reference(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Iota</p>');

        $indexer = new indexer();
        $resourceid = $indexer->index_cm($cm)[0];
        $repo = new repository();
        $repo->mark_indexed($resourceid, 'bit-1', 'hash-1');

        $this->assertTrue($indexer->remove_resource($resourceid));
        $this->assertSame(status::PENDING_DELETION, $repo->get($resourceid)->status);
        $this->assertFalse((new reference_store())->has_references($resourceid));

        // The worker now sees an orphan and completes the untrack.
        (new tracker(repo: $repo, client: new mock_client()))->process($repo->get($resourceid));
        $this->assertNull($repo->get($resourceid));
    }

    /**
     * Removal still spares a bit that another live owner references.
     *
     * @return void
     */
    public function test_remove_resource_spares_shared_bit(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Kappa</p>');

        $indexer = new indexer();
        $resourceid = $indexer->index_cm($cm)[0];
        $repo = new repository();
        $repo->mark_indexed($resourceid, 'bit-1', 'hash-1');

        // A sibling activity also references the same bit.
        (new reference_store())->add($resourceid, 'mod_page', 999999, 100);

        $this->assertTrue($indexer->remove_resource($resourceid));

        // The worker refuses the untrack and the bit stays indexed.
        (new tracker(repo: $repo, client: new mock_client()))->process($repo->get($resourceid));
        $this->assertSame(status::INDEXED, $repo->get($resourceid)->status);
    }

    /**
     * A failed synchronous upload reverts the row to not indexed.
     *
     * With no background queue nothing may stay mid-flight: the teacher sees
     * the failure and the Index button reappears.
     *
     * @return void
     */
    public function test_execute_now_reverts_failed_upload(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Lambda</p>');

        $repo = new repository();
        $failingtracker = new tracker(repo: $repo, client: new mock_client(simulatestatus: 500));
        $indexer = new indexer(repo: $repo, tracker: $failingtracker);
        $resourceid = $indexer->index_cm($cm)[0];

        try {
            $indexer->execute_now($resourceid);
            $this->fail('Expected a transport exception.');
        } catch (\local_tarsus\client\transport_exception $e) {
            $this->assertSame(500, $e->httpstatus);
        }

        $this->assertSame(status::NOT_INDEXED, $repo->get($resourceid)->status);
    }

    /**
     * A failed synchronous deletion restores the row and the owner's claim.
     *
     * @return void
     */
    public function test_execute_now_reverts_failed_deletion(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Mu</p>');

        $repo = new repository();
        $failingtracker = new tracker(repo: $repo, client: new mock_client(simulatestatus: 500));
        $indexer = new indexer(repo: $repo, tracker: $failingtracker);
        $resourceid = $indexer->index_cm($cm)[0];
        $repo->mark_indexed($resourceid, 'bit-1', 'hash-1');

        $this->assertTrue($indexer->remove_resource($resourceid));

        try {
            $indexer->execute_now($resourceid);
            $this->fail('Expected a transport exception.');
        } catch (\local_tarsus\client\transport_exception $e) {
            // The bit is still in the brain, so the row must say so again.
        }

        $this->assertSame(status::INDEXED, $repo->get($resourceid)->status);
        $this->assertTrue((new reference_store())->has_references($resourceid));
    }

    /**
     * A re-index that fails after the old content was untracked must not
     * claim the resource is still indexed: the brain holds nothing.
     *
     * @return void
     */
    public function test_failed_reindex_upload_reverts_to_notindexed(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Nu</p>');

        $failonupload = new class extends mock_client {
            #[\Override]
            public function track_resource(array $resource): array {
                throw new \local_tarsus\client\transport_exception(500, 'mock upload failure');
            }
        };

        $repo = new repository();
        $indexer = new indexer(repo: $repo, tracker: new tracker(repo: $repo, client: $failonupload));
        $resourceid = $indexer->index_cm($cm)[0];
        $repo->mark_indexed($resourceid, 'bit-1', 'hash-1');

        $this->assertTrue($indexer->reindex_resource($resourceid));

        try {
            $indexer->execute_now($resourceid);
            $this->fail('Expected a transport exception.');
        } catch (\local_tarsus\client\transport_exception $e) {
            $this->assertSame(500, $e->httpstatus);
        }

        // The untrack succeeded, so the honest state is "nothing in the brain".
        $this->assertSame(status::NOT_INDEXED, $repo->get($resourceid)->status);
    }

    /**
     * A re-index that fails while untracking leaves the old content indexed.
     *
     * @return void
     */
    public function test_failed_reindex_untrack_keeps_indexed(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Xi</p>');

        $failonuntrack = new class extends mock_client {
            #[\Override]
            public function untrack_resource(array $resourceids): array {
                throw new \local_tarsus\client\transport_exception(500, 'mock untrack failure');
            }
        };

        $repo = new repository();
        $indexer = new indexer(repo: $repo, tracker: new tracker(repo: $repo, client: $failonuntrack));
        $resourceid = $indexer->index_cm($cm)[0];
        $repo->mark_indexed($resourceid, 'bit-1', 'hash-1');

        $this->assertTrue($indexer->reindex_resource($resourceid));

        try {
            $indexer->execute_now($resourceid);
            $this->fail('Expected a transport exception.');
        } catch (\local_tarsus\client\transport_exception $e) {
            // Nothing was removed, so the previous version is still live.
        }

        $this->assertSame(status::INDEXED, $repo->get($resourceid)->status);
    }

    /**
     * refresh_cm re-indexes tracked content but never tracks new material.
     *
     * @return void
     */
    public function test_refresh_only_touches_tracked(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id, '<p>Eta</p>');

        // Untracked module: refresh is a no-op, nothing is created.
        $this->assertSame([], (new indexer())->refresh_cm($cm));
        $resourceid = \local_tarsus\material\resource_id::build($course->id, 'mod_page', $cm->id);
        $this->assertNull((new repository())->get($resourceid));

        // Track it, mark indexed, change content, then refresh.
        (new indexer())->index_cm($cm);
        (new repository())->mark_indexed($resourceid, 'bit-1', 'stale');
        $DB->set_field('page', 'content', '<p>Theta changed</p>', ['id' => $cm->instance]);

        $this->assertSame([$resourceid], (new indexer())->refresh_cm($cm));
        $this->assertSame(status::PENDING_REINDEX, (new repository())->get($resourceid)->status);
    }
}
