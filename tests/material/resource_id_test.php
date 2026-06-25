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
 * Tests for the namespaced resource id helper.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\material\resource_id
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resource_id_test extends \advanced_testcase {

    /**
     * Build then parse round-trips every field.
     *
     * @return void
     */
    public function test_build_parse_roundtrip(): void {
        $id = resource_id::build(42, 'mod_page', 99, 'main');
        $parts = resource_id::parse($id);

        $this->assertNotNull($parts);
        $this->assertSame(42, $parts['courseid']);
        $this->assertSame('mod_page', $parts['component']);
        $this->assertSame(99, $parts['cmid']);
        $this->assertSame('main', $parts['bitkey']);
    }

    /**
     * The bit key defaults to 'main' when omitted.
     *
     * @return void
     */
    public function test_default_bitkey(): void {
        $parts = resource_id::parse(resource_id::build(1, 'mod_url', 7));
        $this->assertSame(resource_id::MAIN, $parts['bitkey']);
    }

    /**
     * Malformed ids are rejected.
     *
     * @return void
     */
    public function test_parse_rejects_malformed(): void {
        $this->assertNull(resource_id::parse('site:1:mod_page:42'));
        $this->assertNull(resource_id::parse('not-an-id'));
        $this->assertNull(resource_id::parse('sitex:notanumber:mod_page:42:main'));
    }

    /**
     * The site token is stable within a site.
     *
     * @return void
     */
    public function test_site_token_is_stable(): void {
        $this->assertSame(resource_id::site_token(), resource_id::site_token());
    }
}
