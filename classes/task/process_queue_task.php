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
use local_tarsus\index\status;
use local_tarsus\tracker;

/**
 * Drains the indexation queue: all API I/O happens here, behind the guardrails.
 *
 * Each due row in an actionable status is handed to the tracker. Status reads
 * on page render never touch the API — only this worker does.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_queue_task extends \core\task\scheduled_task {

    /** @var string[] Statuses processed each run, in order. */
    private const ACTIONABLE = [
        status::PENDING,
        status::PENDING_REINDEX,
        status::PENDING_CONFIRMATION,
        status::PENDING_DELETION,
    ];

    #[\Override]
    public function get_name(): string {
        return get_string('task:processqueue', 'local_tarsus');
    }

    #[\Override]
    public function execute(): void {
        $repo = new repository();
        $tracker = new tracker();

        foreach (self::ACTIONABLE as $status) {
            foreach ($repo->get_due($status) as $row) {
                $tracker->process($row);
            }
        }
    }
}
