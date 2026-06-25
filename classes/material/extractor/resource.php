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

namespace local_tarsus\material\extractor;

use local_tarsus\material\bit_type;
use local_tarsus\material\material;
use local_tarsus\material\resource_id;

/**
 * Extracts the files of a Resource (mod_resource) as document/media bits.
 *
 * Each real file becomes its own bit, keyed by its content hash so re-adding
 * the same file is idempotent and a changed file produces a new bit key.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource extends base {

    #[\Override]
    public function modname(): string {
        return 'resource';
    }

    #[\Override]
    public function extract(\cm_info $cm): array {
        $modcontext = \context_module::instance($cm->id);
        $coursecontext = \context_course::instance($cm->course);

        $fs = get_file_storage();
        $files = $fs->get_area_files($modcontext->id, 'mod_resource', 'content', 0, 'sortorder', false);

        $materials = [];
        foreach ($files as $file) {
            $contenthash = $file->get_contenthash();
            $materials[] = new material(
                resourceid: resource_id::build($cm->course, $this->component(), $cm->id, $contenthash),
                type: bit_type::for_file($file),
                contextid: $coursecontext->id,
                component: $this->component(),
                cmid: $cm->id,
                title: $this->clean_title($file->get_filename(), $coursecontext),
                filehash: $contenthash,
            );
        }

        return $materials;
    }
}
