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

use local_tarsus\index\status;
use local_tarsus\material\bit_type;

/**
 * Real Tarsus (Flexudy Brain) HTTP client.
 *
 * Speaks the /api/v1/brain/access/* surface: track/{text,url,document,audio,
 * video}-bits, untrack/multiple-resources and check/tracked-resources. Every
 * call is form-data with api_key + brain_id; the brain ingests asynchronously,
 * so track only ever confirms receipt and the batch check drives the state
 * machine afterwards.
 *
 * All transport and protocol failures surface as {@see transport_exception} so
 * the guardrails pipeline and the retry/backoff budget stay in control.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_client implements client_interface {

    /** @var string Common prefix of every brain endpoint. */
    private const API_PREFIX = '/api/v1/brain/';

    /** @var int Seconds allowed for a plain JSON call. */
    private const TIMEOUT = 60;

    /** @var int Seconds allowed for a call that uploads a file. */
    private const UPLOAD_TIMEOUT = 300;

    /** @var int Seconds allowed to establish the connection. */
    private const CONNECT_TIMEOUT = 10;

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

    #[\Override]
    public function track_resource(array $resource): array {
        [$path, $params, $tempfile] = $this->build_track_request($resource);

        try {
            $body = $this->send('POST', $path, $params, $tempfile !== null);
        } finally {
            if ($tempfile !== null && file_exists($tempfile)) {
                @unlink($tempfile);
            }
        }

        // A missing ack is tolerated; only an explicit false means the brain
        // never got the bytes. HTTP 2xx already carries the real signal.
        if (isset($body['access']['received']) && $body['access']['received'] === false) {
            throw new transport_exception(0, 'brain did not acknowledge receipt of the resource');
        }

        // Ingestion is asynchronous: receipt is not indexation.
        return [
            'status' => status::PENDING_CONFIRMATION,
            'bitref' => '',
            'credits' => 0,
        ];
    }

    #[\Override]
    public function retrieve_feed(array $params): array {
        $body = $this->send('POST', '/api/t0/activity/retrieve/next/widgets', [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'user_id' => (string) ($params['userid'] ?? ''),
            'state' => 'ask',
            'offset' => (string) (int) ($params['offset'] ?? 0),
            'page_size' => (string) (int) ($params['pagesize'] ?? 50),
            'resource_ids' => json_encode(array_values($params['resourceids'] ?? [])),
            'taxonomy_levels' => json_encode(array_values($params['taxonomylevels'] ?? [])),
            // Selects WHICH page a long feed yields — the newest page_size
            // widgets, not the oldest — without affecting order; offset then
            // counts from the newest end, so offset=page_size is the page
            // before it (verified live).
            'newest_first' => 'true',
        ]);

        // Widgets arrive oldest first (verified with and without
        // newest_first), and the feed mixes every state regardless of the
        // 'state' parameter (verified live) — which is exactly what the
        // interleaved activity feed wants. Each widget becomes one item:
        // question widgets a 'practice' item (answered ones carrying their
        // verdict from data.evaluation, unanswered ones with correctness
        // stripped), answerWidgets an 'exchange', and bare-text widgets
        // (welcome and friends) a 'message'.
        $items = [];
        foreach (($body['activity']['widgets'] ?? []) as $widget) {
            $learn = self::learn_item($widget);
            if ($learn !== null) {
                $items[] = $learn;
                continue;
            }
            $bit = $widget['bit'] ?? [];
            $type = (string) ($bit['type'] ?? '');
            $data = (array) ($bit['data'] ?? []);

            if (in_array($type, self::QUESTION_WIDGET_TYPES, true)) {
                $items[] = self::question_item($widget, $type, $data);
                continue;
            }

            $answer = (string) ($data['answer'] ?? '');
            if ($answer !== '') {
                $items[] = [
                    'kind' => 'exchange',
                    'query' => (string) ($data['question'] ?? ''),
                    'answer' => $answer,
                ];
                continue;
            }

            $text = (string) ($bit['text'] ?? '');
            if ($text !== '') {
                $items[] = ['kind' => 'message', 'text' => $text];
            }
        }

        // The brain counts page_size in single widgets: a learning stack
        // weighs as many as it has slides, and a stack may even straddle
        // two pages (verified live: a three-slide stack came back as two
        // slides on the older page and one on the newer). Counting slides
        // here keeps the page-size test honest; the reader merges the
        // straddling halves by stack id.
        $rawcount = 0;
        foreach (($body['activity']['widgets'] ?? []) as $widget) {
            $rawcount += isset($widget['stackedWidgets']) && is_array($widget['stackedWidgets'])
                ? max(1, count($widget['stackedWidgets'])) : 1;
        }

        return [
            'items' => $items,
            // How many widgets the page held before classification: a full
            // page means an older one may follow.
            'rawcount' => $rawcount,
            // The activity level carries the user's experience figures.
            'progress' => self::extract_progress((array) ($body['activity'] ?? []), []),
            // Reading widgets back generates nothing.
            'credits' => 0,
        ];
    }

    #[\Override]
    public function reset_conversation(string $contextid, string $userid): void {
        $this->send('DELETE', '/api/t0/activity/reset/user/widgets', [
            'brain_id' => $this->brain(),
            'context_id' => $contextid,
            'context_type' => 'tarsus_activity',
            'user_id' => $userid,
        ]);
    }

    #[\Override]
    public function untrack_resource(array $resourceids): array {
        $result = [];

        foreach ($this->chunk($resourceids) as $chunk) {
            $body = $this->send('DELETE', 'access/untrack/multiple-resources', [
                'brain_id' => $this->brain(),
                'resource_ids' => json_encode(array_values($chunk)),
            ]);

            $untracked = $body['access']['untracked']['resource_ids'] ?? [];
            $untracked = is_array($untracked) ? array_flip($untracked) : [];

            foreach ($chunk as $resourceid) {
                $result[$resourceid] = isset($untracked[$resourceid]) ? 'removed' : 'unknown';
            }
        }

        return $result;
    }

    #[\Override]
    public function untrack_assets(array $assetids): array {
        $result = [];

        foreach ($this->chunk($assetids) as $chunk) {
            $body = $this->send('DELETE', 'access/untrack/multiple-assets', [
                'brain_id' => $this->brain(),
                'asset_ids' => json_encode(array_values($chunk)),
            ]);

            $untracked = $body['access']['untracked']['asset_ids'] ?? [];
            $untracked = is_array($untracked) ? array_flip($untracked) : [];

            foreach ($chunk as $assetid) {
                $result[$assetid] = isset($untracked[$assetid]) ? 'removed' : 'unknown';
            }
        }

        return $result;
    }

    #[\Override]
    public function get_resource_status(string $resourceid): array {
        // The plural endpoint reports a brain-side status; the singular one does not.
        $checked = $this->check_tracked_resources([$resourceid]);

        return $checked[$resourceid]
            ?? ['status' => status::PENDING_CONFIRMATION, 'tracked' => false, 'brainstatus' => ''];
    }

    #[\Override]
    public function check_tracked_resources(array $resourceids): array {
        $result = [];

        foreach ($this->chunk($resourceids) as $chunk) {
            $body = $this->send('GET', 'access/check/tracked-resources', [
                'brain_id' => $this->brain(),
                'resource_ids' => json_encode(array_values($chunk)),
            ]);

            foreach (($body['access'] ?? []) as $entry) {
                if (!is_array($entry) || !isset($entry['resourceId'])) {
                    continue;
                }
                $result[(string) $entry['resourceId']] = [
                    'status' => $this->map_status($entry),
                    'tracked' => !empty($entry['tracked']),
                    'brainstatus' => (string) ($entry['status'] ?? ''),
                ];
            }
        }

        return $result;
    }

    #[\Override]
    public function list_brains(): array {
        $body = $this->send('GET', 'awareness/list/memories', []);

        $brains = [];
        foreach (($body['awareness']['brain_ids'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['_id'])) {
                continue;
            }
            $brains[] = [
                'brainid' => (string) $entry['_id'],
                'active' => !empty($entry['active']),
                'language' => (string) ($entry['brainLanguage'] ?? ''),
                'tag' => (string) ($entry['brainTag'] ?? ''),
                'timecreated' => (int) ($entry['brainCreatedDateTime'] ?? 0),
            ];
        }

        return $brains;
    }

    #[\Override]
    public function generate_widgets(array $params): array {
        // The field vocabulary follows the Tarsus developer's instructions:
        // context_type is a constant, state stays 'ask', default_robot stays
        // true, input is always text. full_brain_access must be an explicit
        // 'false' — that is what makes resource_ids the retrieval boundary
        // (verified live); 'true' searches the whole shared brain regardless
        // of scope. Didactics (style, dqr, industry, full-text search) come
        // from the activity's own settings.
        $fields = [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'context_name' => (string) ($params['contextname'] ?? ''),
            'context_description' => (string) ($params['contextdescription'] ?? ''),
            'robot_id' => (string) ($params['robotid'] ?? ''),
            'robot_name' => (string) ($params['robotname'] ?? ''),
            'robot_description' => (string) ($params['robotdescription'] ?? ''),
            'user_id' => (string) ($params['userid'] ?? ''),
            'user_name' => (string) ($params['username'] ?? ''),
            'user_language' => (string) ($params['userlanguage'] ?? 'de'),
            'state' => 'ask',
            'default_robot' => 'true',
            'input_modality' => 'text',
            'conversation_style' => (string) (int) ($params['conversationstyle'] ?? 3),
            'dqr' => (string) (int) ($params['dqr'] ?? 6),
            'full_text_search' => !empty($params['fulltextsearch']) ? 'true' : 'false',
            'full_brain_access' => 'false',
            'operators' => self::operators($params),
            'anti_cheat' => !empty($params['anticheat']) ? 'true' : 'false',
            'learn_tempo' => self::learn_tempo($params),
            'resource_ids' => json_encode(array_values($params['resourceids'] ?? [])),
            'query' => (string) ($params['query'] ?? ''),
        ];

        $industry = trim((string) ($params['industry'] ?? ''));
        if ($industry !== '') {
            // An unset domain is omitted rather than sent empty.
            $fields['industry'] = $industry;
        }

        $body = $this->send('POST', '/api/t0/activity/generate/next/widgets', $fields);

        // The response is a widget list. An answerWidget carries the answer
        // in bit.data.answer (its bit.text merely echoes the question); the
        // informational widgets — welcomeWidget on a conversation's first
        // call, and whatever Tarsus adds later — carry their message in
        // bit.text with no data.answer. Collect both rather than matching
        // widget types by name, so new informational widgets surface instead
        // of vanishing.
        $answer = '';
        $messages = [];
        foreach (($body['activity']['widgets'] ?? []) as $widget) {
            $bit = $widget['bit'] ?? [];
            $answertext = (string) ($bit['data']['answer'] ?? '');
            if ($answertext !== '') {
                $answer = $answertext;
                continue;
            }
            $text = (string) ($bit['text'] ?? '');
            if ($text !== '') {
                $messages[] = $text;
            }
        }

        return [
            'answer' => $answer,
            'messages' => $messages,
            // The response carries no cost figure; spec §6 prices a grounded
            // answer at ~6 credits.
            'credits' => 6,
        ];
    }

    #[\Override]
    public function generate_practice_widget(array $params): array {
        // Same call as the chat, but state 'practice' makes the brain
        // generate the next question instead of answering one. The query is
        // required by the endpoint and stays empty here; question_types
        // must carry all three grades with at least one type each (verified
        // live) — difficulty has no parameter of its own.
        $fields = [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'context_name' => (string) ($params['contextname'] ?? ''),
            'context_description' => (string) ($params['contextdescription'] ?? ''),
            'robot_id' => (string) ($params['robotid'] ?? ''),
            'robot_name' => (string) ($params['robotname'] ?? ''),
            'robot_description' => (string) ($params['robotdescription'] ?? ''),
            'user_id' => (string) ($params['userid'] ?? ''),
            'user_name' => (string) ($params['username'] ?? ''),
            'user_language' => (string) ($params['userlanguage'] ?? 'de'),
            'state' => 'practice',
            'default_robot' => 'true',
            'input_modality' => 'text',
            'conversation_style' => (string) (int) ($params['conversationstyle'] ?? 3),
            'dqr' => (string) (int) ($params['dqr'] ?? 6),
            'full_text_search' => !empty($params['fulltextsearch']) ? 'true' : 'false',
            'full_brain_access' => 'false',
            'operators' => self::operators($params),
            'anti_cheat' => !empty($params['anticheat']) ? 'true' : 'false',
            'learn_tempo' => self::learn_tempo($params),
            'resource_ids' => json_encode(array_values($params['resourceids'] ?? [])),
            'taxonomy_levels' => json_encode(array_values($params['taxonomylevels'] ?? [])),
            'question_types' => self::encode_question_types($params['questiontypes'] ?? []),
            'query' => '',
        ];

        $industry = trim((string) ($params['industry'] ?? ''));
        if ($industry !== '') {
            // An unset domain is omitted rather than sent empty.
            $fields['industry'] = $industry;
        }

        try {
            $body = $this->send('POST', '/api/t0/activity/generate/next/widgets', $fields);
        } catch (transport_exception $e) {
            // Verified live: when no question of the requested types can be
            // built from the material, the brain answers 500 with this
            // message rather than an empty list. That is "no question", not
            // a transport failure.
            if ($e->httpstatus === 500 && stripos($e->body, 'UNABLE TO CREATE WIDGETS') !== false) {
                return ['widgets' => [], 'progress' => self::extract_progress([], []), 'credits' => 0];
            }
            throw $e;
        }

        // One call may generate several questions (verified live: usually
        // two); every widget of a supported question type is returned, in
        // the order Tarsus sent them — how many appear per step is its
        // decision, not ours. Correctness information (isCorrect,
        // clarifications, the model answer) stays server-side — it must
        // never reach the learner's browser before evaluation.
        $widgets = [];
        $widgetdata = [];
        foreach (($body['activity']['widgets'] ?? []) as $candidate) {
            $bit = $candidate['bit'] ?? [];
            $type = (string) ($bit['type'] ?? '');
            if (!in_array($type, self::QUESTION_WIDGET_TYPES, true)) {
                continue;
            }
            $data = (array) ($bit['data'] ?? []);
            $choices = [];
            foreach (($data['choices'] ?? []) as $choice) {
                $choices[] = self::choice_text($choice['choice'] ?? '');
            }
            $widgets[] = [
                'id' => (string) ($candidate['_id'] ?? ''),
                'type' => $type,
                'question' => (string) ($data['question'] ?? ($bit['text'] ?? '')),
                'choices' => $choices,
                // A rule-of-three question asks for named quantities; the
                // model answers arrive alongside and stay server-side.
                'variables' => self::variables_of($data),
                // A spreadsheet calculation asks for the '?' cells of a
                // costing table; the answer table stays server-side.
                'table' => self::question_table($data),
                'columns' => self::table_columns((array) ($data['questionTable'] ?? [])),
                'taxonomylevel' => (string) ($data['taxonomyLevel'] ?? ''),
                'difficultylevel' => (int) ($data['difficultyLevel'] ?? 0),
            ];
            $widgetdata = $data;
        }

        return [
            'widgets' => $widgets,
            'progress' => self::extract_progress((array) ($body['activity'] ?? []), $widgetdata),
            'credits' => 6,
        ];
    }

    #[\Override]
    public function generate_learn_widget(array $params): array {
        // Same call as the practice, with state 'learn': the brain first
        // sends the learning objectives, then one micro-learning stack per
        // call. learning_method picks the didactic concept, gif allows
        // animated illustrations.
        $fields = [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'context_name' => (string) ($params['contextname'] ?? ''),
            'context_description' => (string) ($params['contextdescription'] ?? ''),
            'robot_id' => (string) ($params['robotid'] ?? ''),
            'robot_name' => (string) ($params['robotname'] ?? ''),
            'robot_description' => (string) ($params['robotdescription'] ?? ''),
            'user_id' => (string) ($params['userid'] ?? ''),
            'user_name' => (string) ($params['username'] ?? ''),
            'user_language' => (string) ($params['userlanguage'] ?? 'de'),
            'state' => 'learn',
            'default_robot' => 'true',
            'input_modality' => 'text',
            'conversation_style' => (string) (int) ($params['conversationstyle'] ?? 3),
            'dqr' => (string) (int) ($params['dqr'] ?? 6),
            'full_text_search' => !empty($params['fulltextsearch']) ? 'true' : 'false',
            'full_brain_access' => 'false',
            'operators' => self::operators($params),
            'anti_cheat' => !empty($params['anticheat']) ? 'true' : 'false',
            'learn_tempo' => self::learn_tempo($params),
            'learning_method' => (string) ($params['learningmethod'] ?? 'micro-learning'),
            'gif' => !empty($params['gif']) ? 'true' : 'false',
            'resource_ids' => json_encode(array_values($params['resourceids'] ?? [])),
            'taxonomy_levels' => json_encode(array_values($params['taxonomylevels'] ?? [])),
            'question_types' => self::encode_question_types($params['questiontypes'] ?? []),
            'query' => '',
        ];

        $industry = trim((string) ($params['industry'] ?? ''));
        if ($industry !== '') {
            $fields['industry'] = $industry;
        }

        try {
            $body = $this->send('POST', '/api/t0/activity/generate/next/widgets', $fields);
        } catch (transport_exception $e) {
            if ($e->httpstatus === 500 && stripos($e->body, 'UNABLE TO CREATE WIDGETS') !== false) {
                return ['items' => [], 'progress' => self::extract_progress([], []), 'credits' => 0];
            }
            throw $e;
        }

        $items = [];
        foreach (($body['activity']['widgets'] ?? []) as $widget) {
            $item = self::learn_item($widget);
            if ($item === null) {
                // Anything else with a text is a plain message, such as
                // the step-transition note between two objectives.
                $text = (string) ($widget['bit']['text'] ?? '');
                $item = $text !== '' ? ['kind' => 'message', 'text' => $text] : null;
            }
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'items' => $items,
            'progress' => self::extract_progress((array) ($body['activity'] ?? []), []),
            'credits' => 6,
        ];
    }

    /**
     * Shape one stored question widget — in the feed or inside a learning
     * stack — as a 'practice' item, answered ones carrying their verdict
     * from data.evaluation, unanswered ones with correctness stripped.
     *
     * @param array  $widget The widget as the brain stores it.
     * @param string $type   Its question widget type.
     * @param array  $data   Its bit data.
     * @return array The practice item.
     */
    private static function question_item(array $widget, string $type, array $data): array {
        $choices = [];
        foreach (($data['choices'] ?? []) as $choice) {
            $choices[] = self::choice_text($choice['choice'] ?? '');
        }
        $evaluation = (array) ($data['evaluation'] ?? []);
        $answered = !empty($evaluation['evaluated']);
return [
    'kind' => 'practice',
            'id' => (string) ($widget['_id'] ?? ''),
            'type' => $type,
            'question' => (string) ($data['question'] ?? ($bit['text'] ?? '')),
            'choices' => $choices,
            'variables' => self::variables_of($data),
            'table' => self::question_table($data),
            'columns' => self::table_columns((array) ($data['questionTable'] ?? [])),
            'taxonomylevel' => (string) ($data['taxonomyLevel'] ?? ''),
            'difficultylevel' => (int) ($data['difficultyLevel'] ?? 0),
            'answered' => $answered,
            'score' => $answered ? (float) ($evaluation['score'] ?? 0) : null,
            'correct' => $answered ? ((float) ($evaluation['score'] ?? 0) >= 50) : null,
            'feedback' => $answered ? (string) ($evaluation['feedback'] ?? '') : '',
            'useranswer' => $answered ? self::choice_text($evaluation['userAnswer'] ?? '') : '',
            'useranswers' => $answered ? self::variable_answers($evaluation['userVariables'] ?? []) : [],
            'answer' => $answered ? self::choice_text($evaluation['answer'] ?? ($data['answer'] ?? '')) : '',
            'answers' => $answered ? self::variable_verdicts($data) : [],
            'usertable' => $answered ? self::table_rows($evaluation['userTable'] ?? []) : [],
            'answertable' => $answered ? self::table_verdicts($data) : [],
        ];
    }

    /**
     * Read one learn-mode widget: the learning objectives or a stack.
     *
     * Verified live: the objectives come as a learningObjectivesWidget
     * (title, message, learningObjectives[]); a learning unit comes as
     * {stackId, stackedWidgets[]} whose slides are micro-learning widgets
     * with a title, Markdown text and optionally an illustration
     * (data.image.imageBytes as a data URI, positioned left or right).
     *
     * @param array $widget One element of the widgets list.
     * @return array|null The item ({kind: 'objectives'|'stack'|'complete', ...}), or null for other widgets.
     */
    private static function learn_item(array $widget): ?array {
        if (isset($widget['stackedWidgets']) && is_array($widget['stackedWidgets'])) {
            $slides = [];
            foreach ($widget['stackedWidgets'] as $slide) {
                $bit = (array) ($slide['bit'] ?? []);
                $data = (array) ($bit['data'] ?? []);
                $type = (string) ($bit['type'] ?? '');
                if (in_array($type, self::QUESTION_WIDGET_TYPES, true)) {
                    // The unit's check question (verified live: usually the
                    // last slide, sometimes mid-stack too). Answering it with
                    // state 'learn' is what earns the learning units.
                    $slides[] = [
                        'id' => (string) ($slide['_id'] ?? ''),
                        'kind' => 'question',
                        'title' => '',
                        'text' => '',
                        'image' => '',
                        'imageposition' => 'right',
                        'question' => self::question_item($slide, $type, $data),
                    ];
                    continue;
                }
                $text = (string) ($data['text'] ?? ($bit['text'] ?? ''));
                $title = (string) ($data['title'] ?? ($bit['title'] ?? ''));
                if ($text === '' && $title === '') {
                    continue;
                }
                $image = (array) ($data['image'] ?? []);
                $slides[] = [
                    'id' => (string) ($slide['_id'] ?? ''),
                    'kind' => 'text',
                    'title' => $title,
                    'text' => $text,
                    'image' => self::image_source(
                        (string) ($image['imageBytes'] ?? ($image['url'] ?? ($image['imageUrl'] ?? '')))),
                    'imageposition' => strtolower((string) ($image['imagePosition'] ?? ($data['imagePosition'] ?? 'right'))) === 'left'
                        ? 'left' : 'right',
                ];
            }
            return [
                'kind' => 'stack',
                'id' => (string) ($widget['stackId'] ?? ''),
                'slides' => $slides,
            ];
        }

        $bit = (array) ($widget['bit'] ?? []);
        if ((string) ($bit['type'] ?? '') === 'courseCompleteWidget') {
            // The brain's farewell once every objective is done (verified
            // live): learnProgress reads 100 from here on.
            return [
                'kind' => 'complete',
                'id' => (string) ($widget['_id'] ?? ''),
                'text' => (string) ($bit['text'] ?? ''),
            ];
        }
        if ((string) ($bit['type'] ?? '') === 'learningObjectivesWidget') {
            $data = (array) ($bit['data'] ?? []);
            $objectives = [];
            foreach ((array) ($data['learningObjectives'] ?? []) as $objective) {
                if (!is_array($objective)) {
                    continue;
                }
                $objectives[] = [
                    'id' => (string) ($objective['_id'] ?? ''),
                    'objective' => (string) ($objective['objective'] ?? ''),
                    'taxonomylevel' => (string) ($objective['taxonomyLevel'] ?? ''),
                    'done' => !empty($objective['done']),
                ];
            }
            return [
                'kind' => 'objectives',
                'id' => (string) ($widget['_id'] ?? ''),
                'title' => (string) ($data['title'] ?? ($bit['title'] ?? '')),
                'message' => (string) ($data['message'] ?? ($bit['text'] ?? '')),
                'objectives' => $objectives,
            ];
        }

        return null;
    }

    /**
     * Accept an illustration as a base64 image data URI (the generated
     * flowcharts) or an https image URL (the animated GIFs the brain picks
     * from a GIF service when the teacher allows them, per Tarsus).
     *
     * @param string $value The image bytes or URL field.
     * @return string The source for an <img>, or '' when it is anything else.
     */
    private static function image_source(string $value): string {
        if (preg_match('~^data:image/(svg\+xml|png|jpeg|gif|webp);base64,[A-Za-z0-9+/=\s]+$~', $value)) {
            return $value;
        }
        if (preg_match('~^https://[^\s"\'<>]+$~', $value)) {
            return $value;
        }
        return '';
    }

    #[\Override]
    public function evaluate_practice_answer(array $params): array {
        $paths = [
            'singleChoiceQuestionWidget' => '/api/t0/activity/evaluate/single-choice-question/widget',
            'trueFalseQuestionWidget' => '/api/t0/activity/evaluate/true-false-question/widget',
            'openQuestionWidget' => '/api/t0/activity/evaluate/open-question/widget',
            'crossMultiplicationQuestionWidget' => '/api/t0/activity/evaluate/cross-multiplication-question/widget',
            'tradeCostingQuestionWidget' => '/api/t0/activity/evaluate/trade-costing-question/widget',
        ];
        $type = (string) ($params['widgettype'] ?? '');
        if (!isset($paths[$type])) {
            throw new \coding_exception('Unsupported practice widget type: ' . $type);
        }

        // The endpoints take slightly different subsets; the superset is
        // sent to all of them.
        $fields = [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'context_name' => (string) ($params['contextname'] ?? ''),
            'context_description' => (string) ($params['contextdescription'] ?? ''),
            'robot_id' => (string) ($params['robotid'] ?? ''),
            'robot_name' => (string) ($params['robotname'] ?? ''),
            'robot_description' => (string) ($params['robotdescription'] ?? ''),
            'user_id' => (string) ($params['userid'] ?? ''),
            'user_name' => (string) ($params['username'] ?? ''),
            'user_language' => (string) ($params['userlanguage'] ?? 'de'),
            // 'learn' evaluates a learning unit's check question and earns
            // learning units; 'practice' earns experience points (verified
            // live on the same question).
            'state' => (($params['state'] ?? '') === 'learn') ? 'learn' : 'practice',
            'input_modality' => 'text',
            'learn_tempo' => self::learn_tempo($params),
            'resource_ids' => json_encode(array_values($params['resourceids'] ?? [])),
            'taxonomy_levels' => json_encode(array_values($params['taxonomylevels'] ?? [])),
            'widget_id' => (string) ($params['widgetid'] ?? ''),
            'user_answer' => (string) ($params['useranswer'] ?? ''),
        ];
        if (!empty($params['useranswers'])) {
            // A rule-of-three question is answered per variable; the brain
            // accepts exactly {variable, answer} pairs (verified live).
            $fields['user_answers'] = json_encode(array_map(
                static fn(array $entry): array => ['variable' => $entry['variable'], 'answer' => $entry['answer']],
                self::variable_answers($params['useranswers'])
            ));
        } else if (!empty($params['usertable'])) {
            // A spreadsheet calculation is answered as the whole table, the
            // given cells echoed and the '?' cells filled (verified live).
            // The column names are localised by the brain per user language
            // (Position/Satz/Betrag, Position/Rate/Amount, ...), so the
            // names the question came with travel back with the answer.
            $columns = array_values(array_map('strval', (array) ($params['columns'] ?? [])));
            if (count($columns) !== 3) {
                $columns = ['Position', 'Satz', 'Betrag'];
            }
            $fields['user_answers'] = json_encode(array_map(
                static fn(array $row): array => [
                    $columns[0] => $row['position'], $columns[1] => $row['rate'], $columns[2] => $row['amount'],
                ],
                self::table_rows($params['usertable'])
            ));
        }

        $body = $this->send('POST', $paths[$type], $fields);

        $data = (array) ($body['activity']['widget']['bit']['data'] ?? []);

        // The verdict lives in data.evaluation (verified live): score 0-100,
        // the feedback sentence, the model answer and the user's answer.
        // The choices additionally carry per-choice clarifications; matching
        // the learner's pick against them is the fallback when the
        // evaluation block is missing.
        $useranswer = self::normalise_answer((string) ($params['useranswer'] ?? ''));
        $choices = [];
        $correct = null;
        $feedback = '';
        foreach (($data['choices'] ?? []) as $choice) {
            $text = self::choice_text($choice['choice'] ?? '');
            $ischoicecorrect = !empty($choice['isCorrect']);
            $choices[] = [
                'text' => $text,
                'iscorrect' => $ischoicecorrect,
                'clarification' => (string) ($choice['clarification'] ?? ''),
            ];
            if (self::normalise_answer($text) === $useranswer) {
                $correct = $ischoicecorrect;
                $feedback = (string) ($choice['clarification'] ?? '');
            }
        }

        $evaluation = (array) ($data['evaluation'] ?? []);
        $answer = (string) ($data['answer'] ?? '');
        $score = null;
        if (!empty($evaluation['evaluated'])) {
            $score = (float) ($evaluation['score'] ?? 0);
            $correct = $score >= 50;
            $feedback = (string) ($evaluation['feedback'] ?? $feedback);
            $answer = (string) ($evaluation['answer'] ?? $answer);
        }

        return [
            'correct' => $correct,
            // The brain's score 0-100; partial credit shows as a fraction.
            'score' => $score,
            'feedback' => $feedback,
            'answer' => self::choice_text($answer),
            'choices' => $choices,
            'answers' => self::variable_verdicts($data),
            'usertable' => self::table_rows(((array) ($data['evaluation'] ?? []))['userTable'] ?? []),
            'answertable' => self::table_verdicts($data),
            'progress' => self::extract_progress((array) ($body['activity'] ?? []), $data),
            'credits' => 6,
        ];
    }

    /**
     * The question table of a spreadsheet calculation, '?' marking the
     * cells to answer.
     *
     * @param array $data The widget's bit.data.
     * @return array<int, array{position: string, rate: string, amount: string, askrate: bool, askamount: bool}>
     */
    private static function question_table(array $data): array {
        $rows = [];
        foreach (self::table_rows($data['questionTable'] ?? []) as $row) {
            $row['askrate'] = $row['rate'] === '?';
            $row['askamount'] = $row['amount'] === '?';
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The column names of an API costing table: position, rate, amount.
     *
     * The brain localises them per user language (Position/Satz/Betrag,
     * Position/Rate/Amount, ...); known names are matched, anything else
     * falls back to column order.
     *
     * @param array $rows The API rows.
     * @return string[] Exactly three names, defaults when the table is empty.
     */
    private static function table_columns(array $rows): array {
        $first = null;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $first = $row;
                break;
            }
        }
        if ($first === null) {
            return ['Position', 'Satz', 'Betrag'];
        }
        $keys = array_values(array_filter(array_keys($first),
            static fn($key) => strcasecmp((string) $key, 'clarification') !== 0));
        $pick = static function (string $pattern, int $index) use ($keys): string {
            foreach ($keys as $key) {
                if (preg_match($pattern, (string) $key)) {
                    return (string) $key;
                }
            }
            return (string) ($keys[$index] ?? '');
        };
        return [
            $pick('/^position$/i', 0),
            $pick('/^(rate|satz|prozentsatz|taux|tasa|tasso)$/i', 1),
            $pick('/^(amount|betrag|montant|importe|importo)$/i', 2),
        ];
    }

    /**
     * Normalise costing table rows from either side of the wire.
     *
     * @param mixed $entries Raw rows: API rows in any column language, or ours (position/rate/amount).
     * @return array<int, array{position: string, rate: string, amount: string}>
     */
    private static function table_rows($entries): array {
        $entries = array_values(array_filter((array) $entries, 'is_array'));
        [$poskey, $ratekey, $amountkey] = self::table_columns($entries);
        $rows = [];
        foreach ($entries as $entry) {
            $position = trim((string) ($entry['position'] ?? ($entry[$poskey] ?? '')));
            if ($position === '') {
                continue;
            }
            $rows[] = [
                'position' => $position,
                'rate' => trim((string) ($entry['rate'] ?? ($entry[$ratekey] ?? ''))),
                'amount' => trim((string) ($entry['amount'] ?? ($entry[$amountkey] ?? ''))),
            ];
        }
        return $rows;
    }

    /**
     * The model table of a spreadsheet calculation with per-cell verdicts.
     *
     * Verified live: after evaluation, data.evaluation carries answerTable
     * (the model rows) and evaluationMask — one {Position, Satz, Betrag}
     * per row, 1 for a correct cell, -1 for a wrong one, 0 for a given one.
     *
     * @param array $data The widget's bit.data.
     * @return array<int, array{position: string, rate: string, amount: string, rateverdict: int, amountverdict: int}>
     */
    private static function table_verdicts(array $data): array {
        $evaluation = (array) ($data['evaluation'] ?? []);
        $mask = array_values(array_filter((array) ($evaluation['evaluationMask'] ?? []), 'is_array'));
        $modelrows = (array) ($evaluation['answerTable'] ?? ($data['answerTable'] ?? []));
        [, $ratekey, $amountkey] = self::table_columns($mask !== [] ? $mask : $modelrows);
        $rows = [];
        foreach (self::table_rows($modelrows) as $i => $row) {
            $cell = $mask[$i] ?? [];
            $row['rateverdict'] = max(-1, min(1, (int) ($cell[$ratekey] ?? 0)));
            $row['amountverdict'] = max(-1, min(1, (int) ($cell[$amountkey] ?? 0)));
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The model answers of a rule-of-three question with their verdicts.
     *
     * Verified live: after evaluation, data.evaluation carries
     * answerVariables (the model figures) and evaluationMask — one
     * {variable, evaluation} per quantity, negative when wrong.
     *
     * @param array $data The widget's bit.data.
     * @return array<int, array{variable: string, answer: string, iscorrect: bool, clarification: string}>
     */
    private static function variable_verdicts(array $data): array {
        $evaluation = (array) ($data['evaluation'] ?? []);
        $verdicts = [];
        foreach ((array) ($evaluation['evaluationMask'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['variable'])) {
                $verdicts[(string) $entry['variable']] = (float) ($entry['evaluation'] ?? 0) > 0;
            }
        }
        $answers = self::variable_answers($evaluation['answerVariables'] ?? ($data['answers'] ?? []));
        foreach ($answers as &$answer) {
            $answer['iscorrect'] = $verdicts[$answer['variable']] ?? false;
        }
        unset($answer);
        return $answers;
    }

    /**
     * The variable names a rule-of-three question asks for, in order.
     *
     * @param array $data The widget's bit.data.
     * @return string[] Variable names; empty for other question types.
     */
    private static function variables_of(array $data): array {
        $variables = [];
        foreach ((array) ($data['answers'] ?? []) as $entry) {
            $variable = trim((string) ($entry['variable'] ?? ''));
            if ($variable !== '') {
                $variables[] = $variable;
            }
        }
        return $variables;
    }

    /**
     * Normalise a per-variable answer list from either side of the wire.
     *
     * @param mixed $entries Raw list of {variable, answer[, isCorrect, clarification]}.
     * @return array<int, array{variable: string, answer: string, iscorrect: bool, clarification: string}>
     */
    private static function variable_answers($entries): array {
        $answers = [];
        foreach ((array) $entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $variable = trim((string) ($entry['variable'] ?? ''));
            if ($variable === '') {
                continue;
            }
            $answers[] = [
                'variable' => $variable,
                'answer' => (string) ($entry['answer'] ?? ''),
                'iscorrect' => !empty($entry['isCorrect'] ?? ($entry['iscorrect'] ?? false)),
                'clarification' => (string) ($entry['clarification'] ?? ''),
            ];
        }
        return $answers;
    }

    #[\Override]
    public function evaluate_answer(string $contextid, array $params): array {
        // Phase 2.
        throw new \coding_exception('evaluate_answer is not implemented in Phase 1.');
    }

    /**
     * The operator taxonomy to send: 'standard' or 'ihk'.
     *
     * @param array $params The call parameters.
     * @return string The taxonomy; 'standard' when unset or unknown.
     */
    private static function operators(array $params): string {
        $value = strtolower(trim((string) ($params['operators'] ?? '')));
        return in_array($value, ['standard', 'ihk'], true) ? $value : 'standard';
    }

    /**
     * The learning pace to send, within the brain's 0.1-2 range.
     *
     * @param array $params The call parameters.
     * @return string The pace as text; '1' (the recommended pace) when unset.
     */
    private static function learn_tempo(array $params): string {
        $value = $params['learntempo'] ?? null;
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '1';
        }
        $pace = max(0.1, min(2.0, round((float) $value, 2)));
        return rtrim(rtrim(number_format($pace, 2, '.', ''), '0'), '.');
    }

    /**
     * Encode the question types map with string grade keys, as the API expects.
     *
     * @param array<int, string[]> $map Difficulty grade => widget type names.
     * @return string JSON object, e.g. {"1":["singleChoiceQuestionWidget"]}.
     */
    private static function encode_question_types(array $map): string {
        $encoded = [];
        foreach ($map as $grade => $types) {
            $encoded[(string) (int) $grade] = array_values((array) $types);
        }
        return json_encode((object) $encoded);
    }

    /**
     * Render a choice value as text: true/false widgets use booleans.
     *
     * @param mixed $choice The raw choice value from the API.
     * @return string The choice as text.
     */
    private static function choice_text($choice): string {
        if (is_bool($choice)) {
            return $choice ? 'true' : 'false';
        }
        return (string) $choice;
    }

    /**
     * Compare answers leniently: case and surrounding space do not matter.
     *
     * @param string $answer The answer text.
     * @return string The normalised form.
     */
    private static function normalise_answer(string $answer): string {
        return \core_text::strtolower(trim($answer));
    }

    /**
     * Read the experience/progress figures from a t0 response.
     *
     * Generation carries them inside the widget data, evaluation at the
     * activity level; the widget data wins where both are present.
     *
     * @param array $activity   The response's activity object.
     * @param array $widgetdata The widget's bit.data, if any.
     * @return array{earnedxp: float, totalxp: float, earnedunits: float, totalunits: float,
     *               practiceprogress: float, learnprogress: float, progress: float}
     */
    private static function extract_progress(array $activity, array $widgetdata): array {
        $pick = static function (string $key) use ($activity, $widgetdata) {
            return $widgetdata[$key] ?? ($activity[$key] ?? 0);
        };
        return [
            'earnedxp' => (float) $pick('earnedExperiencePoints'),
            'totalxp' => (float) $pick('totalExperiencePoints'),
            // The learn mode keeps its own tally, learning units, beside
            // the practice mode's experience points.
            'earnedunits' => (float) $pick('earnedLearningUnits'),
            'totalunits' => (float) $pick('totalLearningUnits'),
            // Three progress figures (verified live): the practice and the
            // learn mode each have their own, and 'progress' is the overall
            // one — their average — which the chat mode shows.
            'practiceprogress' => (float) $pick('practiceProgress'),
            'learnprogress' => (float) $pick('learnProgress'),
            'progress' => (float) $pick('progress'),
        ];
    }

    #[\Override]
    public function get_usage(): array {
        // Phase 2.
        throw new \coding_exception('get_usage is not implemented in Phase 1.');
    }

    /**
     * Translate a brain status entry into the local state-machine vocabulary.
     *
     * The brain reports exactly three values (confirmed by Tarsus): done,
     * pending and failed. "failed" means the resource is simply not in the
     * brain, not that something is broken. The `tracked` flag is redundant
     * next to `status`, so it is not consulted. An unrecognised value is
     * treated as "still working", never as success, so an API change can only
     * ever cost an extra poll.
     *
     * @param array $entry One element of the check/tracked-resources response.
     * @return string A {@see status} constant.
     */
    private function map_status(array $entry): string {
        return match (strtolower((string) ($entry['status'] ?? ''))) {
            'done' => status::INDEXED,
            'failed' => status::NOT_INDEXED,
            default => status::PENDING_CONFIRMATION,
        };
    }

    /**
     * Pick the endpoint and form fields for one material payload.
     *
     * @param array $resource Payload from {@see \local_tarsus\material\material::to_payload()}.
     * @return array{0: string, 1: array, 2: string|null} Path, form fields, temp file to clean up.
     * @throws transport_exception When a file bit no longer resolves to a stored file.
     */
    private function build_track_request(array $resource): array {
        $title = (string) ($resource['title'] ?? '');
        $type = (string) ($resource['type'] ?? bit_type::TEXT);

        $common = [
            'brain_id' => $this->brain(),
            'resource_id' => (string) ($resource['resource_id'] ?? ''),
            'asset_id' => (string) ($resource['context_id'] ?? ''),
        ];

        if ($type === bit_type::URL) {
            return ['access/track/url-bits', $common + ['url' => (string) ($resource['url'] ?? '')], null];
        }

        if ($type === bit_type::DOCUMENT || $type === bit_type::MEDIA) {
            return $this->build_file_track_request($resource, $common, $title, $type);
        }

        return [
            'access/track/text-bits',
            $common + ['text' => (string) ($resource['text'] ?? ''), 'title' => $title],
            null,
        ];
    }

    /**
     * Build the multipart request for a document or media bit.
     *
     * @param array  $resource The material payload.
     * @param array  $common   Fields shared by every track call.
     * @param string $title    Human-readable title.
     * @param string $type     The bit type.
     * @return array{0: string, 1: array, 2: string} Path, form fields, temp file path.
     * @throws transport_exception When the stored file cannot be resolved.
     */
    private function build_file_track_request(array $resource, array $common, string $title, string $type): array {
        $file = $this->stored_file($resource);
        if ($file === null) {
            throw new transport_exception(0, 'stored file for the resource no longer exists');
        }

        $temppath = $file->copy_content_to_temp('local_tarsus', 'track');
        $curlfile = new \CURLFile($temppath, (string) $file->get_mimetype(), $file->get_filename());

        if ($type === bit_type::DOCUMENT) {
            return ['access/track/document-bits', $common + ['document' => $curlfile], $temppath];
        }

        $isaudio = str_starts_with((string) $file->get_mimetype(), 'audio/');

        return [
            $isaudio ? 'access/track/audio-bits' : 'access/track/video-bits',
            $common + ['title' => $title, ($isaudio ? 'audio_content' : 'video_content') => $curlfile],
            $temppath,
        ];
    }

    /**
     * Resolve the stored file behind a document/media payload.
     *
     * @param array $resource The material payload.
     * @return \stored_file|null The file, or null when it has been deleted.
     */
    private function stored_file(array $resource): ?\stored_file {
        $pathnamehash = (string) ($resource['pathnamehash'] ?? '');
        if ($pathnamehash === '') {
            return null;
        }

        $file = get_file_storage()->get_file_by_hash($pathnamehash);

        return ($file && !$file->is_directory()) ? $file : null;
    }

    /**
     * The configured brain id, or fail loudly.
     *
     * Listing brains deliberately does not go through here: an admin must be
     * able to discover the available brains before one has been chosen.
     *
     * @return string
     * @throws \moodle_exception When no brain is configured.
     */
    private function brain(): string {
        $brainid = trim($this->brainid);
        if ($brainid === '') {
            throw new \moodle_exception('nobrainselected', 'local_tarsus');
        }

        return $brainid;
    }

    /**
     * Split ids into wire-sized chunks, dropping empties and duplicates.
     *
     * @param string[] $resourceids Namespaced resource ids.
     * @return array<int, string[]> Chunks of at most {@see self::BATCH_LIMIT} ids.
     */
    private function chunk(array $resourceids): array {
        $ids = array_values(array_unique(array_filter(array_map('strval', $resourceids), 'strlen')));

        return $ids === [] ? [] : array_chunk($ids, self::BATCH_LIMIT);
    }

    /**
     * Perform one form-data call against the brain and decode its JSON body.
     *
     * The API takes form-data even for GET and DELETE, so the method is set
     * via CURLOPT_CUSTOMREQUEST while the fields travel in the body.
     *
     * @param string $method    HTTP method.
     * @param string $path      Path relative to the brain API prefix, or an
     *                          absolute path (leading '/') for surfaces
     *                          outside /api/v1/brain, such as /api/t0.
     * @param array  $params    Form fields (credentials are added here).
     * @param bool   $uploading Whether the call carries a file.
     * @return array The decoded response body.
     * @throws transport_exception On any transport, HTTP or protocol failure.
     * @throws \moodle_exception When the plugin is not configured.
     */
    private function send(string $method, string $path, array $params, bool $uploading = false): array {
        $baseurl = rtrim(trim($this->baseurl), '/');

        if ($baseurl === '' || trim($this->apikey) === '') {
            throw new \moodle_exception('notconfigured', 'local_tarsus');
        }

        $params['api_key'] = $this->apikey;

        $url = str_starts_with($path, '/')
            ? $baseurl . $path
            : $baseurl . self::API_PREFIX . $path;

        // The brain expects form-data on every verb, including GET and DELETE.
        // curl::get() would move the fields into the query string and curl::delete()
        // drops them entirely, so post() carries the body and CURLOPT_CUSTOMREQUEST
        // relabels the verb, exactly as "curl --request GET --form" does.
        $curl = new \curl();
        $response = $curl->post($url, $params, [
            'CURLOPT_CUSTOMREQUEST' => $method,
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT' => $uploading ? self::UPLOAD_TIMEOUT : self::TIMEOUT,
        ]);

        if ($curl->get_errno()) {
            // Never echo the request: it carries the API key.
            throw new transport_exception(0, 'connection failed calling ' . $path);
        }

        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($httpcode < 200 || $httpcode >= 300) {
            // The brain explains some refusals in the body; keep its start
            // (never the request, which carries the API key).
            throw new transport_exception($httpcode, 'unexpected HTTP status calling ' . $path,
                trim(substr((string) $response, 0, 300)));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new transport_exception(0, 'malformed JSON response from ' . $path);
        }

        return $decoded;
    }
}
