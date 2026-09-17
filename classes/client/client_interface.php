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

    /** @var string[] Question widget types the practice mode supports. */
    public const QUESTION_WIDGET_TYPES = [
        'singleChoiceQuestionWidget',
        'trueFalseQuestionWidget',
        'openQuestionWidget',
    ];

    /**
     * Grounded retrieval: answer a query from tracked brain content.
     *
     * Retrieval is scoped by an explicit list of resource ids: only those may
     * ground the answer. The brain treats an empty list as "no restriction",
     * which would leak other courses' content on the shared brain — so callers
     * must refuse locally instead of ever passing an empty list.
     *
     * @param string   $query              The learner question.
     * @param string   $userid             Pseudonymised user id (analytics; never a real identity).
     * @param string[] $allowedresourceids Non-empty list of resource ids allowed to ground the answer.
     * @return array Result with 'answer', 'answered', 'sources' and 'credits'.
     */
    public function search_grounded(string $query, string $userid, array $allowedresourceids): array;

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
     * Ask the activity chat (t0 surface) and return the assistant's answer.
     *
     * Unlike {@see search_grounded()} this is a conversation: the brain keeps
     * dialogue memory per (context_id, user_id), so each call only carries the
     * new question. Retrieval is scoped by 'resourceids' with full brain
     * access off — verified live: an out-of-scope question is refused, and
     * with full access on the scope list is ignored entirely. An empty scope
     * must never reach the API; callers refuse locally.
     *
     * Expected $params keys: contextid, contextname, contextdescription,
     * robotid, robotname, robotdescription, userid, username, userlanguage,
     * resourceids (non-empty string[]), query, and the activity's didactic
     * settings: conversationstyle (int 1-5), dqr (int 3-7), industry
     * (string, may be empty), fulltextsearch (bool).
     *
     * @param array $params The conversation parameters, as above.
     * @return array Result with 'answer' (markdown), 'messages' (markdown
     *               strings from informational widgets, e.g. the welcome on a
     *               conversation's first call) and 'credits'.
     */
    public function generate_widgets(array $params): array;

    /**
     * Generate the next practice question widget (Test knowledge mode).
     *
     * Same endpoint and scope rules as {@see generate_widgets()}, but with
     * state 'practice': instead of answering a question, the brain generates
     * the next question for the learner. Which types it may generate is
     * steered by 'questiontypes' — a map of difficulty grade (1 Einfach,
     * 2 Mittel, 3 Schwer) to widget type names; a grade absent from the map
     * takes no part, which is how difficulty is selected (it has no API
     * parameter of its own). 'taxonomylevels' narrows the Bloom levels
     * (1 Wissen, 2 Verstehen, 3 Anwenden).
     *
     * Expected $params keys: those of {@see generate_widgets()} minus query,
     * plus taxonomylevels (int[]) and questiontypes (array<int, string[]>,
     * values from {@see QUESTION_WIDGET_TYPES}).
     *
     * @param array $params The generation parameters, as above.
     * @return array Result with 'widget' (null when the brain sent no
     *               question, else {id, type, question, choices: string[],
     *               taxonomylevel, difficultylevel}, with correctness
     *               information stripped), 'progress' ({earnedxp, totalxp,
     *               practiceprogress}) and 'credits'.
     */
    public function generate_practice_widget(array $params): array;

    /**
     * Evaluate a learner's answer to a practice question widget.
     *
     * Routes to the per-type evaluate endpoint. The response reveals the
     * correctness information the generation call withheld.
     *
     * Expected $params keys: contextid, contextname, contextdescription,
     * robotid, robotname, robotdescription, userid, username, userlanguage,
     * resourceids (non-empty string[]), taxonomylevels (int[]), widgetid,
     * widgettype (one of {@see QUESTION_WIDGET_TYPES}), useranswer (string).
     *
     * @param array $params The evaluation parameters, as above.
     * @return array Result with 'correct' (bool, or null when the endpoint
     *               gave no verdict — open questions), 'feedback' (markdown),
     *               'answer' (the model answer, may be empty), 'choices'
     *               (list of {text, iscorrect, clarification}), 'progress'
     *               ({earnedxp, totalxp, practiceprogress}) and 'credits'.
     */
    public function evaluate_practice_answer(array $params): array;

    /**
     * Read a conversation back from the brain, oldest first.
     *
     * The brain is the only store of the dialogue, so this is what a chat
     * page opens with. Takes the same scope rules as
     * {@see generate_widgets()}: the t0 surface requires resourceids even to
     * read, and callers refuse locally on an empty scope.
     *
     * Expected $params keys: contextid, userid, resourceids (non-empty
     * string[]), offset (int), pagesize (int).
     *
     * @param array $params The retrieval parameters, as above.
     * @return array Result with 'turns' — a list of
     *               {query: string (empty for informational turns),
     *                answer: markdown string, timecreated: int} — and 'credits'.
     */
    public function retrieve_widgets(array $params): array;

    /**
     * Erase one user's conversation in one activity context.
     *
     * The brain forgets both the transcript and its dialogue memory: the
     * next question starts a fresh conversation (and is greeted again).
     *
     * @param string $contextid The conversation context id.
     * @param string $userid    Pseudonymised user id.
     * @return void
     */
    public function reset_conversation(string $contextid, string $userid): void;

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
