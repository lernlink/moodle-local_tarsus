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

/**
 * Tracks which Moodle owners (activities) reference a namespaced resource_id.
 *
 * The brain is shared at course level, so a single resource_id may be
 * referenced by several activities. This store answers the question the
 * untrack step depends on: "does any live owner still reference this
 * resource?" — so a bit is only removed from the brain once it is truly
 * orphaned (spec §5, US-3).
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reference_store {

    /** @var string The reference table. */
    private const TABLE = 'local_tarsus_resource_ref';

    /**
     * Register a reference from an owner to a resource (idempotent).
     *
     * @param string $resourceid     Namespaced resource id.
     * @param string $ownercomponent Frankenstyle component of the owner.
     * @param int    $owneritemid    Owner item id (e.g. course module id).
     * @param int    $ownercontextid Owner context id.
     * @return void
     */
    public function add(string $resourceid, string $ownercomponent, int $owneritemid, int $ownercontextid = 0): void {
        global $DB;

        $key = [
            'resourceid' => $resourceid,
            'ownercomponent' => $ownercomponent,
            'owneritemid' => $owneritemid,
        ];

        if ($DB->record_exists(self::TABLE, $key)) {
            return;
        }

        $DB->insert_record(self::TABLE, (object) ($key + [
            'ownercontextid' => $ownercontextid,
            'timecreated' => time(),
        ]));
    }

    /**
     * Remove a single owner's reference to a resource.
     *
     * @param string $resourceid     Namespaced resource id.
     * @param string $ownercomponent Frankenstyle component of the owner.
     * @param int    $owneritemid    Owner item id.
     * @return void
     */
    public function remove(string $resourceid, string $ownercomponent, int $owneritemid): void {
        global $DB;

        $DB->delete_records(self::TABLE, [
            'resourceid' => $resourceid,
            'ownercomponent' => $ownercomponent,
            'owneritemid' => $owneritemid,
        ]);
    }

    /**
     * Remove every reference held by an owner (e.g. on activity deletion).
     *
     * @param string $ownercomponent Frankenstyle component of the owner.
     * @param int    $owneritemid    Owner item id.
     * @return string[] The resource ids the owner had referenced.
     */
    public function remove_all_for_owner(string $ownercomponent, int $owneritemid): array {
        global $DB;

        $key = ['ownercomponent' => $ownercomponent, 'owneritemid' => $owneritemid];
        $resourceids = $DB->get_fieldset_select(self::TABLE, 'resourceid', 'ownercomponent = :ownercomponent AND owneritemid = :owneritemid', $key);
        $DB->delete_records(self::TABLE, $key);

        return array_values(array_unique($resourceids));
    }

    /**
     * How many owners reference a resource.
     *
     * @param string $resourceid Namespaced resource id.
     * @return int
     */
    public function count(string $resourceid): int {
        global $DB;
        return $DB->count_records(self::TABLE, ['resourceid' => $resourceid]);
    }

    /**
     * Whether any owner still references a resource.
     *
     * @param string $resourceid Namespaced resource id.
     * @return bool
     */
    public function has_references(string $resourceid): bool {
        return $this->count($resourceid) > 0;
    }

    /**
     * Whether the resource is safe to untrack from the shared brain.
     *
     * @param string $resourceid Namespaced resource id.
     * @return bool True when no live owner references it.
     */
    public function safe_to_untrack(string $resourceid): bool {
        return !$this->has_references($resourceid);
    }
}
