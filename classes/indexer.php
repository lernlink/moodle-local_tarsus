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

namespace local_tarsus;

use local_tarsus\client\transport_exception;
use local_tarsus\guardrail\blocked_exception;
use local_tarsus\index\reference_store;
use local_tarsus\index\repository;
use local_tarsus\index\status;
use local_tarsus\material\material;
use local_tarsus\material\resolver;

/**
 * Entry point that turns a course module into indexation work.
 *
 * Implements the decision half of §5: resolve materials, compute each content
 * hash, look up the local index map, and only mark a bit for an API call when
 * it is new or its content changed. An unchanged bit is skipped with no API
 * call. The marked work is then run synchronously via {@see execute_now()} —
 * there is no background queue, so a failure reverts the row to its previous
 * stable state and surfaces to the caller.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class indexer {
    /**
     * Constructor.
     *
     * @param resolver|null        $resolver Material resolver.
     * @param repository|null      $repo     Index map repository.
     * @param reference_store|null $refs     Resource reference store.
     * @param tracker|null         $tracker  The synchronous worker service.
     */
    public function __construct(
        /** @var ?resolver Resolves course modules to materials */
        private ?resolver $resolver = null,
        /** @var ?repository The index map repository */
        private ?repository $repo = null,
        /** @var ?reference_store The store of resource owners */
        private ?reference_store $refs = null,
        /** @var ?tracker The tracker that talks to the brain */
        private ?tracker $tracker = null
    ) {
        $this->resolver ??= new resolver();
        $this->repo ??= new repository();
        $this->refs ??= new reference_store();
        $this->tracker ??= new tracker(repo: $this->repo, refs: $this->refs, resolver: $this->resolver);
    }

    /**
     * Resolve a course module and mark any new or changed bits for indexing.
     *
     * Opt-in path (teacher action / bulk): tracks materials that are not yet
     * tracked, and re-indexes those whose content changed.
     *
     * @param \cm_info $cm The course module to index.
     * @return string[] The resource ids that were marked (new or re-index).
     */
    public function index_cm(\cm_info $cm): array {
        return $this->run($cm, false);
    }

    /**
     * Index every supported activity in a course (opt-in bulk action).
     *
     * @param int $courseid The course id.
     * @return string[] All resource ids marked across the course.
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
     * @return string[] The resource ids marked for re-indexing.
     */
    public function refresh_cm(\cm_info $cm): array {
        return $this->run($cm, true);
    }

    /**
     * Index a single material identified by its namespaced resource id.
     *
     * Opt-in per-file action: resolves the material afresh, tracks it if new,
     * and marks an upload when the content is new or changed.
     *
     * @param string $resourceid The namespaced resource id.
     * @return bool True when an indexation call was marked.
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
     * @return bool True when a re-index was marked.
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
        return true;
    }

    /**
     * Mark a single resource for removal from the brain (shared-brain safe, §5).
     *
     * The worker still runs the reference-count check before untracking.
     *
     * @param string $resourceid The namespaced resource id.
     * @return bool True when a deletion was marked.
     */
    public function remove_resource(string $resourceid): bool {
        $row = $this->repo->get($resourceid);
        if ($row === null) {
            return false;
        }

        if (!status::can_transition($row->status, status::PENDING_DELETION)) {
            return false;
        }

        // The teacher explicitly withdrew this owner's claim on the bit, so
        // release its own reference — otherwise the worker's orphan check
        // would see it and revert the deletion. References held by other
        // owners still protect a shared bit.
        $this->refs->remove($resourceid, $row->component, (int) $row->itemid);

        $this->repo->set_status($resourceid, status::PENDING_DELETION);
        return true;
    }

    /**
     * Retry a single resource stuck in the error state.
     *
     * @param string $resourceid The namespaced resource id.
     * @return bool True when a retry was marked.
     */
    public function retry_resource(string $resourceid): bool {
        $row = $this->repo->get($resourceid);
        if ($row === null || $row->status !== status::ERROR) {
            return false;
        }

        $this->repo->reset_attempts($resourceid);
        $this->repo->set_status($resourceid, status::PENDING);
        return true;
    }

    /**
     * Run the pending work of a resource synchronously, reverting on failure.
     *
     * This is the execution half of every teacher action: the row was just
     * marked (pending / pendingreindex / pendingdeletion) and the API call
     * happens here, in the acting request. When a guardrail or the transport
     * refuses, the row returns to its previous stable state — there is no
     * queue to pick it up later, so it must never be left mid-flight — and
     * the failure propagates so the caller can show it.
     *
     * @param string $resourceid The namespaced resource id.
     * @return void
     * @throws blocked_exception When a guardrail refuses the call.
     * @throws transport_exception When the call fails at the transport level.
     */
    public function execute_now(string $resourceid): void {
        $row = $this->repo->get($resourceid);
        if ($row === null) {
            return;
        }

        try {
            $this->tracker->process($row);
        } catch (blocked_exception | transport_exception $e) {
            // Re-read: a re-index advances the row to pending once the old
            // content is untracked, and the revert must act on where the
            // sequence actually failed, not on where it started.
            $this->revert($this->repo->get($resourceid) ?? $row);
            throw $e;
        }
    }

    /**
     * Return a row that failed mid-action to its previous stable state.
     *
     * @param \stdClass $row The row, still carrying its transient status.
     * @return void
     */
    private function revert(\stdClass $row): void {
        switch ($row->status) {
            case status::PENDING:
                // A first upload failed: back to square one, Index reappears.
                $this->repo->set_status($row->resourceid, status::NOT_INDEXED);
                break;
            case status::PENDING_REINDEX:
                // The old content is still live in the brain; keep the old
                // hash so the change stays detectable.
                $this->repo->set_status($row->resourceid, status::INDEXED);
                break;
            case status::PENDING_DELETION:
                // The bit is still in the brain: restore this owner's claim.
                $this->refs->add($row->resourceid, $row->component, (int) $row->itemid, (int) $row->contextid);
                $this->repo->set_status($row->resourceid, status::INDEXED);
                break;
            default:
                break;
        }
    }

    /**
     * Resolve a module and decide each material.
     *
     * @param \cm_info $cm          The course module.
     * @param bool     $onlytracked When true, ignore materials with no index row.
     * @return string[] The resource ids that were marked.
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
     * Decide and mark a single material.
     *
     * @param material $material    The extracted material.
     * @param bool     $onlytracked When true, skip materials that have no row yet.
     * @return bool True when an indexation call was marked.
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
