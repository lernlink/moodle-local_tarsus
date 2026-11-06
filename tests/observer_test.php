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

namespace local_tarsus;

use local_tarsus\index\reference_store;
use local_tarsus\index\repository;
use local_tarsus\index\status;

/**
 * Tests for the course module deletion observer.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\observer
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        observer::reset_upload_budget();
    }

    /**
     * Create an indexed page and return its cm and resource id.
     *
     * @param \stdClass $course The course to create it in.
     * @param string    $content The page body.
     * @return array{0: \cm_info, 1: string} The module info and resource id.
     */
    private function make_indexed_page(\stdClass $course, string $content): array {
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => $content,
            'contentformat' => FORMAT_HTML,
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($page->cmid);

        $indexer = new indexer();
        $resourceid = $indexer->index_cm($cm)[0];
        $indexer->execute_now($resourceid);

        $repo = new repository();
        $repo->set_status($resourceid, status::INDEXED);

        return [$cm, $resourceid];
    }

    /**
     * Deleting a module untracks its orphaned bits there and then.
     *
     * The activity is gone from the dashboard, so nothing could ever clean
     * this up later: the row must not survive the deletion.
     *
     * @return void
     */
    public function test_module_deletion_untracks_orphan(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $resourceid = 'site:1:mod_page:' . $page->id;

        $repo = new repository();
        $repo->ensure($resourceid, $context->id, 'mod_page', $page->id);
        $repo->set_status($resourceid, status::PENDING);
        $repo->mark_indexed($resourceid, 'mock-bit', sha1($resourceid));

        $refs = new reference_store();
        $refs->add($resourceid, 'mod_page', $page->cmid, $context->id);

        course_delete_module($page->cmid);

        $this->assertFalse($refs->has_references($resourceid));
        $this->assertNull($repo->get($resourceid));
    }

    /**
     * A brain failure during deletion leaves the row marked for a later sweep
     * and never breaks the Moodle-side deletion.
     *
     * @return void
     */
    public function test_module_deletion_survives_a_blocked_call(): void {
        global $DB;
        $this->resetAfterTest(true);

        set_config('killswitch', 1, 'local_tarsus');

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $resourceid = 'site:1:mod_page:' . $page->id;

        $repo = new repository();
        $repo->ensure($resourceid, $context->id, 'mod_page', $page->id);
        $repo->set_status($resourceid, status::PENDING);
        $repo->mark_indexed($resourceid, 'mock-bit', sha1($resourceid));
        (new reference_store())->add($resourceid, 'mod_page', $page->cmid, $context->id);

        course_delete_module($page->cmid);

        // Moodle 5.2 also deprecates course_delete_module() itself, so assert
        // on the message rather than on how many notices were emitted.
        $messages = array_map(
            static fn(\stdClass $entry): string => $entry->message,
            $this->getDebuggingMessages()
        );
        $this->resetDebugging();
        $this->assertStringContainsString('could not untrack deleted content', implode("\n", $messages));

        // Moodle deleted the activity; the bit is still in the brain and the
        // row records that so it can be retried.
        $this->assertFalse(
            $DB->record_exists('course_modules', ['id' => $page->cmid]),
            'the module should be gone regardless of the brain'
        );
        $this->assertSame(status::PENDING_DELETION, $repo->get($resourceid)->status);
    }

    /**
     * Deleting a course clears everything it had in the brain.
     *
     * @return void
     */
    public function test_course_deletion_clears_the_whole_course(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $resourceid = 'site:1:mod_page:orphan-of-course';

        $repo = new repository();
        $repo->ensure($resourceid, $context->id, 'mod_page', 999999);
        $repo->set_status($resourceid, status::PENDING);
        $repo->mark_indexed($resourceid, 'mock-bit', sha1($resourceid));
        (new reference_store())->add($resourceid, 'mod_page', 999999, $context->id);

        delete_course($course->id, false);

        $this->assertNull($repo->get($resourceid));
        $this->assertFalse((new reference_store())->has_references($resourceid));
    }

    /**
     * A file removed from an activity is untracked on the next save.
     *
     * Moodle emits no event for the file itself, so this is caught by
     * comparing what the activity holds now against what is recorded for it.
     *
     * @return void
     */
    public function test_removed_file_is_untracked_on_update(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $modcontext = \context_module::instance($resource->cmid);

        // Three files, so removing one still leaves the rest multi-file and
        // their ids untouched.
        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id, 'mod_resource', 'content');
        foreach (['one.txt', 'two.txt', 'three.txt'] as $filename) {
            $fs->create_file_from_string([
                'contextid' => $modcontext->id,
                'component' => 'mod_resource',
                'filearea' => 'content',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
            ], 'contents of ' . $filename);
        }

        $cm = get_fast_modinfo($course->id)->get_cm($resource->cmid);
        $indexer = new indexer();
        $indexer->index_cm($cm);

        $repo = new repository();
        foreach ($repo->get_by_owner('mod_resource', $resource->cmid) as $row) {
            $repo->mark_uploaded($row->resourceid, 'hash-' . $row->id);
            $repo->mark_indexed($row->resourceid, 'bit-' . $row->id, 'hash-' . $row->id);
        }
        $this->assertCount(3, $repo->get_by_owner('mod_resource', $resource->cmid));

        // The teacher drops one file and saves the activity.
        $gone = $fs->get_file($modcontext->id, 'mod_resource', 'content', 0, '/', 'two.txt');
        $goneid = null;
        foreach ($repo->get_by_owner('mod_resource', $resource->cmid) as $row) {
            if (str_ends_with($row->resourceid, ':two.txt')) {
                $goneid = $row->resourceid;
            }
        }
        $this->assertNotNull($goneid);
        $gone->delete();

        \core\event\course_module_updated::create_from_cm(
            get_fast_modinfo($course->id)->get_cm($resource->cmid),
            $modcontext
        )->trigger();

        $remaining = $repo->get_by_owner('mod_resource', $resource->cmid);
        $this->assertCount(2, $remaining);
        $this->assertNull($repo->get($goneid));
        $this->assertFalse((new reference_store())->has_references($goneid));
    }

    /**
     * A reference still held by another owner keeps the resource indexed.
     *
     * @return void
     */
    public function test_remaining_reference_keeps_indexed(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $resourceid = 'site:1:mod_page:' . $page->id;

        $repo = new repository();
        $repo->ensure($resourceid, $context->id, 'mod_page', $page->id);
        $repo->set_status($resourceid, status::PENDING);
        $repo->mark_indexed($resourceid, 'mock-bit', sha1($resourceid));

        $refs = new reference_store();
        $refs->add($resourceid, 'mod_page', $page->cmid, $context->id);
        $refs->add($resourceid, 'mod_book', 999, $context->id);

        course_delete_module($page->cmid);

        $this->assertTrue($refs->has_references($resourceid));
        $this->assertSame(status::INDEXED, $repo->get($resourceid)->status);
    }

    /**
     * Fire the update event for a module, as saving its form would.
     *
     * @param \cm_info $cm The module that was saved.
     * @return void
     */
    private function trigger_update(\cm_info $cm): void {
        \core\event\course_module_updated::create([
            'objectid' => $cm->id,
            'context' => \context_module::instance($cm->id),
            'other' => [
                'modulename' => $cm->modname,
                'instanceid' => $cm->instance,
                'name' => $cm->name,
            ],
        ])->trigger();
    }

    /**
     * Editing tracked content sends the new version while the teacher saves,
     * so nobody has to press Re-index.
     *
     * @return void
     */
    public function test_module_update_uploads_the_new_version(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        [$cm, $resourceid] = $this->make_indexed_page($course, '<p>Original</p>');

        $DB->set_field('page', 'content', '<p>Edited</p>', ['id' => $cm->instance]);
        $this->trigger_update($cm);

        // The mock brain confirms asynchronously, so the row is now awaiting
        // confirmation of the *new* content rather than sitting on a stale one.
        $row = (new repository())->get($resourceid);
        $this->assertSame(status::PENDING_CONFIRMATION, $row->status);

        $material = (new \local_tarsus\material\resolver())->resolve_by_resource_id($resourceid);
        $this->assertSame($material->content_hash(), $row->contenthash);
    }

    /**
     * Saving without touching the content costs nothing.
     *
     * @return void
     */
    public function test_module_update_without_a_change_does_nothing(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        [$cm, $resourceid] = $this->make_indexed_page($course, '<p>Unchanged</p>');

        $this->trigger_update($cm);

        $this->assertSame(status::INDEXED, (new repository())->get($resourceid)->status);
    }

    /**
     * When the brain refuses, the row shows the drift instead of claiming to
     * be current, and the save itself is unaffected.
     *
     * @return void
     */
    public function test_failed_auto_upload_leaves_content_changed(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        [$cm, $resourceid] = $this->make_indexed_page($course, '<p>Original</p>');

        $DB->set_field('page', 'content', '<p>Edited</p>', ['id' => $cm->instance]);
        set_config('killswitch', 1, 'local_tarsus');

        $this->trigger_update($cm);
        $this->assertDebuggingCalled();

        $this->assertSame(status::PENDING_REINDEX, (new repository())->get($resourceid)->status);
    }

    /**
     * One request only uploads so much: a restore or an import walking many
     * activities defers the rest to the teacher rather than running for ever.
     *
     * @return void
     */
    public function test_upload_budget_defers_the_rest(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $pages = [];
        for ($i = 0; $i <= observer::MAX_UPLOADS_PER_REQUEST; $i++) {
            $pages[] = $this->make_indexed_page($course, "<p>Body {$i}</p>");
        }

        // Everything changes at once, as a restore would leave it.
        foreach ($pages as [$cm, $resourceid]) {
            $DB->set_field('page', 'content', '<p>Edited</p>', ['id' => $cm->instance]);
        }
        foreach ($pages as [$cm, $resourceid]) {
            $this->trigger_update($cm);
        }

        $repo = new repository();
        $uploaded = 0;
        $deferred = 0;
        foreach ($pages as [$cm, $resourceid]) {
            if ($repo->get($resourceid)->status === status::PENDING_REINDEX) {
                $deferred++;
            } else {
                $uploaded++;
            }
        }

        $this->assertSame(observer::MAX_UPLOADS_PER_REQUEST, $uploaded);
        $this->assertSame(1, $deferred);
    }
}
