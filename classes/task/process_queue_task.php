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

use local_tarsus\client\client_interface;
use local_tarsus\client\transport_exception;
use local_tarsus\guardrail\blocked_exception;
use local_tarsus\index\repository;
use local_tarsus\index\status;
use local_tarsus\index\status_sync;
use local_tarsus\tracker;

/**
 * Drains the indexation queue: all API I/O happens here, behind the guardrails.
 *
 * Each due row in an actionable status is handed to the tracker. Status reads
 * on page render never touch the API — only this worker does.
 *
 * Confirmation polling is the exception: the brain reports many resources per
 * call, so those rows are batched instead of walked one by one.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_queue_task extends \core\task\scheduled_task {

    #[\Override]
    public function get_name(): string {
        return get_string('task:processqueue', 'local_tarsus');
    }

    #[\Override]
    public function execute(): void {
        $repo = new repository();
        $tracker = new tracker();

        // Uploads first, so anything queued this run can be confirmed later.
        foreach ([status::PENDING, status::PENDING_REINDEX] as $status) {
            foreach ($repo->get_due($status) as $row) {
                $tracker->process($row);
            }
        }

        $this->confirm_due($repo);

        foreach ($repo->get_due(status::PENDING_DELETION) as $row) {
            $tracker->process($row);
        }
    }

    /**
     * Poll every resource awaiting confirmation, batched by the wire limit.
     *
     * A resource the brain has not finished ingesting keeps its retry budget
     * ticking, so a permanently stuck upload still ends in the error state.
     *
     * @param repository $repo Index map repository.
     * @return void
     */
    private function confirm_due(repository $repo): void {
        $due = $repo->get_due(status::PENDING_CONFIRMATION);
        if ($due === []) {
            return;
        }

        $sync = new status_sync($repo);

        foreach (array_chunk(array_column($due, 'resourceid'), client_interface::BATCH_LIMIT) as $chunk) {
            try {
                $changed = $sync->refresh($chunk);
            } catch (blocked_exception $e) {
                return; // Guardrail held the call; the queue holds with it.
            } catch (transport_exception $e) {
                foreach ($chunk as $resourceid) {
                    $repo->record_failure($resourceid);
                }
                return;
            }

            foreach ($chunk as $resourceid) {
                if (!isset($changed[$resourceid])) {
                    // Still ingesting: back off before asking again.
                    $repo->record_failure($resourceid);
                }
            }
        }
    }
}
