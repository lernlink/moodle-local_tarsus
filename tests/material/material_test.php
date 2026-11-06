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

namespace local_tarsus\material;

/**
 * Tests for the material value object: hashing and payload shape.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\material\material
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class material_test extends \advanced_testcase {
    /**
     * Build a text material with the given body.
     *
     * @param string $body The body text.
     * @return material
     */
    private function text_material(string $body): material {
        return new material(
            resourceid: 'site:1:mod_page:5:main',
            type: bit_type::TEXT,
            contextid: 100,
            component: 'mod_page',
            cmid: 5,
            title: 'Lesson one',
            body: $body,
        );
    }

    /**
     * The same content hashes identically; changed content hashes differently.
     *
     * @return void
     */
    public function test_hash_changes_with_content(): void {
        $a = $this->text_material('hello world');
        $b = $this->text_material('hello world');
        $c = $this->text_material('hello mars');

        $this->assertSame($a->content_hash(), $b->content_hash());
        $this->assertNotSame($a->content_hash(), $c->content_hash());
        $this->assertSame(64, strlen($a->content_hash()));
    }

    /**
     * A text payload carries the body under 'text'.
     *
     * @return void
     */
    public function test_text_payload(): void {
        $payload = $this->text_material('body')->to_payload();

        $this->assertSame('site:1:mod_page:5:main', $payload['resource_id']);
        $this->assertSame(bit_type::TEXT, $payload['type']);
        $this->assertSame('body', $payload['text']);
        $this->assertArrayNotHasKey('url', $payload);
    }

    /**
     * A document payload carries the file content hash, not inline text.
     *
     * @return void
     */
    public function test_document_payload(): void {
        $material = new material(
            resourceid: 'site:1:mod_resource:8:abc',
            type: bit_type::DOCUMENT,
            contextid: 100,
            component: 'mod_resource',
            cmid: 8,
            title: 'notes.pdf',
            filehash: 'abc123',
        );
        $payload = $material->to_payload();

        $this->assertSame('abc123', $payload['contenthash']);
        $this->assertSame('notes.pdf', $payload['filename']);
        $this->assertArrayNotHasKey('text', $payload);
    }
}
