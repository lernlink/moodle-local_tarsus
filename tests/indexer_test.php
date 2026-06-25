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
}
