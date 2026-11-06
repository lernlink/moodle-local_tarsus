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

namespace local_tarsus\guardrail;

use moodle_exception;

/**
 * Raised when a guardrail gate refuses an outbound call.
 *
 * The reason ('killswitch', 'breaker', 'budget', 'ratelimit') lets callers
 * decide whether to hold the queue, requeue with backoff, or surface an error.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class blocked_exception extends moodle_exception {
    /** @var string The gate that blocked the call. */
    public string $reason;

    /**
     * Constructor.
     *
     * @param string $reason One of killswitch|breaker|budget|ratelimit.
     */
    public function __construct(string $reason) {
        $this->reason = $reason;
        parent::__construct('blocked_' . $reason, 'local_tarsus');
    }
}
