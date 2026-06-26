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

namespace local_tarsus\index;

use local_tarsus\material\resolver;

/**
 * Read-side view of a course's indexation status (zero API calls).
 *
 * Drives the teacher reconciliation list: for every supported activity it
 * resolves the material bits and reads their status from the local index map.
 * Per §3 the status is a local fact, so rendering never touches the brain.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_report {

    /**
     * Constructor.
     *
     * @param resolver|null   $resolver Material resolver.
     * @param repository|null $repo     Index map repository.
     */
    public function __construct(
        private ?resolver $resolver = null,
        private ?repository $repo = null
    ) {
        $this->resolver ??= new resolver();
        $this->repo ??= new repository();
    }

    /**
     * Per-activity indexation status for a course.
     *
     * @param int $courseid The course id.
     * @return array<int, array{cmid: int, modname: string, name: string, bits: array}>
     *         One entry per supported, indexable activity.
     */
    public function for_course(int $courseid): array {
        $rows = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress) || !$this->resolver->supports($cm->modname)) {
                continue;
            }

            $bits = $this->bits_for($cm);
            if (!$bits) {
                continue;
            }

            $rows[] = [
                'cmid' => $cm->id,
                'modname' => $cm->modname,
                'name' => $cm->get_formatted_name(),
                'bits' => $bits,
            ];
        }
        return $rows;
    }

    /**
     * A count of bits per status across the whole course.
     *
     * @param int $courseid The course id.
     * @return array<string, int> Status value => count (only non-zero entries).
     */
    public function summary(int $courseid): array {
        $counts = [];
        foreach ($this->for_course($courseid) as $activity) {
            foreach ($activity['bits'] as $bit) {
                $counts[$bit['status']] = ($counts[$bit['status']] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Resolve a module's bits and attach each one's current status.
     *
     * @param \cm_info $cm The course module.
     * @return array<int, array{resourceid: string, title: string, type: string, status: string, statuslabel: string}>
     */
    private function bits_for(\cm_info $cm): array {
        $bits = [];
        foreach ($this->resolver->resolve($cm) as $material) {
            $row = $this->repo->get($material->resourceid);
            $statusvalue = $row ? $row->status : status::NOT_INDEXED;
            $bits[] = [
                'resourceid' => $material->resourceid,
                'title' => $material->title,
                'type' => $material->type,
                'status' => $statusvalue,
                'statuslabel' => status::label($statusvalue),
            ];
        }
        return $bits;
    }
}
