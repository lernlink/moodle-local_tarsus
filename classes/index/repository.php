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

use coding_exception;

/**
 * Typed access to the local index map (source of truth for indexation status).
 *
 * Status reads are DB reads — zero API calls on render. All transitions are
 * validated against {@see status} so the row can only move through legal
 * states of the state machine.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository {

    /** @var string The index table. */
    private const TABLE = 'local_tarsus_index';

    /**
     * Fetch a row by its namespaced resource id.
     *
     * @param string $resourceid Namespaced resource id.
     * @return \stdClass|null The row, or null if none.
     */
    public function get(string $resourceid): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['resourceid' => $resourceid]);
        return $row ?: null;
    }

    /**
     * Ensure a row exists for a resource, creating it as notindexed if absent.
     *
     * Idempotent: a stable resource id keeps tracking safe to repeat.
     *
     * @param string $resourceid Namespaced resource id.
     * @param int    $contextid  Owning context id.
     * @param string $component  Frankenstyle component of the source resource.
     * @param int    $itemid     Item id within the component.
     * @return \stdClass The existing or newly created row.
     */
    public function ensure(string $resourceid, int $contextid, string $component, int $itemid): \stdClass {
        global $DB;

        if ($row = $this->get($resourceid)) {
            return $row;
        }

        $now = time();
        $row = (object) [
            'resourceid' => $resourceid,
            'contextid' => $contextid,
            'component' => $component,
            'itemid' => $itemid,
            'contenthash' => null,
            'bitref' => null,
            'status' => status::NOT_INDEXED,
            'attempts' => 0,
            'nextretry' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record(self::TABLE, $row);

        // Re-fetch so the returned row carries the same DB-typed fields as get().
        return $this->require_row($resourceid);
    }

    /**
     * Move a row to a new status, validating the transition.
     *
     * @param string $resourceid Namespaced resource id.
     * @param string $newstatus  Target status.
     * @return void
     * @throws coding_exception When the row is missing or the transition is illegal.
     */
    public function set_status(string $resourceid, string $newstatus): void {
        global $DB;

        $row = $this->require_row($resourceid);

        if (!status::can_transition($row->status, $newstatus)) {
            throw new coding_exception(
                "Illegal index status transition {$row->status} -> {$newstatus} for {$resourceid}"
            );
        }

        $row->status = $newstatus;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * Mark a resource as live in the brain, storing its bit ref and hash.
     *
     * @param string $resourceid  Namespaced resource id.
     * @param string $bitref      Brain bit reference.
     * @param string $contenthash Hash of the indexed content.
     * @return void
     */
    public function mark_indexed(string $resourceid, string $bitref, string $contenthash): void {
        global $DB;

        $row = $this->require_row($resourceid);

        if (!status::can_transition($row->status, status::INDEXED)) {
            throw new coding_exception(
                "Illegal index status transition {$row->status} -> " . status::INDEXED . " for {$resourceid}"
            );
        }

        $row->status = status::INDEXED;
        $row->bitref = $bitref;
        $row->contenthash = $contenthash;
        $row->attempts = 0;
        $row->nextretry = 0;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * Record a successful upload: await confirmation, remembering what was sent.
     *
     * The stored hash describes the content the brain actually received, so a
     * later confirmation must not recompute it from current Moodle state — the
     * source may have changed in the meantime.
     *
     * @param string $resourceid  Namespaced resource id.
     * @param string $contenthash Hash of the content that was uploaded.
     * @return void
     * @throws coding_exception When the row is missing or the transition is illegal.
     */
    public function mark_uploaded(string $resourceid, string $contenthash): void {
        global $DB;

        $row = $this->require_row($resourceid);

        if (!status::can_transition($row->status, status::PENDING_CONFIRMATION)) {
            throw new coding_exception(
                "Illegal index status transition {$row->status} -> " . status::PENDING_CONFIRMATION . " for {$resourceid}"
            );
        }

        $row->status = status::PENDING_CONFIRMATION;
        $row->contenthash = $contenthash;
        $row->attempts = 0;
        $row->nextretry = 0;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * Record a failed attempt: schedule the next retry, or fail to error.
     *
     * @param string $resourceid Namespaced resource id.
     * @return \stdClass The updated row.
     */
    public function record_failure(string $resourceid): \stdClass {
        global $DB;

        $row = $this->require_row($resourceid);
        $row->attempts = (int) $row->attempts + 1;
        $now = time();

        if (backoff::exhausted($row->attempts)) {
            if (status::can_transition($row->status, status::ERROR)) {
                $row->status = status::ERROR;
            }
            $row->nextretry = 0;
        } else {
            $row->nextretry = $now + backoff::delay($row->attempts, true);
        }

        $row->timemodified = $now;
        $DB->update_record(self::TABLE, $row);

        return $row;
    }

    /**
     * Reset the retry counter (e.g. on manual retry).
     *
     * @param string $resourceid Namespaced resource id.
     * @return void
     */
    public function reset_attempts(string $resourceid): void {
        global $DB;

        $row = $this->require_row($resourceid);
        $row->attempts = 0;
        $row->nextretry = 0;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * Whether stored content differs from a freshly computed hash.
     *
     * A row with no stored hash is treated as changed (never indexed).
     *
     * @param string $resourceid Namespaced resource id.
     * @param string $newhash    Freshly computed content hash.
     * @return bool
     */
    public function content_changed(string $resourceid, string $newhash): bool {
        $row = $this->get($resourceid);
        if (!$row) {
            return true;
        }
        return (string) $row->contenthash !== $newhash;
    }

    /**
     * Rows in a given status that are due for processing now.
     *
     * @param string $status The status to drain.
     * @param int|null $now  Reference time (defaults to now).
     * @return \stdClass[] Due rows, oldest retry first.
     */
    public function get_due(string $status, ?int $now = null): array {
        global $DB;

        $now ??= time();
        $select = 'status = :status AND nextretry <= :now';
        $params = ['status' => $status, 'now' => $now];

        return $DB->get_records_select(self::TABLE, $select, $params, 'nextretry ASC');
    }

    /**
     * All rows in a given status, irrespective of retry schedule.
     *
     * @param string $status The status to list.
     * @return \stdClass[] Matching rows.
     */
    public function get_by_status(string $status): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['status' => $status]);
    }

    /**
     * Every row in the index map.
     *
     * @return \stdClass[] All rows.
     */
    public function get_all(): array {
        global $DB;
        return $DB->get_records(self::TABLE);
    }

    /**
     * All rows belonging to a context (i.e. to one course).
     *
     * @param int $contextid The owning course context id.
     * @return \stdClass[] Matching rows.
     */
    public function get_by_context(int $contextid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['contextid' => $contextid]);
    }

    /**
     * All rows owned by one activity.
     *
     * @param string $component Frankenstyle component of the owner.
     * @param int    $itemid    Owner item id (course module id).
     * @return \stdClass[] Matching rows.
     */
    public function get_by_owner(string $component, int $itemid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['component' => $component, 'itemid' => $itemid]);
    }

    /**
     * Delete a row (e.g. after the source module is gone and bits untracked).
     *
     * @param string $resourceid Namespaced resource id.
     * @return void
     */
    public function delete(string $resourceid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['resourceid' => $resourceid]);
    }

    /**
     * Load a row or fail loudly.
     *
     * @param string $resourceid Namespaced resource id.
     * @return \stdClass
     * @throws coding_exception When the row does not exist.
     */
    private function require_row(string $resourceid): \stdClass {
        $row = $this->get($resourceid);
        if (!$row) {
            throw new coding_exception("No index row for resource {$resourceid}");
        }
        return $row;
    }
}
