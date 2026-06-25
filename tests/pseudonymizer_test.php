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

namespace local_tarsus;

/**
 * Tests for the pseudonymizer.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\pseudonymizer
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pseudonymizer_test extends \advanced_testcase {

    /**
     * The same user id always yields the same pseudonym.
     *
     * @return void
     */
    public function test_pseudonym_is_stable(): void {
        $this->resetAfterTest(true);

        $first = pseudonymizer::pseudonymize(42);
        $second = pseudonymizer::pseudonymize(42);

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first), 'Expected a hex SHA-256 digest.');
    }

    /**
     * Different user ids yield different pseudonyms.
     *
     * @return void
     */
    public function test_pseudonyms_differ_per_user(): void {
        $this->resetAfterTest(true);

        $this->assertNotSame(
            pseudonymizer::pseudonymize(1),
            pseudonymizer::pseudonymize(2)
        );
    }

    /**
     * The secret is generated once and persisted.
     *
     * @return void
     */
    public function test_secret_is_generated_and_persisted(): void {
        $this->resetAfterTest(true);

        $this->assertFalse(get_config('local_tarsus', 'hmac_secret'));
        pseudonymizer::pseudonymize(7);
        $this->assertNotEmpty(get_config('local_tarsus', 'hmac_secret'));
    }
}
