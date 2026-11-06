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

namespace local_tarsus\material\extractor;

use local_tarsus\material\bit_type;
use local_tarsus\material\material;
use local_tarsus\material\resource_id;

/**
 * Extracts the external link of a URL activity as a single url bit.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url extends base {
    #[\Override]
    public function modname(): string {
        return 'url';
    }

    #[\Override]
    public function extract(\cm_info $cm): array {
        global $DB;

        $record = $DB->get_record('url', ['id' => $cm->instance]);
        if (!$record || trim($record->externalurl ?? '') === '') {
            return [];
        }

        $context = \context_course::instance($cm->course);

        $material = new material(
            resourceid: resource_id::build($cm->course, $this->component(), $cm->id),
            type: bit_type::URL,
            contextid: $context->id,
            component: $this->component(),
            cmid: $cm->id,
            title: $this->clean_title($cm->name, $context),
            body: trim($record->externalurl),
        );

        return [$material];
    }
}
