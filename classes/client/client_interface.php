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
        'crossMultiplicationQuestionWidget',
        'tradeCostingQuestionWidget',
    ];

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
     * This is a conversation: the brain keeps dialogue memory per
     * (context_id, user_id), so each call only carries the new question. Retrieval is scoped by 'resourceids' with full brain
     * access off — verified live: an out-of-scope question is refused, and
     * with full access on the scope list is ignored entirely. An empty scope
     * must never reach the API; callers refuse locally.
     *
     * Expected $params keys: contextid, contextname, contextdescription,
     * robotid, robotname, robotdescription, userid, username, userlanguage,
     * resourceids (non-empty string[]), query, and the activity's didactic
     * settings: conversationstyle (int 1-5), dqr (int 3-7), industry
     * (string, may be empty), fulltextsearch (bool), operators ('standard'
     * or 'ihk'), anticheat (bool), learntempo (float 0.1-2).
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
     * 2 Mittel, 3 Schwer) to widget type names. The brain requires all
     * three grades, each with at least one type (verified live); difficulty
     * has no API parameter of its own. 'taxonomylevels' narrows the Bloom
     * levels (1 Wissen, 2 Verstehen, 3 Anwenden).
     *
     * Expected $params keys: those of {@see generate_widgets()} minus query,
     * plus taxonomylevels (int[]) and questiontypes (array<int, string[]>,
     * values from {@see QUESTION_WIDGET_TYPES}).
     *
     * One call may generate several questions (usually two); all of them
     * are returned in the order Tarsus sent them — how many appear per
     * step is its decision, not ours.
     *
     * @param array $params The generation parameters, as above.
     * @return array Result with 'widgets' (possibly empty list of {id,
     *               type, question, choices: string[], variables: string[]
     *               (the quantities a rule-of-three question asks for),
     *               table: list of {position, rate, amount, askrate,
     *               askamount} (the costing table of a spreadsheet
     *               calculation, '?' marking asked cells), columns: string[]
     *               (its three column names as the brain localised them,
     *               to send back with the answer), taxonomylevel,
     *               difficultylevel}, with correctness information
     *               stripped), 'progress' ({earnedxp, totalxp,
     *               practiceprogress}) and 'credits'.
     */
    public function generate_practice_widget(array $params): array;

    /**
     * Generate the next learning unit (Build knowledge mode).
     *
     * Same endpoint and scope rules as {@see generate_practice_widget()},
     * with state 'learn': the first call yields the learning objectives, each
     * further call one micro-learning stack of slides. Expected $params keys:
     * those of {@see generate_practice_widget()} plus learningmethod (string,
     * e.g. 'micro-learning') and gif (bool).
     *
     * @param array $params The generation parameters, as above.
     * @return array Result with 'items' — {kind: 'objectives', id, title,
     *               message, objectives: list of {id, objective,
     *               taxonomylevel, done}} or {kind: 'stack', id, slides: list
     *               of {id, title, text (markdown), image (data URI or ''),
     *               imageposition}} — 'progress' and 'credits'.
     */
    public function generate_learn_widget(array $params): array;

    /**
     * Evaluate a learner's answer to a practice question widget.
     *
     * Routes to the per-type evaluate endpoint. The response reveals the
     * correctness information the generation call withheld.
     *
     * Expected $params keys: contextid, contextname, contextdescription,
     * robotid, robotname, robotdescription, userid, username, userlanguage,
     * resourceids (non-empty string[]), taxonomylevels (int[]), widgetid,
     * widgettype (one of {@see QUESTION_WIDGET_TYPES}), and the answer:
     * useranswer (string) for single-answer types, useranswers (list of
     * {variable, answer}) for a rule-of-three question, or usertable (list
     * of {position, rate, amount}, every row of the costing table with the
     * asked cells filled) plus columns (string[3], the names the question
     * came with) for a spreadsheet calculation.
     *
     * @param array $params The evaluation parameters, as above.
     * @return array Result with 'correct' (bool, or null when the endpoint
     *               gave no verdict), 'score' (float 0-100, or null; partial
     *               credit shows as a fraction), 'feedback' (markdown), 'answer' (the
     *               model answer, may be empty), 'choices' (list of {text,
     *               iscorrect, clarification}), 'answers' (list of
     *               {variable, answer, iscorrect, clarification} — the model
     *               answers of a rule-of-three question), 'usertable' and
     *               'answertable' (the learner's and the model costing
     *               rows, the latter with rateverdict/amountverdict 1, -1
     *               or 0 for given cells), 'progress' ({earnedxp, totalxp,
     *               practiceprogress}) and 'credits'.
     */
    public function evaluate_practice_answer(array $params): array;

    /**
     * Read the whole activity feed back from the brain, oldest first.
     *
     * The brain is the only store of the interaction history, and it keeps
     * one interleaved feed across the interaction modes (the state filter is
     * not applied on retrieval — verified live), so this is what an activity
     * page opens with. Chat turns and practice questions come back in their
     * original order: answered questions carry their verdict, unanswered
     * ones stay answerable with correctness stripped, as in
     * {@see generate_practice_widget()}. The response also carries the
     * user's experience figures. Same scope rules as
     * {@see generate_widgets()}: the t0 surface requires resourceids even
     * to read, and callers refuse locally on an empty scope.
     *
     * Expected $params keys: contextid, userid, resourceids (non-empty
     * string[]), taxonomylevels (int[]), offset (int, counted from the
     * newest widget: pagesize reaches the page before the newest one),
     * pagesize (int).
     *
     * @param array $params The retrieval parameters, as above.
     * @return array Result with 'rawcount' (how many widgets the page held,
     *               a stack counting one per slide; a full page means an
     *               older page may follow — and a stack may straddle two
     *               pages, returned under the same id on both), 'items' — an ordered list where each item
     *               is one of {kind: 'message', text}, {kind: 'exchange',
     *               query, answer} or {kind: 'practice', id, type, question,
     *               choices: string[], variables: string[], taxonomylevel,
     *               difficultylevel, answered: bool, correct: ?bool,
     *               score: ?float, feedback, useranswer, useranswers: list of {variable,
     *               answer}, answer, answers: list of {variable, answer},
     *               table, usertable, answertable as in the generation and
     *               evaluation results}, or a learn item as returned by
     *               {@see generate_learn_widget()} —
     *               'progress' ({earnedxp, totalxp, practiceprogress}) and
     *               'credits'.
     */
    public function retrieve_feed(array $params): array;

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
