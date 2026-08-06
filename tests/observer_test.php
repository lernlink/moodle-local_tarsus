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

use local_tarsus\index\reference_store;
use local_tarsus\index\repository;
use local_tarsus\index\status;

/**
 * Tests for the course module deletion observer.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\observer
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer_test extends \advanced_testcase {

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
     * Updating a tracked module queues it for re-indexing.
     *
     * @return void
     */
    public function test_module_update_triggers_reindex(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>Original</p>',
            'contentformat' => FORMAT_HTML,
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($page->cmid);

        // Track and index the page, then mutate its body.
        $resourceid = (new \local_tarsus\indexer())->index_cm($cm)[0];
        (new repository())->mark_indexed($resourceid, 'bit-1', 'stale');
        $DB->set_field('page', 'content', '<p>Edited</p>', ['id' => $page->id]);

        $event = \core\event\course_module_updated::create([
            'objectid' => $cm->id,
            'context' => \context_module::instance($cm->id),
            'other' => [
                'modulename' => 'page',
                'instanceid' => $cm->instance,
                'name' => $cm->name,
            ],
        ]);
        $event->trigger();

        $this->assertSame(status::PENDING_REINDEX, (new repository())->get($resourceid)->status);
    }
}
