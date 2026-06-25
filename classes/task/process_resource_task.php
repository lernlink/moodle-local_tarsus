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

use local_tarsus\index\repository;
use local_tarsus\tracker;

/**
 * Process a single resource now (event-driven responsiveness / manual retry).
 *
 * The scheduled queue task is the steady-state drain; this adhoc task lets the
 * plugin act on one resource immediately without waiting for the next cron run.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_resource_task extends \core\task\adhoc_task {

    /**
     * Queue this task for a single resource.
     *
     * @param string $resourceid Namespaced resource id.
     * @return void
     */
    public static function queue(string $resourceid): void {
        $task = new self();
        $task->set_custom_data(['resourceid' => $resourceid]);
        $task->set_component('local_tarsus');
        \core\task\manager::queue_adhoc_task($task, true);
    }

    #[\Override]
    public function execute(): void {
        $data = (array) $this->get_custom_data();
        $resourceid = (string) ($data['resourceid'] ?? '');
        if ($resourceid === '') {
            return;
        }

        $row = (new repository())->get($resourceid);
        if ($row === null) {
            return;
        }

        (new tracker())->process($row);
    }
}
