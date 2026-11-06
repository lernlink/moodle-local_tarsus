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

namespace local_tarsus\client;

use moodle_exception;

/**
 * Transport-level failure from the Tarsus client (429 / 5xx / timeout).
 *
 * Carries the HTTP status so the guardrails pipeline can decide whether to
 * retry, back off, or trip the circuit breaker. The mock raises this to
 * exercise those paths deterministically.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transport_exception extends moodle_exception {
    /** @var int The HTTP status code (0 for a timeout / connection failure). */
    public int $httpstatus;

    /** @var string The start of the response body on an HTTP error, for callers that recognise the brain's messages. */
    public string $body;

    /**
     * Constructor.
     *
     * @param int    $httpstatus The HTTP status code, or 0 for timeout.
     * @param string $detail     Optional debugging detail.
     * @param string $body       The start of the error response body, if any.
     */
    public function __construct(int $httpstatus, string $detail = '', string $body = '') {
        $this->httpstatus = $httpstatus;
        $this->body = $body;
        parent::__construct('transporterror', 'local_tarsus', '', $httpstatus, $detail);
    }
}
