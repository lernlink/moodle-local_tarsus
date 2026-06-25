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
 * Tests for the backoff schedule.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\index\backoff
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backoff_test extends \advanced_testcase {

    /**
     * The delay doubles each attempt: 120, 240, 480, 960.
     *
     * @return void
     */
    public function test_delay_doubles_per_attempt(): void {
        $this->assertSame(120, backoff::delay(1));
        $this->assertSame(240, backoff::delay(2));
        $this->assertSame(480, backoff::delay(3));
        $this->assertSame(960, backoff::delay(4));
    }

    /**
     * Jittered delay stays within ±10% of the base value.
     *
     * @return void
     */
    public function test_jitter_stays_in_bounds(): void {
        for ($i = 0; $i < 20; $i++) {
            $delay = backoff::delay(2, true);
            $this->assertGreaterThanOrEqual(216, $delay);
            $this->assertLessThanOrEqual(264, $delay);
        }
    }

    /**
     * The retry budget is exhausted at the limit.
     *
     * @return void
     */
    public function test_exhausted_at_limit(): void {
        $this->assertFalse(backoff::exhausted(3));
        $this->assertTrue(backoff::exhausted(4));
        $this->assertTrue(backoff::exhausted(5));
    }
}
