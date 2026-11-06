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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_tarsus\index;

/**
 * Indexation status vocabulary and the allowed state-machine transitions.
 *
 * State machine (spec §5):
 *   notindexed → pending → pendingconfirmation → indexed
 * plus pendingreindex, pendingdeletion and error.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status {
    /** @var string Tracked locally but not yet queued. */
    public const NOT_INDEXED = 'notindexed';

    /** @var string Queued for upload to the active brain. */
    public const PENDING = 'pending';

    /** @var string Uploaded; awaiting async indexing confirmation. */
    public const PENDING_CONFIRMATION = 'pendingconfirmation';

    /** @var string Live in the active brain (bit ref + hash stored). */
    public const INDEXED = 'indexed';

    /** @var string Content changed / re-index requested. */
    public const PENDING_REINDEX = 'pendingreindex';

    /** @var string Marked for removal from the brain. */
    public const PENDING_DELETION = 'pendingdeletion';

    /** @var string Upload/confirmation failed after the retry budget. */
    public const ERROR = 'error';

    /** @var string[] Every valid status. */
    public const ALL = [
        self::NOT_INDEXED,
        self::PENDING,
        self::PENDING_CONFIRMATION,
        self::INDEXED,
        self::PENDING_REINDEX,
        self::PENDING_DELETION,
        self::ERROR,
    ];

    /** @var array<string, string[]> Allowed transitions from each status. */
    private const TRANSITIONS = [
        self::NOT_INDEXED => [self::PENDING, self::PENDING_DELETION],
        // The notindexed state is the revert target when a synchronous upload fails:
        // with no background queue the row must never stay mid-flight.
        self::PENDING => [
            self::PENDING_CONFIRMATION,
            self::INDEXED,
            self::ERROR,
            self::PENDING_DELETION,
            self::NOT_INDEXED,
        ],
        // The brain is the source of truth: it may report a resource as simply
        // absent ("failed"), which drops the row back to the start state.
        self::PENDING_CONFIRMATION => [
            self::INDEXED,
            self::PENDING,
            self::NOT_INDEXED,
            self::ERROR,
            self::PENDING_DELETION,
        ],
        self::INDEXED => [self::PENDING_REINDEX, self::NOT_INDEXED, self::PENDING_DELETION],
        // The pending state is reached mid-re-index: once the old bits are untracked the
        // brain holds nothing, which is exactly what pending means. Recording
        // it makes a failure of the following upload revert honestly.
        self::PENDING_REINDEX => [
            self::PENDING,
            self::PENDING_CONFIRMATION,
            self::INDEXED,
            self::NOT_INDEXED,
            self::ERROR,
            self::PENDING_DELETION,
        ],
        // A pending deletion either completes, or reverts to indexed when the
        // resource turns out to still be referenced (shared-brain race).
        self::PENDING_DELETION => [self::NOT_INDEXED, self::INDEXED, self::ERROR],
        // From error a manual retry re-enters the pipeline, or the row is
        // dropped. The brain may also confirm the resource as indexed after
        // the local retry budget ran out (slow ingestion): that self-heals.
        self::ERROR => [
            self::PENDING,
            self::PENDING_REINDEX,
            self::PENDING_DELETION,
            self::NOT_INDEXED,
            self::INDEXED,
        ],
    ];

    /**
     * Whether a status value is recognised.
     *
     * @param string $status The status to check.
     * @return bool
     */
    public static function is_valid(string $status): bool {
        return in_array($status, self::ALL, true);
    }

    /**
     * Whether a transition between two statuses is allowed.
     *
     * A no-op transition (same status) is always allowed (idempotent writes).
     *
     * @param string $from Current status.
     * @param string $to   Target status.
     * @return bool
     */
    public static function can_transition(string $from, string $to): bool {
        if ($from === $to) {
            return true;
        }
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Human-readable label for a status.
     *
     * @param string $status The status.
     * @return string Localised label.
     */
    public static function label(string $status): string {
        return get_string('status:' . $status, 'local_tarsus');
    }
}
