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

use local_tarsus\indexer;

/**
 * Tests for the course indexation report (teacher reconciliation view).
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\index\course_report
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_report_test extends \advanced_testcase {

    /**
     * Create a page and return its cm_info.
     *
     * @param int $courseid The course id.
     * @return \cm_info
     */
    private function make_page(int $courseid): \cm_info {
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $courseid,
            'content' => '<p>Body</p>',
            'contentformat' => FORMAT_HTML,
        ]);
        return get_fast_modinfo($courseid)->get_cm($page->cmid);
    }

    /**
     * Supported activities are listed with their bit statuses; unsupported are not.
     *
     * @return void
     */
    public function test_lists_supported_activities_with_status(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id);
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $report = (new course_report())->for_course($course->id);

        $this->assertCount(1, $report);
        $this->assertSame($cm->id, $report[0]['cmid']);
        $this->assertCount(1, $report[0]['bits']);
        $this->assertSame(status::NOT_INDEXED, $report[0]['bits'][0]['status']);
    }

    /**
     * A tracked bit reflects its stored status, and the summary counts it.
     *
     * @return void
     */
    public function test_reflects_tracked_status_and_summary(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_page($course->id);

        $resourceid = (new indexer())->index_cm($cm)[0];
        (new repository())->mark_indexed($resourceid, 'bit-1', 'hash');

        $report = (new course_report())->for_course($course->id);
        $this->assertSame(status::INDEXED, $report[0]['bits'][0]['status']);

        $summary = (new course_report())->summary($course->id);
        $this->assertSame(1, $summary[status::INDEXED]);
    }
}
