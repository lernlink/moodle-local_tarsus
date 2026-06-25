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
 * Stable pseudonymization of Moodle user ids for transmission to Tarsus.
 *
 * Tarsus never receives names or emails — only a stable HMAC of the Moodle
 * user id. The HMAC secret is generated once and stored in plugin config
 * (never hardcoded); rotating it invalidates existing brain references, so
 * rotation must be a deliberate, logged operation.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pseudonymizer {

    /** @var string Config name holding the HMAC secret. */
    private const SECRET_SETTING = 'hmac_secret';

    /**
     * Return the stable pseudonym for a Moodle user id.
     *
     * @param int $userid The Moodle user id.
     * @return string Hex-encoded HMAC-SHA256 pseudonym.
     */
    public static function pseudonymize(int $userid): string {
        return hash_hmac('sha256', (string) $userid, self::secret());
    }

    /**
     * Fetch the HMAC secret, generating and storing it on first use.
     *
     * @return string The secret.
     */
    private static function secret(): string {
        $secret = get_config('local_tarsus', self::SECRET_SETTING);

        if ($secret === false || $secret === '') {
            $secret = bin2hex(random_bytes(32));
            set_config(self::SECRET_SETTING, $secret, 'local_tarsus');
        }

        return $secret;
    }
}
