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

use local_tarsus\client\transport_exception;
use local_tarsus\guardrail\blocked_exception;
use local_tarsus\tracker;

/**
 * Finishes deletions that could not be delivered when they were made.
 *
 * Deletion is the one operation nobody can retry by hand: the activity it
 * belonged to is gone, so it appears on no dashboard and offers no button.
 * When the brain was unreachable at that moment the row keeps its
 * pendingdeletion status as the record of unfinished work, and this sweep
 * acts on it the next time someone opens the plugin.
 *
 * It is opportunistic on purpose: bounded per run, silent about failure, and
 * never allowed to affect whatever the visitor actually came to do.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deletion_sweeper {

    /** @var int Rows one sweep will attempt. */
    public const MAX_PER_RUN = 20;

    /** @var bool Whether this request has already swept. */
    private static bool $swept = false;

    /**
     * Attempt the outstanding deletions, at most once per request.
     *
     * @param repository|null $repo    Index map repository.
     * @param tracker|null    $tracker The worker service.
     * @return int How many rows were cleared.
     */
    public static function sweep(?repository $repo = null, ?tracker $tracker = null): int {
        if (self::$swept) {
            return 0;
        }
        self::$swept = true;

        return self::run($repo, $tracker);
    }

    /**
     * Attempt the outstanding deletions unconditionally.
     *
     * Used by the admin page, where the request exists precisely to retry.
     *
     * @param repository|null $repo    Index map repository.
     * @param tracker|null    $tracker The worker service.
     * @return int How many rows were cleared.
     */
    public static function run(?repository $repo = null, ?tracker $tracker = null): int {
        $repo ??= new repository();

        $rows = array_slice(
            array_values($repo->get_by_status(status::PENDING_DELETION)),
            0,
            self::MAX_PER_RUN
        );
        if ($rows === []) {
            return 0;
        }

        try {
            ($tracker ?? new tracker())->process_deletions($rows);
        } catch (blocked_exception | transport_exception $e) {
            // Still unreachable: the rows keep their status and their turn.
            return 0;
        }

        $cleared = 0;
        foreach ($rows as $row) {
            if ($repo->get($row->resourceid) === null) {
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * How much unfinished deletion work is outstanding.
     *
     * @param repository|null $repo Index map repository.
     * @return \stdClass[] The rows still awaiting removal from the brain.
     */
    public static function outstanding(?repository $repo = null): array {
        $repo ??= new repository();

        return $repo->get_by_status(status::PENDING_DELETION);
    }

    /**
     * Allow this request to sweep again (tests only).
     *
     * @return void
     */
    public static function reset(): void {
        self::$swept = false;
    }
}
