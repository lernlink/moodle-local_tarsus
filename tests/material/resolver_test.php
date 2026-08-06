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
 * Tests for the material resolver and the module extractors.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\material\resolver
 * @covers     \local_tarsus\material\extractor\page
 * @covers     \local_tarsus\material\extractor\url
 * @covers     \local_tarsus\material\extractor\resource
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resolver_test extends \advanced_testcase {

    /**
     * Fetch cm_info for a generated module.
     *
     * @param \stdClass $module The generated module record (carries cmid).
     * @param int       $courseid The course id.
     * @return \cm_info
     */
    private function cm(\stdClass $module, int $courseid): \cm_info {
        return get_fast_modinfo($courseid)->get_cm($module->cmid);
    }

    /**
     * A page yields a single text bit containing its body.
     *
     * @return void
     */
    public function test_page_extracts_text(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>The mitochondria is the powerhouse.</p>',
            'contentformat' => FORMAT_HTML,
        ]);

        $materials = (new resolver())->resolve($this->cm($page, $course->id));

        $this->assertCount(1, $materials);
        $this->assertSame(bit_type::TEXT, $materials[0]->type);
        $this->assertStringContainsString('powerhouse', $materials[0]->body);
    }

    /**
     * A URL yields a single url bit with the external link.
     *
     * @return void
     */
    public function test_url_extracts_link(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $url = $this->getDataGenerator()->create_module('url', [
            'course' => $course->id,
            'externalurl' => 'https://example.org/reading',
        ]);

        $materials = (new resolver())->resolve($this->cm($url, $course->id));

        $this->assertCount(1, $materials);
        $this->assertSame(bit_type::URL, $materials[0]->type);
        $this->assertSame('https://example.org/reading', $materials[0]->body);
    }

    /**
     * A single-file resource yields one document bit keyed by 'main', so
     * replacing the file re-indexes that bit instead of orphaning it.
     *
     * @return void
     */
    public function test_resource_extracts_files(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $modcontext = \context_module::instance($resource->cmid);

        // Replace the generator's file with one known file, so the activity
        // holds exactly one bit.
        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id, 'mod_resource', 'content');
        $filerecord = [
            'contextid' => $modcontext->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'handout.txt',
        ];
        $stored = $fs->create_file_from_string($filerecord, 'study notes');

        $materials = (new resolver())->resolve($this->cm($resource, $course->id));

        $match = array_values(array_filter(
            $materials,
            fn(material $m) => $m->title === 'handout.txt'
        ));
        $this->assertNotEmpty($match);
        $this->assertSame(bit_type::DOCUMENT, $match[0]->type);
        $this->assertSame($stored->get_contenthash(), $match[0]->filehash);
        $this->assertStringEndsWith(':main', $match[0]->resourceid);
        $this->assertStringNotContainsString($stored->get_contenthash(), $match[0]->resourceid);
    }

    /**
     * Replacing the file keeps the resource id and only moves the hash, which
     * is what turns a replacement into a re-index instead of an orphan.
     *
     * @return void
     */
    public function test_replacing_a_file_keeps_the_resource_id(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $modcontext = \context_module::instance($resource->cmid);

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $modcontext->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'handout.txt',
        ];

        // Start from a single known file, so the bit keys on 'main'.
        $fs->delete_area_files($modcontext->id, 'mod_resource', 'content');
        $fs->create_file_from_string($filerecord, 'first edition');
        $before = (new resolver())->resolve($this->cm($resource, $course->id))[0];

        // The teacher uploads a new version of the same handout.
        $fs->delete_area_files($modcontext->id, 'mod_resource', 'content');
        $fs->create_file_from_string($filerecord, 'second edition, revised');
        $after = (new resolver())->resolve($this->cm($resource, $course->id))[0];

        $this->assertSame($before->resourceid, $after->resourceid);
        $this->assertNotSame($before->content_hash(), $after->content_hash());
    }

    /**
     * supports() reflects the registered extractors.
     *
     * @return void
     */
    public function test_supports(): void {
        $resolver = new resolver();
        $this->assertTrue($resolver->supports('page'));
        $this->assertTrue($resolver->supports('url'));
        $this->assertTrue($resolver->supports('resource'));
        $this->assertFalse($resolver->supports('quiz'));
    }
}
