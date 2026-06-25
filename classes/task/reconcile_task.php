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

use local_tarsus\index\reference_store;
use local_tarsus\index\repository;
use local_tarsus\index\status;

/**
 * Janitor: corrects index drift that event observers miss.
 *
 * Currently sweeps for orphaned resources — rows that are live in the brain
 * but no longer referenced by any owner — and queues them for deletion. The
 * queue task then performs the guarded untrack.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_task extends \core\task\scheduled_task {

    #[\Override]
    public function get_name(): string {
        return get_string('task:reconcile', 'local_tarsus');
    }

    #[\Override]
    public function execute(): void {
        $repo = new repository();
        $refs = new reference_store();

        foreach ($repo->get_by_status(status::INDEXED) as $row) {
            if (!$refs->has_references($row->resourceid)
                    && status::can_transition($row->status, status::PENDING_DELETION)) {
                $repo->set_status($row->resourceid, status::PENDING_DELETION);
            }
        }
    }
}
