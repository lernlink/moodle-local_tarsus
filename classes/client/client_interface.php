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
 * Stable Tarsus client contract.
 *
 * Everything outside local_tarsus depends on this interface, never on a
 * concrete client. A deterministic mock backs it until the live API is
 * granted, at which point only {@see http_client} is swapped in.
 *
 * Signatures are indicative; final wire details are an open item (spec §16)
 * that affects only the implementations, not this contract.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface client_interface {

    /** @var int Maximum resource ids accepted by one batch status check. */
    public const BATCH_LIMIT = 10;

    /**
     * Grounded retrieval: answer a query from tracked brain content.
     *
     * @param string $contextid Namespaced context scoping retrieval.
     * @param string $query     The learner question.
     * @return array Result with at least 'answer' and 'sources'.
     */
    public function search_grounded(string $contextid, string $query): array;

    /**
     * Track (index) a resource into the brain.
     *
     * @param array $resource Resource descriptor (type, payload, resource_id, contextid).
     * @return array Result with at least 'status' and optional 'bitref'.
     */
    public function track_resource(array $resource): array;

    /**
     * Untrack (remove) one or more resources from the brain.
     *
     * @param string[] $resourceids Namespaced resource ids to remove.
     * @return array Per-resource removal result.
     */
    public function untrack_resource(array $resourceids): array;

    /**
     * Untrack every resource filed under the given assets.
     *
     * The plugin files each bit under its course context id, so one asset is
     * one course: deleting a course costs a single call instead of one per
     * resource.
     *
     * @param string[] $assetids Asset ids (course context ids) to clear.
     * @return array Per-asset removal result.
     */
    public function untrack_assets(array $assetids): array;

    /**
     * Read the brain-side status of a tracked resource.
     *
     * @param string $resourceid Namespaced resource id.
     * @return array Result with at least 'status'.
     */
    public function get_resource_status(string $resourceid): array;

    /**
     * Read the brain-side status of many resources in as few calls as possible.
     *
     * Implementations chunk the input by {@see self::BATCH_LIMIT}, so callers
     * may pass any number of ids without knowing the wire limit.
     *
     * @param string[] $resourceids Namespaced resource ids.
     * @return array<string, array{status: string, tracked: bool, brainstatus: string}>
     *         Keyed by resource id; ids the brain does not report are omitted.
     */
    public function check_tracked_resources(array $resourceids): array;

    /**
     * List every brain the configured API key can reach.
     *
     * Deliberately not scoped to the configured brain: an admin must be able to
     * discover the available brains before choosing one.
     *
     * @return array<int, array{brainid: string, active: bool, language: string, tag: string, timecreated: int}>
     */
    public function list_brains(): array;

    /**
     * Generate learning widgets (tests / stacks). Phase 2.
     *
     * @param string $contextid Namespaced context.
     * @param array  $params    Generation parameters.
     * @return array Generated widgets.
     */
    public function generate_widgets(string $contextid, array $params): array;

    /**
     * Evaluate a learner answer against the material. Phase 2.
     *
     * @param string $contextid Namespaced context.
     * @param array  $params    Evaluation parameters.
     * @return array Evaluation result.
     */
    public function evaluate_answer(string $contextid, array $params): array;

    /**
     * Read aggregate usage. Phase 2.
     *
     * @return array Usage figures.
     */
    public function get_usage(): array;
}
