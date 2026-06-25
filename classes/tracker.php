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

/**
 * Orchestrates one unit of indexation work against the brain.
 *
 * The tasks are thin: they pick due rows and hand each to this service, which
 * runs the right client call through the guardrails pipeline and advances the
 * index state machine. All credit spend is metered; all transport failures
 * feed the retry/backoff budget; guardrail blocks simply leave the row for a
 * later run (the queue holds).
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
     * @param client_interface|null $client  Tarsus client (defaults to the configured one).
     */
    public function __construct(
        private ?repository $repo = null,
        private ?reference_store $refs = null,
        private ?pipeline $pipeline = null,
        private ?usage_meter $usage = null,
        ?client_interface $client = null
    ) {
        $this->repo ??= new repository();
        $this->refs ??= new reference_store();
        $this->pipeline ??= new pipeline();
        $this->usage ??= new usage_meter();
        $this->client = $client ?? client_factory::get_client();
    }

    /**
     * Route a due row to the handler for its status.
     *
     * @param \stdClass $row An index map row.
     * @return void
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
     * Re-index relies on add-before-remove on the brain side; the old bit is
     * only untracked once the new content confirms as indexed.
     *
     * @param \stdClass $row An index map row.
     * @return void
     */
    private function process_upload(\stdClass $row): void {
        try {
            $result = $this->pipeline->execute(
                fn() => $this->client->track_resource([
                    'resource_id' => $row->resourceid,
                    'contextid' => (int) $row->contextid,
                ])
            );
        } catch (blocked_exception $e) {
            return; // Guardrail held the call; retry on a later run.
        } catch (transport_exception $e) {
            $this->repo->record_failure($row->resourceid);
            return;
        }

        $this->usage->record((int) $row->contextid, 0, 'track', (int) ($result['credits'] ?? 0));

        $hash = $row->contenthash ?: sha1($row->resourceid);
        $bitref = (string) ($result['bitref'] ?? '');

        if (($result['status'] ?? '') === status::INDEXED) {
            $this->repo->mark_indexed($row->resourceid, $bitref, $hash);
        } else {
            $this->repo->reset_attempts($row->resourceid);
            $this->repo->set_status($row->resourceid, status::PENDING_CONFIRMATION);
        }
    }

    /**
     * Poll for async indexing confirmation.
     *
     * @param \stdClass $row An index map row.
     * @return void
     */
    private function process_confirmation(\stdClass $row): void {
        try {
            $result = $this->pipeline->execute(
                fn() => $this->client->get_resource_status($row->resourceid)
            );
        } catch (blocked_exception $e) {
            return;
        } catch (transport_exception $e) {
            $this->repo->record_failure($row->resourceid);
            return;
        }

        if (($result['status'] ?? '') === status::INDEXED) {
            $hash = $row->contenthash ?: sha1($row->resourceid);
            $bitref = (string) ($result['bitref'] ?? $row->bitref ?? '');
            $this->repo->mark_indexed($row->resourceid, $bitref, $hash);
        } else {
            // Not confirmed yet: poll again with backoff; error when exhausted.
            $this->repo->record_failure($row->resourceid);
        }
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

        try {
            $this->pipeline->execute(
                fn() => $this->client->untrack_resource([$row->resourceid])
            );
        } catch (blocked_exception $e) {
            return;
        } catch (transport_exception $e) {
            $this->repo->record_failure($row->resourceid);
            return;
        }

        $this->repo->delete($row->resourceid);
    }
}
