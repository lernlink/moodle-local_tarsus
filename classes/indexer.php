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

use local_tarsus\index\reference_store;
use local_tarsus\index\repository;
use local_tarsus\index\status;
use local_tarsus\material\material;
use local_tarsus\material\resolver;
use local_tarsus\task\process_resource_task;

/**
 * Entry point that turns a course module into queued indexation work.
 *
 * Implements the decision half of §5: resolve materials, compute each content
 * hash, look up the local index map, and only queue an API call when the bit
 * is new or its content changed. An unchanged bit is skipped with no API call.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class indexer {

    /**
     * Constructor.
     *
     * @param resolver|null        $resolver Material resolver.
     * @param repository|null      $repo     Index map repository.
     * @param reference_store|null $refs     Resource reference store.
     */
    public function __construct(
        private ?resolver $resolver = null,
        private ?repository $repo = null,
        private ?reference_store $refs = null
    ) {
        $this->resolver ??= new resolver();
        $this->repo ??= new repository();
        $this->refs ??= new reference_store();
    }

    /**
     * Resolve a course module and queue any new or changed bits for indexing.
     *
     * @param \cm_info $cm The course module to index.
     * @return string[] The resource ids that were queued (new or re-index).
     */
    public function index_cm(\cm_info $cm): array {
        $queued = [];
        foreach ($this->resolver->resolve($cm) as $material) {
            if ($this->index_material($material)) {
                $queued[] = $material->resourceid;
            }
        }
        return $queued;
    }

    /**
     * Decide and queue a single material.
     *
     * @param material $material The extracted material.
     * @return bool True when an indexation call was queued.
     */
    private function index_material(material $material): bool {
        $resourceid = $material->resourceid;
        $hash = $material->content_hash();

        $row = $this->repo->ensure(
            $resourceid,
            $material->contextid,
            $material->component,
            $material->cmid
        );

        // This owner now references the bit (idempotent), so a sibling owner's
        // deletion will not untrack content we still need.
        $this->refs->add($resourceid, $material->component, $material->cmid, $material->contextid);

        $target = $this->target_status($row, $hash);
        if ($target === null) {
            return false; // Unchanged, or already in flight: no API call.
        }

        $this->repo->set_status($resourceid, $target);
        process_resource_task::queue($resourceid);
        return true;
    }

    /**
     * The status to move a row to, or null when no work is needed.
     *
     * @param \stdClass $row  The current index row.
     * @param string    $hash The freshly computed content hash.
     * @return string|null The target status, or null to skip.
     */
    private function target_status(\stdClass $row, string $hash): ?string {
        if ($row->status === status::NOT_INDEXED) {
            return status::PENDING;
        }
        if ($row->status === status::INDEXED && (string) $row->contenthash !== $hash) {
            return status::PENDING_REINDEX;
        }
        if ($row->status === status::ERROR) {
            // Manual/automatic recovery re-enters the pipeline.
            return status::PENDING;
        }
        return null;
    }
}
