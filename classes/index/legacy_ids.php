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

use local_tarsus\material\resource_id;
use local_tarsus\tracker;

/**
 * Retires resource ids built under the old content-addressed scheme.
 *
 * File bits used to be keyed by the file's content hash, which made the id
 * change whenever the file did: the replacement was tracked as a new resource
 * and the previous one stayed in the brain forever, unreachable from the
 * dashboard. The key is now content-independent, so ids minted before the
 * change no longer match any material and must be withdrawn from the brain
 * before the teacher re-indexes under the new id.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_ids {

    /**
     * Withdraw every index row whose id carries a content hash.
     *
     * Rows the brain never received are simply dropped; the rest are untracked
     * first. A failure leaves them marked pendingdeletion, which is the record
     * a later sweep retries from.
     *
     * @param repository|null      $repo    Index map repository.
     * @param reference_store|null $refs    Resource reference store.
     * @param tracker|null         $tracker The worker service.
     * @return int How many rows were retired.
     */
    public static function retire(
        ?repository $repo = null,
        ?reference_store $refs = null,
        ?tracker $tracker = null
    ): int {
        $repo ??= new repository();
        $refs ??= new reference_store();

        $doomed = [];
        $retired = 0;

        foreach ($repo->get_all() as $row) {
            if (!self::is_legacy($row->resourceid)) {
                continue;
            }

            $refs->remove_all_for_resource($row->resourceid);
            $retired++;

            if ($row->status === status::NOT_INDEXED) {
                // Never reached the brain: nothing to withdraw.
                $repo->delete($row->resourceid);
                continue;
            }

            $repo->set_status($row->resourceid, status::PENDING_DELETION);
            $doomed[] = $repo->get($row->resourceid);
        }

        if ($doomed !== []) {
            ($tracker ?? new tracker())->process_deletions($doomed);
        }

        return $retired;
    }

    /**
     * Whether an id was minted by the content-addressed scheme.
     *
     * Those ids end in the file's content hash, which Moodle stores as a
     * 40-character sha1. No key the plugin mints now can look like that:
     * they are 'main' or a sanitised filename, which keeps its extension.
     *
     * @param string $resourceid The namespaced resource id.
     * @return bool
     */
    public static function is_legacy(string $resourceid): bool {
        $parts = resource_id::parse($resourceid);

        return $parts !== null && preg_match('/^[0-9a-f]{40}$/', $parts['bitkey']) === 1;
    }
}
