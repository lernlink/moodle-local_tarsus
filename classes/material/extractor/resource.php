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
 * Extracts the files of a Resource (mod_resource) as document/media bits.
 *
 * Each real file becomes its own bit. The bit key deliberately says nothing
 * about the content: replacing the file must re-index the same bit, not
 * create a second one and orphan the first. A single-file activity — which
 * is what a Resource almost always is — therefore keys on 'main', so even
 * renaming the file leaves the id alone.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
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
        $files = array_values(
            $fs->get_area_files($modcontext->id, 'mod_resource', 'content', 0, 'sortorder', false)
        );
        $single = count($files) === 1;

        $materials = [];
        $taken = [];
        foreach ($files as $file) {
            $contenthash = $file->get_contenthash();
            $resourceid = $this->unique_resource_id(
                $cm,
                $single ? resource_id::MAIN : $file->get_filename(),
                $taken
            );
            $taken[$resourceid] = true;

            $materials[] = new material(
                resourceid: $resourceid,
                type: bit_type::for_file($file),
                contextid: $coursecontext->id,
                component: $this->component(),
                cmid: $cm->id,
                title: $this->clean_title($file->get_filename(), $coursecontext),
                filehash: $contenthash,
                pathnamehash: $file->get_pathnamehash(),
            );
        }

        return $materials;
    }

    /**
     * Build a resource id that no sibling file in this activity already holds.
     *
     * Two filenames can collide once the id scheme has stripped the characters
     * it does not allow ('report v2.pdf' and 'reportv2.pdf'), so the loser gets
     * a counter appended. Order comes from sortorder and is stable for a given
     * set of files.
     *
     * @param \cm_info $cm The owning course module.
     * @param string $bitkey The preferred bit key.
     * @param array $taken Ids already used in this extraction, as keys.
     * @return string A unique namespaced resource id.
     */
    private function unique_resource_id(\cm_info $cm, string $bitkey, array $taken): string {
        $resourceid = resource_id::build($cm->course, $this->component(), $cm->id, $bitkey);

        $suffix = 2;
        while (isset($taken[$resourceid])) {
            $resourceid = resource_id::build($cm->course, $this->component(), $cm->id, $bitkey . '-' . $suffix);
            $suffix++;
        }

        return $resourceid;
    }
}
