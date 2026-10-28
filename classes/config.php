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

/**
 * Typed access to local_tarsus settings.
 *
 * The kill-switch and other operational flags are read directly from the
 * database (bypassing the per-request config cache) so that flipping a flag
 * mid-run — e.g. during a long CRON worker — takes effect immediately.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {

    /** @var int Default outbound calls per minute. */
    public const DEFAULT_RATE_LIMIT = 60;

    /** @var int Default aiCredit budget per period. */
    public const DEFAULT_CREDIT_BUDGET = 100000;

    /**
     * Read a plugin setting fresh from the database (no config cache).
     *
     * @param string $name The setting name.
     * @return string|false The raw value, or false if unset.
     */
    protected function fresh(string $name) {
        global $DB;
        return $DB->get_field('config_plugins', 'value', [
            'plugin' => 'local_tarsus',
            'name' => $name,
        ]);
    }

    /**
     * Whether the kill-switch is engaged (block all outbound calls).
     *
     * @return bool
     */
    public function killswitch_enabled(): bool {
        return (string) $this->fresh('killswitch') === '1';
    }

    /**
     * Whether mock mode is active.
     *
     * @return bool
     */
    public function mock_enabled(): bool {
        $value = $this->fresh('mockmode');
        return $value === false || (string) $value === '1';
    }

    /**
     * Maximum outbound calls per minute (token-bucket capacity).
     *
     * @return int
     */
    public function rate_limit(): int {
        $value = $this->fresh('ratelimit');
        return $value === false ? self::DEFAULT_RATE_LIMIT : max(1, (int) $value);
    }

    /**
     * Maximum aiCredits spendable per period.
     *
     * @return int
     */
    public function credit_budget(): int {
        $value = $this->fresh('creditbudget');
        return $value === false ? self::DEFAULT_CREDIT_BUDGET : max(0, (int) $value);
    }

    /**
     * URL of the assistant avatar uploaded in the admin settings.
     *
     * @return string The pluginfile URL, or '' when no avatar is uploaded.
     */
    public static function robot_avatar_url(): string {
        $context = \context_system::instance();
        $files = get_file_storage()->get_area_files(
            $context->id, 'local_tarsus', 'robotavatar', 0, 'itemid, filepath, filename', false
        );

        foreach ($files as $file) {
            return \moodle_url::make_pluginfile_url(
                $context->id, 'local_tarsus', 'robotavatar', null,
                $file->get_filepath(), $file->get_filename()
            )->out(false);
        }

        return '';
    }
}
