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
 * Tests for the indexation status state machine.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\index\status
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status_test extends \advanced_testcase {

    /**
     * The happy-path lifecycle transitions are all legal.
     *
     * @return void
     */
    public function test_happy_path_transitions(): void {
        $this->assertTrue(status::can_transition(status::NOT_INDEXED, status::PENDING));
        $this->assertTrue(status::can_transition(status::PENDING, status::PENDING_CONFIRMATION));
        $this->assertTrue(status::can_transition(status::PENDING_CONFIRMATION, status::INDEXED));
        $this->assertTrue(status::can_transition(status::INDEXED, status::PENDING_REINDEX));
        $this->assertTrue(status::can_transition(status::INDEXED, status::PENDING_DELETION));
    }

    /**
     * Illegal jumps are rejected.
     *
     * @return void
     */
    public function test_illegal_transitions_rejected(): void {
        $this->assertFalse(status::can_transition(status::NOT_INDEXED, status::INDEXED));
        $this->assertFalse(status::can_transition(status::INDEXED, status::PENDING));
        $this->assertFalse(status::can_transition(status::PENDING_DELETION, status::PENDING));
    }

    /**
     * A row that failed locally may still be confirmed by the brain later.
     *
     * @return void
     */
    public function test_error_self_heals_to_indexed(): void {
        $this->assertTrue(status::can_transition(status::ERROR, status::INDEXED));
    }

    /**
     * A no-op transition is always allowed (idempotent writes).
     *
     * @return void
     */
    public function test_same_status_is_allowed(): void {
        $this->assertTrue(status::can_transition(status::PENDING, status::PENDING));
    }

    /**
     * Validity check recognises only known statuses.
     *
     * @return void
     */
    public function test_validity(): void {
        $this->assertTrue(status::is_valid(status::ERROR));
        $this->assertFalse(status::is_valid('bogus'));
    }
}
