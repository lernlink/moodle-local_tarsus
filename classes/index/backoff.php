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
 * Capped exponential backoff schedule for upload retries.
 *
 * Spec §5: retrylimit = 4, backoff 120 → 240 → 480 → 960 s. Optional jitter
 * spreads retries so a batch does not retry in lockstep.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backoff {

    /** @var int Maximum number of attempts before giving up. */
    public const RETRY_LIMIT = 4;

    /** @var int Base delay in seconds for the first retry. */
    public const BASE_SECONDS = 120;

    /**
     * Delay before the given attempt number (1-based).
     *
     * @param int  $attempt The attempt number (1 = first retry).
     * @param bool $jitter  When true, add up to ±10% random jitter.
     * @return int Delay in seconds.
     */
    public static function delay(int $attempt, bool $jitter = false): int {
        $attempt = max(1, $attempt);
        $delay = self::BASE_SECONDS * (2 ** ($attempt - 1));

        if ($jitter) {
            $spread = (int) round($delay * 0.1);
            $delay += random_int(-$spread, $spread);
        }

        return max(0, (int) $delay);
    }

    /**
     * Whether the retry budget is spent for the given attempt count.
     *
     * @param int $attempts Attempts made so far.
     * @return bool
     */
    public static function exhausted(int $attempts): bool {
        return $attempts >= self::RETRY_LIMIT;
    }
}
