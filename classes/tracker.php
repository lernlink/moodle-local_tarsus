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

use local_tarsus\client\client_factory;
use local_tarsus\client\client_interface;
use local_tarsus\client\transport_exception;
use local_tarsus\guardrail\blocked_exception;
use local_tarsus\guardrail\pipeline;
use local_tarsus\index\repository;
use local_tarsus\index\reference_store;
use local_tarsus\index\status;
use local_tarsus\material\resolver;

/**
 * Orchestrates one unit of indexation work against the brain, synchronously.
 *
 * Runs the right client call through the guardrails pipeline and advances the
 * index state machine. All credit spend is metered. Failures are not absorbed:
 * a guardrail block or transport error propagates to the caller, which decides
 * how to present it and how to revert the row ({@see indexer::execute_now()}).
 * There is no background queue — every run happens in the acting request.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tracker {

    /** @var client_interface The active Tarsus client. */
    private client_interface $client;

    /**
     * Constructor.
     *
     * @param repository|null      $repo     Index map repository.
     * @param reference_store|null $refs     Resource reference store.
     * @param pipeline|null        $pipeline Guardrails pipeline.
     * @param usage_meter|null     $usage    Usage ledger.
     * @param resolver|null        $resolver Material resolver (re-extracts payloads at send time).
     * @param client_interface|null $client  Tarsus client (defaults to the configured one).
     */
    public function __construct(
        private ?repository $repo = null,
        private ?reference_store $refs = null,
        private ?pipeline $pipeline = null,
        private ?usage_meter $usage = null,
        private ?resolver $resolver = null,
        ?client_interface $client = null
    ) {
        $this->repo ??= new repository();
        $this->refs ??= new reference_store();
        $this->pipeline ??= new pipeline();
        $this->usage ??= new usage_meter();
        $this->resolver ??= new resolver();
        $this->client = $client ?? client_factory::get_client();
    }

    /**
     * Route a row to the handler for its status.
     *
     * @param \stdClass $row An index map row.
     * @return void
     * @throws blocked_exception When a guardrail refuses the call.
     * @throws transport_exception When the call fails at the transport level.
     */
    public function process(\stdClass $row): void {
        match ($row->status) {
            status::PENDING, status::PENDING_REINDEX => $this->process_upload($row),
            status::PENDING_CONFIRMATION => $this->process_confirmation($row),
            status::PENDING_DELETION => $this->process_deletion($row),
            default => null,
        };
    }

    /**
     * Upload (or re-upload) a resource and record the outcome.
     *
     * Tracking the same resource id twice does not replace the previous bits:
     * the brain keeps both, and only whole resources can be untracked. So the
     * upload is always preceded by an untrack, which clears any content the
     * brain still holds under this id — the previous version on a re-index, or
     * the remains of an upload whose response never reached us. Untracking an
     * id the brain does not know is a documented no-op.
     *
     * @param \stdClass $row An index map row.
     * @return void
     */
    private function process_upload(\stdClass $row): void {
        // Re-extract from current state so the upload carries what is on disk now.
        $material = $this->resolver->resolve_by_resource_id($row->resourceid);
        $payload = $material
            ? $material->to_payload()
            : ['resource_id' => $row->resourceid, 'context_id' => (string) $row->contextid];
        $hash = $material ? $material->content_hash() : ($row->contenthash ?: sha1($row->resourceid));

        $this->pipeline->execute(
            fn() => $this->client->untrack_resource([$row->resourceid])
        );

        // The brain now holds nothing under this id. Record that before the
        // upload: should it fail, the row must not keep claiming the previous
        // version is live.
        if ($row->status === status::PENDING_REINDEX) {
            $this->repo->set_status($row->resourceid, status::PENDING);
        }

        $result = $this->pipeline->execute(
            fn() => $this->client->track_resource($payload)
        );

        $this->usage->record((int) $row->contextid, 0, 'track', (int) ($result['credits'] ?? 0));

        $bitref = (string) ($result['bitref'] ?? '');

        if (($result['status'] ?? '') === status::INDEXED) {
            $this->repo->mark_indexed($row->resourceid, $bitref, $hash);
        } else {
            // Persist the hash of what was actually sent: confirmation must
            // compare against this, not against whatever the source contains
            // by the time the brain answers.
            $this->repo->mark_uploaded($row->resourceid, $hash);
        }
    }

    /**
     * Poll for async indexing confirmation.
     *
     * @param \stdClass $row An index map row.
     * @return void
     */
    private function process_confirmation(\stdClass $row): void {
        $result = $this->pipeline->execute(
            fn() => $this->client->get_resource_status($row->resourceid)
        );

        if (($result['status'] ?? '') === status::INDEXED) {
            // The upload persisted the hash of what was sent; recomputing it
            // here would silently absorb any edit made while the brain was
            // still ingesting the previous content.
            $hash = (string) $row->contenthash;
            $bitref = (string) ($result['bitref'] ?? $row->bitref ?? '');
            $this->repo->mark_indexed($row->resourceid, $bitref, $hash);
        } else if (($result['status'] ?? '') === status::NOT_INDEXED) {
            // The brain reports the resource as absent: waiting cannot change that.
            if (status::can_transition($row->status, status::NOT_INDEXED)) {
                $this->repo->set_status($row->resourceid, status::NOT_INDEXED);
            }
        }
        // Still ingesting: leave the row awaiting confirmation. The next page
        // sync (or an explicit action) will ask again — there is no poller.
    }

    /**
     * Untrack a resource from the shared brain — only once it is orphaned.
     *
     * @param \stdClass $row An index map row.
     * @return void
     */
    private function process_deletion(\stdClass $row): void {
        if (!$this->refs->safe_to_untrack($row->resourceid)) {
            // Another live owner still references it: keep it indexed.
            if (status::can_transition($row->status, status::INDEXED)) {
                $this->repo->set_status($row->resourceid, status::INDEXED);
            }
            return;
        }

        $this->pipeline->execute(
            fn() => $this->client->untrack_resource([$row->resourceid])
        );

        $this->repo->delete($row->resourceid);
    }
}
