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

namespace local_tarsus\guardrail;

use local_tarsus\client\transport_exception;

/**
 * Tests for the guardrails pipeline.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\guardrail\pipeline
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pipeline_test extends \advanced_testcase {
    /**
     * A successful call returns its result and resets the breaker.
     *
     * @return void
     */
    public function test_success_passes_through(): void {
        $this->resetAfterTest(true);

        $pipeline = new pipeline();
        $result = $pipeline->execute(fn() => ['answer' => 'ok', 'credits' => 6]);

        $this->assertSame('ok', $result['answer']);
    }

    /**
     * The kill-switch blocks calls immediately.
     *
     * @return void
     */
    public function test_killswitch_blocks(): void {
        $this->resetAfterTest(true);
        set_config('killswitch', 1, 'local_tarsus');

        $pipeline = new pipeline();

        try {
            $pipeline->execute(fn() => ['credits' => 0]);
            $this->fail('Expected blocked_exception.');
        } catch (blocked_exception $e) {
            $this->assertSame('killswitch', $e->reason);
        }
    }

    /**
     * The budget gate refuses a call that would overspend.
     *
     * @return void
     */
    public function test_budget_blocks_when_exceeded(): void {
        $this->resetAfterTest(true);
        set_config('creditbudget', 10, 'local_tarsus');

        $pipeline = new pipeline();

        try {
            $pipeline->execute(fn() => ['credits' => 0], 50);
            $this->fail('Expected blocked_exception.');
        } catch (blocked_exception $e) {
            $this->assertSame('budget', $e->reason);
        }
    }

    /**
     * The rate limiter refuses once the bucket is empty.
     *
     * @return void
     */
    public function test_ratelimit_blocks_when_bucket_empty(): void {
        $this->resetAfterTest(true);
        set_config('ratelimit', 1, 'local_tarsus');

        $pipeline = new pipeline();

        // First call consumes the only token.
        $pipeline->execute(fn() => ['credits' => 0]);

        try {
            $pipeline->execute(fn() => ['credits' => 0]);
            $this->fail('Expected blocked_exception.');
        } catch (blocked_exception $e) {
            $this->assertSame('ratelimit', $e->reason);
        }
    }

    /**
     * Repeated transport failures trip the breaker, then calls fail fast.
     *
     * @return void
     */
    public function test_breaker_opens_after_threshold(): void {
        $this->resetAfterTest(true);
        // Plenty of tokens and budget so only the breaker can block.
        set_config('ratelimit', 1000, 'local_tarsus');

        $pipeline = new pipeline();
        $failing = function () {
            throw new transport_exception(500, 'boom');
        };

        for ($i = 0; $i < pipeline::FAILURE_THRESHOLD; $i++) {
            try {
                $pipeline->execute($failing);
                $this->fail('Expected transport_exception.');
            } catch (transport_exception $e) {
                $this->assertSame(500, $e->httpstatus);
            }
        }

        // Breaker is now open: the next call fails fast.
        try {
            $pipeline->execute(fn() => ['credits' => 0]);
            $this->fail('Expected blocked_exception.');
        } catch (blocked_exception $e) {
            $this->assertSame('breaker', $e->reason);
        }
    }

    /**
     * A success after failures resets the failure counter.
     *
     * @return void
     */
    public function test_success_resets_failure_counter(): void {
        $this->resetAfterTest(true);
        set_config('ratelimit', 1000, 'local_tarsus');

        $pipeline = new pipeline();

        // One failure, then a success.
        try {
            $pipeline->execute(function () {
                throw new transport_exception(503, 'temporary');
            });
        } catch (transport_exception $e) {
            $this->assertSame(503, $e->httpstatus);
        }
        $pipeline->execute(fn() => ['credits' => 0]);

        $row = (new state_store())->get('breaker');
        $this->assertSame(0, (int) $row->failurecount);
        $this->assertSame('closed', $row->state);
    }
}
