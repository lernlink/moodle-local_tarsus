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

use core\event\course_module_deleted;
use core\event\course_module_updated;
use local_tarsus\index\reference_store;
use local_tarsus\index\repository;
use local_tarsus\index\status;

/**
 * Event observers that keep the index map consistent (push side of §5).
 *
 * The scheduled reconcile task is the pull-based safety net; these observers
 * react immediately so deletions don't leave orphaned references behind.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Drop an owner's references on module deletion and queue orphans for removal.
     *
     * @param course_module_deleted $event The triggered event.
     * @return void
     */
    public static function course_module_deleted(course_module_deleted $event): void {
        $modulename = $event->other['modulename'] ?? '';
        if ($modulename === '') {
            return;
        }

        $refs = new reference_store();
        $freed = $refs->remove_all_for_owner('mod_' . $modulename, (int) $event->objectid);
        if (!$freed) {
            return;
        }

        $repo = new repository();
        foreach ($freed as $resourceid) {
            if ($refs->has_references($resourceid)) {
                // Still referenced by another live owner; leave it indexed.
                continue;
            }
            $row = $repo->get($resourceid);
            if ($row !== null && status::can_transition($row->status, status::PENDING_DELETION)) {
                $repo->set_status($resourceid, status::PENDING_DELETION);
            }
        }
    }

    /**
     * Re-index already-tracked bits when a module is updated.
     *
     * Only content the teacher has already chosen to index is refreshed;
     * untracked material is never auto-tracked from an edit.
     *
     * @param course_module_updated $event The triggered event.
     * @return void
     */
    public static function course_module_updated(course_module_updated $event): void {
        $cm = get_coursemodule_from_id('', (int) $event->objectid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        try {
            $cminfo = get_fast_modinfo($cm->course)->get_cm($cm->id);
        } catch (\moodle_exception $e) {
            return;
        }

        (new indexer())->refresh_cm($cminfo);
    }
}
