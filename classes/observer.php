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

use core\event\course_deleted;
use core\event\course_module_deleted;
use core\event\course_module_updated;
use local_tarsus\client\transport_exception;
use local_tarsus\guardrail\blocked_exception;
use local_tarsus\index\reference_store;
use local_tarsus\index\repository;
use local_tarsus\index\status;

/**
 * Event observers that keep the brain in step with Moodle (push side of §5).
 *
 * Content leaving Moodle must leave the brain with it, and there is no queue
 * to defer that to: once the activity is gone it no longer appears on the
 * dashboard, so no teacher action can ever clean it up. The observers
 * therefore untrack immediately.
 *
 * They never let a brain failure break the Moodle operation that triggered
 * them: a deletion that could not be delivered stays recorded as
 * pendingdeletion, which is the durable record a later sweep acts on.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Remove an activity's bits from the brain as the activity is deleted.
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
        $doomed = [];
        foreach ($freed as $resourceid) {
            if ($refs->has_references($resourceid)) {
                // Still referenced by another live owner; leave it indexed.
                continue;
            }
            $row = $repo->get($resourceid);
            if ($row !== null && status::can_transition($row->status, status::PENDING_DELETION)) {
                $repo->set_status($resourceid, status::PENDING_DELETION);
                $doomed[] = $repo->get($resourceid);
            }
        }

        if ($doomed === []) {
            return;
        }

        // One call for the whole activity, however many files it held.
        self::deliver(fn() => (new tracker())->process_deletions($doomed));
    }

    /**
     * Remove everything the brain holds for a course as the course is deleted.
     *
     * Bits are filed under the course context id as their asset id, so this
     * costs one call no matter how large the course was.
     *
     * @param course_deleted $event The triggered event.
     * @return void
     */
    public static function course_deleted(course_deleted $event): void {
        $contextid = (int) $event->contextid;
        if ($contextid <= 0) {
            return;
        }

        $repo = new repository();
        $rows = $repo->get_by_context($contextid);
        if ($rows === []) {
            return;
        }

        foreach ($rows as $row) {
            if (status::can_transition($row->status, status::PENDING_DELETION)) {
                $repo->set_status($row->resourceid, status::PENDING_DELETION);
            }
        }

        if (!self::deliver(fn() => (new tracker())->untrack_asset((string) $contextid))) {
            return; // Rows stay marked so the deletion can be retried.
        }

        $refs = new reference_store();
        foreach ($rows as $row) {
            $refs->remove_all_for_resource($row->resourceid);
            $repo->delete($row->resourceid);
        }
    }

    /**
     * Run an outbound call, never letting its failure escape into Moodle.
     *
     * @param callable $call The call to attempt.
     * @return bool True when it went through.
     */
    private static function deliver(callable $call): bool {
        try {
            $call();
            return true;
        } catch (blocked_exception | transport_exception $e) {
            // The Moodle-side deletion has already happened and must stand.
            debugging(
                'local_tarsus: could not untrack deleted content: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return false;
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
