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

namespace local_tarsus\guardrail;

use local_tarsus\config;
use local_tarsus\client\transport_exception;

/**
 * Client-side guardrails pipeline for every outbound Tarsus call.
 *
 * Gate order (spec §6): kill-switch → circuit breaker → credit budget →
 * rate limit → send. A 2xx resets the failure counter; repeated transport
 * failures trip the breaker. Cross-attempt retry/backoff is the task layer's
 * job (nextretry / retrylimit) — this pipeline never sleeps.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pipeline {

    /** @var int Consecutive transport failures that trip the breaker. */
    public const FAILURE_THRESHOLD = 5;

    /** @var int Seconds the breaker stays open before a trial call. */
    public const BREAKER_COOLDOWN = 300;

    /** @var int Length of a credit-budget period in seconds. */
    public const BUDGET_PERIOD = DAYSECS;

    /** @var state_store Guardrail state persistence. */
    private state_store $store;

    /** @var config Settings access. */
    private config $config;

    /**
     * Constructor.
     *
     * @param state_store|null $store  Guardrail state store.
     * @param config|null      $config Settings access.
     */
    public function __construct(?state_store $store = null, ?config $config = null) {
        $this->store = $store ?? new state_store();
        $this->config = $config ?? new config();
    }

    /**
     * Run a guarded outbound call.
     *
     * @param callable $call             The outbound call: function(): mixed.
     * @param int      $estimatedcredits Credits the call is expected to spend.
     * @return mixed The call result.
     * @throws blocked_exception When a gate refuses the call.
     * @throws transport_exception When the call fails at the transport level.
     */
    public function execute(callable $call, int $estimatedcredits = 0) {
        $this->guard_killswitch();
        $this->guard_breaker();
        $this->guard_budget($estimatedcredits);
        $this->guard_ratelimit();

        try {
            $result = $call();
        } catch (transport_exception $e) {
            $this->record_failure();
            throw $e;
        }

        $credits = (is_array($result) && isset($result['credits'])) ? (int) $result['credits'] : 0;
        $this->record_success($credits);

        return $result;
    }

    /**
     * Abort immediately when the kill-switch is engaged.
     *
     * @return void
     * @throws blocked_exception
     */
    private function guard_killswitch(): void {
        if ($this->config->killswitch_enabled()) {
            throw new blocked_exception('killswitch');
        }
    }

    /**
     * Fail fast while the circuit breaker is open; reset after cooldown.
     *
     * @return void
     * @throws blocked_exception
     */
    private function guard_breaker(): void {
        $this->store->mutate('breaker', function (\stdClass $row): void {
            if ($row->state !== 'open') {
                return;
            }
            if (time() - (int) $row->opensince < self::BREAKER_COOLDOWN) {
                throw new blocked_exception('breaker');
            }
            // Cooldown elapsed: allow a trial call by closing the breaker.
            $row->state = 'closed';
            $row->failurecount = 0;
        });
    }

    /**
     * Refuse the call when it would exceed the credit budget for the period.
     *
     * @param int $estimatedcredits Credits the call is expected to spend.
     * @return void
     * @throws blocked_exception
     */
    private function guard_budget(int $estimatedcredits): void {
        $budget = $this->config->credit_budget();

        $row = $this->store->mutate('budget', function (\stdClass $row): void {
            $now = time();
            if ((int) $row->periodstart === 0
                    || $now - (int) $row->periodstart >= self::BUDGET_PERIOD) {
                $row->creditsused = 0;
                $row->periodstart = $now;
            }
        });

        if ((int) $row->creditsused + $estimatedcredits > $budget) {
            throw new blocked_exception('budget');
        }
    }

    /**
     * Consume a token from the refilling bucket, or refuse the call.
     *
     * @return void
     * @throws blocked_exception
     */
    private function guard_ratelimit(): void {
        $capacity = $this->config->rate_limit();
        $ratepersecond = $capacity / 60;

        $this->store->mutate('bucket', function (\stdClass $row) use ($capacity, $ratepersecond): void {
            $now = time();
            $last = (int) $row->lastrefill;
            $tokens = (float) $row->tokens;

            if ($last <= 0) {
                // First use: start with a full bucket.
                $tokens = $capacity;
                $last = $now;
            }

            $tokens = min((float) $capacity, $tokens + max(0, $now - $last) * $ratepersecond);
            $row->lastrefill = $now;

            if ($tokens < 1) {
                $row->tokens = $tokens;
                throw new blocked_exception('ratelimit');
            }

            $row->tokens = $tokens - 1;
        });
    }

    /**
     * Record a transport failure; trip the breaker at the threshold.
     *
     * @return void
     */
    private function record_failure(): void {
        $this->store->mutate('breaker', function (\stdClass $row): void {
            $row->failurecount = (int) $row->failurecount + 1;
            if ($row->failurecount >= self::FAILURE_THRESHOLD) {
                $row->state = 'open';
                $row->opensince = time();
            }
        });
    }

    /**
     * Record a success: reset the breaker and charge the budget.
     *
     * @param int $credits Credits actually spent by the call.
     * @return void
     */
    private function record_success(int $credits): void {
        $this->store->mutate('breaker', function (\stdClass $row): void {
            $row->failurecount = 0;
            $row->state = 'closed';
        });

        if ($credits > 0) {
            $this->store->mutate('budget', function (\stdClass $row) use ($credits): void {
                $row->creditsused = (int) $row->creditsused + $credits;
            });
        }
    }
}
