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
     * Opt-in path (teacher action / bulk): tracks materials that are not yet
     * tracked, and re-indexes those whose content changed.
     *
     * @param \cm_info $cm The course module to index.
     * @return string[] The resource ids that were queued (new or re-index).
     */
    public function index_cm(\cm_info $cm): array {
        return $this->run($cm, false);
    }

    /**
     * Index every supported activity in a course (opt-in bulk action).
     *
     * @param int $courseid The course id.
     * @return string[] All resource ids queued across the course.
     */
    public function index_course(int $courseid): array {
        $queued = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress) || !$this->resolver->supports($cm->modname)) {
                continue;
            }
            $queued = array_merge($queued, $this->index_cm($cm));
        }
        return $queued;
    }

    /**
     * Re-evaluate only the already-tracked bits of a course module.
     *
     * Event-driven path (module updated): refreshes content the teacher has
     * already chosen to index, but never auto-tracks new material.
     *
     * @param \cm_info $cm The course module to refresh.
     * @return string[] The resource ids queued for re-indexing.
     */
    public function refresh_cm(\cm_info $cm): array {
        return $this->run($cm, true);
    }

    /**
     * Index a single material identified by its namespaced resource id.
     *
     * Opt-in per-file action: resolves the material afresh, tracks it if new,
     * and queues an upload when the content is new or changed.
     *
     * @param string $resourceid The namespaced resource id.
     * @return bool True when an indexation call was queued.
     */
    public function index_resource(string $resourceid): bool {
        $material = $this->resolver->resolve_by_resource_id($resourceid);
        if ($material === null) {
            return false;
        }
        return $this->index_material($material, false);
    }

    /**
     * Force a re-index of a single tracked resource (add-before-remove, §5).
     *
     * @param string $resourceid The namespaced resource id.
     * @return bool True when a re-index was queued.
     */
    public function reindex_resource(string $resourceid): bool {
        $row = $this->repo->get($resourceid);
        if ($row === null) {
            // Not tracked yet: a re-index request degrades to a first index.
            return $this->index_resource($resourceid);
        }

        if ($row->status === status::ERROR) {
            $this->repo->reset_attempts($resourceid);
        }

        if (!status::can_transition($row->status, status::PENDING_REINDEX)) {
            return false; // Already in flight: nothing to do.
        }

        $this->repo->set_status($resourceid, status::PENDING_REINDEX);
        process_resource_task::queue($resourceid);
        return true;
    }

    /**
     * Mark a single resource for removal from the brain (shared-brain safe, §5).
     *
     * The worker still runs the reference-count check before untracking.
     *
     * @param string $resourceid The namespaced resource id.
     * @return bool True when a deletion was queued.
     */
    public function remove_resource(string $resourceid): bool {
        $row = $this->repo->get($resourceid);
        if ($row === null) {
            return false;
        }

        if (!status::can_transition($row->status, status::PENDING_DELETION)) {
            return false;
        }

        $this->repo->set_status($resourceid, status::PENDING_DELETION);
        process_resource_task::queue($resourceid);
        return true;
    }

    /**
     * Retry a single resource stuck in the error state.
     *
     * @param string $resourceid The namespaced resource id.
     * @return bool True when a retry was queued.
     */
    public function retry_resource(string $resourceid): bool {
        $row = $this->repo->get($resourceid);
        if ($row === null || $row->status !== status::ERROR) {
            return false;
        }

        $this->repo->reset_attempts($resourceid);
        $this->repo->set_status($resourceid, status::PENDING);
        process_resource_task::queue($resourceid);
        return true;
    }

    /**
     * Resolve a module and decide each material.
     *
     * @param \cm_info $cm          The course module.
     * @param bool     $onlytracked When true, ignore materials with no index row.
     * @return string[] The resource ids that were queued.
     */
    private function run(\cm_info $cm, bool $onlytracked): array {
        $queued = [];
        foreach ($this->resolver->resolve($cm) as $material) {
            if ($this->index_material($material, $onlytracked)) {
                $queued[] = $material->resourceid;
            }
        }
        return $queued;
    }

    /**
     * Decide and queue a single material.
     *
     * @param material $material    The extracted material.
     * @param bool     $onlytracked When true, skip materials that have no row yet.
     * @return bool True when an indexation call was queued.
     */
    private function index_material(material $material, bool $onlytracked = false): bool {
        $resourceid = $material->resourceid;
        $existing = $this->repo->get($resourceid);

        if ($onlytracked && $existing === null) {
            return false; // Not chosen for indexing: a refresh must not track it.
        }

        $row = $existing ?? $this->repo->ensure(
            $resourceid,
            $material->contextid,
            $material->component,
            $material->cmid
        );

        // This owner now references the bit (idempotent), so a sibling owner's
        // deletion will not untrack content we still need.
        $this->refs->add($resourceid, $material->component, $material->cmid, $material->contextid);

        $target = $this->target_status($row, $material->content_hash());
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
