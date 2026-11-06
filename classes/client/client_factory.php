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

namespace local_tarsus\client;

/**
 * Returns the active Tarsus client (mock or real) per site configuration.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_factory {
    /**
     * Build the client for the current configuration.
     *
     * Defaults to the mock when mockmode is unset (e.g. fresh install), so no
     * code path can reach a live endpoint by accident.
     *
     * @return client_interface
     */
    public static function get_client(): client_interface {
        $mockmode = get_config('local_tarsus', 'mockmode');

        if ($mockmode === false || (string) $mockmode === '1') {
            return new mock_client();
        }

        $baseurl = (string) get_config('local_tarsus', 'base_url');
        $apikey  = (string) get_config('local_tarsus', 'api_key');
        $brainid = (string) get_config('local_tarsus', 'brain_id');

        return new http_client($baseurl, $apikey, $brainid);
    }
}
