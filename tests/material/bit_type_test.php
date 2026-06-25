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

namespace local_tarsus\material;

/**
 * Tests for bit type classification.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\material\bit_type
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bit_type_test extends \advanced_testcase {

    /**
     * Create a stored file with a given name in the user draft area.
     *
     * @param string $filename The file name (drives the MIME type).
     * @return \stored_file
     */
    private function file(string $filename): \stored_file {
        $context = \context_system::instance();
        return get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'local_tarsus',
            'filearea' => 'unittest',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], 'data');
    }

    /**
     * Audio and video files classify as media; everything else as document.
     *
     * @return void
     */
    public function test_for_file_classification(): void {
        $this->resetAfterTest(true);

        $this->assertSame(bit_type::MEDIA, bit_type::for_file($this->file('clip.mp3')));
        $this->assertSame(bit_type::MEDIA, bit_type::for_file($this->file('lecture.mp4')));
        $this->assertSame(bit_type::DOCUMENT, bit_type::for_file($this->file('notes.pdf')));
        $this->assertSame(bit_type::DOCUMENT, bit_type::for_file($this->file('handout.txt')));
    }

    /**
     * Only the four known types are valid.
     *
     * @return void
     */
    public function test_is_valid(): void {
        $this->assertTrue(bit_type::is_valid(bit_type::TEXT));
        $this->assertTrue(bit_type::is_valid(bit_type::MEDIA));
        $this->assertFalse(bit_type::is_valid('spreadsheet'));
    }
}
