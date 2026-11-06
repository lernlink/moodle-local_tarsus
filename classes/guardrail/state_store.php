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

/**
 * Persistent guardrail state with locked read-modify-write.
 *
 * The breaker / token-bucket / budget counters are shared mutable state.
 * Concurrent CRON workers must not corrupt them, so every mutation runs
 * inside a named Moodle lock — the safeguard the prior plugin lacked.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state_store {
    /** @var string Lock factory type for guardrail state. */
    private const LOCK_TYPE = 'local_tarsus_guardrail';

    /** @var int Seconds to wait for a guardrail lock. */
    private const LOCK_TIMEOUT = 10;

    /**
     * Fetch a guardrail row, creating it with defaults if absent.
     *
     * @param string $name The guardrail name (breaker, budget, bucket).
     * @return \stdClass The row.
     */
    public function get(string $name): \stdClass {
        global $DB;

        $row = $DB->get_record('local_tarsus_guardrail', ['name' => $name]);
        if (!$row) {
            $row = $this->default_row($name);
            $row->id = $DB->insert_record('local_tarsus_guardrail', $row);
        }

        return $row;
    }

    /**
     * Read, mutate and persist a guardrail row under a lock.
     *
     * The callback receives the row by reference and mutates it in place. If
     * the callback throws, the row is left unchanged (and the lock released).
     *
     * @param string   $name     The guardrail name.
     * @param callable $mutator  Callback: function(\stdClass $row): void.
     * @return \stdClass The persisted row.
     * @throws \moodle_exception When the lock cannot be acquired.
     */
    public function mutate(string $name, callable $mutator): \stdClass {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock($name, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('lockfailed', 'local_tarsus');
        }

        try {
            $row = $this->get($name);
            $mutator($row);
            $row->timemodified = time();
            $DB->update_record('local_tarsus_guardrail', $row);
            return $row;
        } finally {
            $lock->release();
        }
    }

    /**
     * Build a default row for a guardrail.
     *
     * @param string $name The guardrail name.
     * @return \stdClass
     */
    private function default_row(string $name): \stdClass {
        return (object) [
            'name' => $name,
            'state' => 'closed',
            'failurecount' => 0,
            'opensince' => 0,
            'tokens' => 0,
            'lastrefill' => 0,
            'creditsused' => 0,
            'periodstart' => 0,
            'timemodified' => time(),
        ];
    }
}
