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

namespace local_tarsus;

/**
 * Tests for the usage meter.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\usage_meter
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class usage_meter_test extends \advanced_testcase {
    /**
     * Records accumulate per context and site-wide.
     *
     * @return void
     */
    public function test_records_accumulate(): void {
        $this->resetAfterTest(true);

        $meter = new usage_meter();
        $meter->record(100, 5, 'search', 6);
        $meter->record(100, 5, 'search', 6);
        $meter->record(200, 7, 'track', 0);

        $this->assertSame(12, $meter->total_for_context(100));
        $this->assertSame(0, $meter->total_for_context(200));
        $this->assertSame(12, $meter->total());
    }

    /**
     * An unseen context totals to zero.
     *
     * @return void
     */
    public function test_unseen_context_is_zero(): void {
        $this->resetAfterTest(true);

        $this->assertSame(0, (new usage_meter())->total_for_context(999));
    }
}
