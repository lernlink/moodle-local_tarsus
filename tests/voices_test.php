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
 * Choosing the narration voice.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_tarsus\voices
 */
final class voices_test extends \advanced_testcase {

    /**
     * Automatic mode takes the first voice of the learner's language and
     * falls back to the configured voice for a language without one.
     */
    public function test_auto_mode_follows_the_language(): void {
        $this->resetAfterTest(true);
        set_config('mockmode', 1, 'local_tarsus');
        set_config('voice_mode', voices::MODE_AUTO, 'local_tarsus');
        set_config('voice', 'Daniel', 'local_tarsus');

        $this->assertSame('Sara', voices::choose('de'));
        $this->assertSame('Abigail', voices::choose('en'));
        $this->assertSame('Maria', voices::choose('fr'));
        $this->assertSame('Daniel', voices::choose('uk'));
    }

    /**
     * Fixed mode reads to everyone with the configured voice.
     */
    public function test_fixed_mode_ignores_the_language(): void {
        $this->resetAfterTest(true);
        set_config('mockmode', 1, 'local_tarsus');
        set_config('voice_mode', voices::MODE_FIXED, 'local_tarsus');
        set_config('voice', 'Philip', 'local_tarsus');

        $this->assertSame('Philip', voices::choose('de'));
        $this->assertSame('Philip', voices::choose('en'));
    }

    /**
     * The catalogue is cached, and the options carry language and gender.
     */
    public function test_catalogue_is_cached_and_labelled(): void {
        $this->resetAfterTest(true);
        set_config('mockmode', 1, 'local_tarsus');

        $this->assertCount(5, voices::catalogue());
        $this->assertNotEmpty(get_config('local_tarsus', 'voices_cache'));
        $options = voices::options();
        $this->assertSame('Sara (German, female)', $options['Sara']);
        $this->assertSame('Daniel (English, male)', $options['Daniel']);
    }
}
