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

use local_tarsus\client\client_factory;
use local_tarsus\client\client_interface;
use local_tarsus\client\transport_exception;
use local_tarsus\guardrail\blocked_exception;
use local_tarsus\guardrail\pipeline;
use local_tarsus\material\resolver;

/**
 * Reconciles a set of local index rows against the brain in one batch call.
 *
 * The brain exposes a plural check that reports up to
 * {@see client_interface::BATCH_LIMIT} resources at a time, which is why a page
 * of the teacher reconciliation list holds the same number: one page of
 * material costs exactly one API call instead of one call per row.
 *
 * Only the local index map is written; the brain is never mutated here.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status_sync {

    /** @var string[] Statuses worth asking the brain about. */
    private const SYNCABLE = [
        status::NOT_INDEXED,
        status::PENDING,
        status::PENDING_CONFIRMATION,
        status::PENDING_REINDEX,
        status::INDEXED,
        status::ERROR,
    ];

    /** @var client_interface The active Tarsus client. */
    private client_interface $client;

    /**
     * Constructor.
     *
     * @param repository|null       $repo     Index map repository.
     * @param pipeline|null         $pipeline Guardrails pipeline.
     * @param resolver|null         $resolver Material resolver (for the content hash).
     * @param client_interface|null $client   Tarsus client (defaults to the configured one).
     */
    public function __construct(
        private ?repository $repo = null,
        private ?pipeline $pipeline = null,
        private ?resolver $resolver = null,
        ?client_interface $client = null
    ) {
        $this->repo ??= new repository();
        $this->pipeline ??= new pipeline();
        $this->resolver ??= new resolver();
        $this->client = $client ?? client_factory::get_client();
    }

    /**
     * Ask the brain about the given resources and apply the answer locally.
     *
     * Rows awaiting deletion are filtered out: their status is ours to decide,
     * not the brain's. Everything else on a page is asked about in one call,
     * since the batch costs the same for one id as for ten.
     *
     * @param string[] $resourceids Namespaced resource ids.
     * @return array<string, string> The resource ids whose local status changed,
     *                               mapped to their new status.
     * @throws blocked_exception When a guardrail refuses the call.
     * @throws transport_exception When the call fails at the transport level.
     */
    public function refresh(array $resourceids): array {
        $rows = $this->syncable_rows($resourceids);
        if ($rows === []) {
            return [];
        }

        $checked = $this->pipeline->execute(
            fn() => $this->client->check_tracked_resources(array_keys($rows))
        );

        $changed = [];
        foreach ($rows as $resourceid => $row) {
            $reported = $checked[$resourceid]['status'] ?? null;
            if ($reported === null || $reported === $row->status) {
                continue;
            }
            if ($this->apply($row, $reported)) {
                $changed[$resourceid] = $reported;
            }
        }

        return $changed;
    }

    /**
     * Load the rows that are in a state the brain can report on.
     *
     * @param string[] $resourceids Namespaced resource ids.
     * @return array<string, \stdClass> Rows keyed by resource id.
     */
    private function syncable_rows(array $resourceids): array {
        $rows = [];

        foreach (array_unique($resourceids) as $resourceid) {
            $row = $this->repo->get((string) $resourceid);
            if ($row !== null && in_array($row->status, self::SYNCABLE, true)) {
                $rows[$row->resourceid] = $row;
            }
        }

        return $rows;
    }

    /**
     * Move one row to the status the brain reported, when that is legal.
     *
     * @param \stdClass $row      The current index row.
     * @param string    $reported The status reported by the brain.
     * @return bool True when the row was written.
     */
    private function apply(\stdClass $row, string $reported): bool {
        if (!status::can_transition($row->status, $reported)) {
            return false;
        }

        if ($reported === status::INDEXED) {
            $material = $this->resolver->resolve_by_resource_id($row->resourceid);
            $hash = $material ? $material->content_hash() : ($row->contenthash ?: sha1($row->resourceid));
            $this->repo->mark_indexed($row->resourceid, (string) ($row->bitref ?? ''), $hash);
            return true;
        }

        $this->repo->set_status($row->resourceid, $reported);

        return true;
    }
}
