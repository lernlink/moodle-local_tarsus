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

namespace local_tarsus\task;

use local_tarsus\indexer;

/**
 * Indexes an entire course in the background (teacher "index this course").
 *
 * The teacher action only enqueues this task; the heavy resolve/hash/decide
 * sweep then runs off the web request and merely queues per-resource work, so
 * the actual API calls still flow through the guarded queue task.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class index_course_task extends \core\task\adhoc_task {

    /**
     * Queue a bulk index of a course.
     *
     * @param int $courseid The course id.
     * @return void
     */
    public static function queue(int $courseid): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid]);
        $task->set_component('local_tarsus');
        \core\task\manager::queue_adhoc_task($task, true);
    }

    #[\Override]
    public function execute(): void {
        $data = (array) $this->get_custom_data();
        $courseid = (int) ($data['courseid'] ?? 0);
        if ($courseid <= 0) {
            return;
        }

        (new indexer())->index_course($courseid);
    }
}
