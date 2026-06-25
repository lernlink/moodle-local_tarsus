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

namespace local_tarsus\client;

/**
 * Real Tarsus HTTP client — SKELETON ONLY.
 *
 * Intentionally unimplemented. The live Tarsus API is not yet available, so
 * every method throws. When access is granted this is the single class to
 * wire up (spec §16 resolves the wire format); nothing else changes.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_client implements client_interface {

    /**
     * Constructor.
     *
     * @param string $baseurl Tarsus base URL.
     * @param string $apikey  Tarsus API key.
     * @param string $brainid Configured brain id (one per client).
     */
    public function __construct(
        protected string $baseurl,
        protected string $apikey,
        protected string $brainid
    ) {
    }

    /**
     * Guard against accidental use before the API is wired.
     *
     * @return never
     * @throws \coding_exception
     */
    protected function not_implemented(): never {
        // TODO: wire to Tarsus API once access is granted (spec §16).
        throw new \coding_exception('http_client is a skeleton; enable mock mode until the Tarsus API is wired.');
    }

    #[\Override]
    public function search_grounded(string $contextid, string $query): array {
        $this->not_implemented();
    }

    #[\Override]
    public function track_resource(array $resource): array {
        $this->not_implemented();
    }

    #[\Override]
    public function untrack_resource(array $resourceids): array {
        $this->not_implemented();
    }

    #[\Override]
    public function get_resource_status(string $resourceid): array {
        $this->not_implemented();
    }

    #[\Override]
    public function generate_widgets(string $contextid, array $params): array {
        $this->not_implemented();
    }

    #[\Override]
    public function evaluate_answer(string $contextid, array $params): array {
        $this->not_implemented();
    }

    #[\Override]
    public function get_usage(): array {
        $this->not_implemented();
    }
}
