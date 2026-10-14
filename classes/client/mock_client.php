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
 * Deterministic mock Tarsus client.
 *
 * Backs the entire build until the live API is granted. Returns stable
 * fixtures and can simulate transport failures and both synchronous and
 * asynchronous track results, so guardrail tests and the indexation state
 * machine are exercised without any network I/O.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mock_client implements client_interface {

    /**
     * Constructor.
     *
     * @param bool $async          When true, track returns 'pending-confirmation'
     *                             (async indexing); when false it returns 'indexed'
     *                             immediately (synchronous track).
     * @param int  $simulatestatus When non-zero, every call throws a
     *                             {@see transport_exception} with this HTTP status
     *                             (e.g. 429 / 500) for guardrail tests.
     */
    public function __construct(
        protected bool $async = true,
        protected int $simulatestatus = 0
    ) {
    }

    /**
     * Raise the simulated transport failure when configured.
     *
     * @return void
     * @throws transport_exception
     */
    protected function maybe_fail(): void {
        if ($this->simulatestatus !== 0) {
            throw new transport_exception($this->simulatestatus, 'mock simulated failure');
        }
    }

    #[\Override]
    public function track_resource(array $resource): array {
        $this->maybe_fail();

        $resourceid = $resource['resource_id'] ?? 'mock-resource';

        return [
            'status' => $this->async ? 'pending-confirmation' : 'indexed',
            'bitref' => 'mock-bit-' . sha1($resourceid),
            'credits' => 0,
        ];
    }

    #[\Override]
    public function retrieve_feed(array $params): array {
        $this->maybe_fail();

        // The fixture is one page long: any older page is empty.
        if ((int) ($params['offset'] ?? 0) > 0) {
            return ['items' => [], 'rawcount' => 0,
                'progress' => ['earnedxp' => 9.0, 'totalxp' => 15.0, 'practiceprogress' => 60.0], 'credits' => 0];
        }

        // A deterministic interleaved feed: the welcome, one chat exchange,
        // one answered practice question and one still open.
        return [
            'items' => [
                [
                    'kind' => 'message',
                    'title' => 'Mock activity',
                    'text' => '**Mock welcome** to the activity chat!',
                ],
                [
                    'kind' => 'exchange',
                    'query' => 'Mock question?',
                    'answer' => 'Mock stored answer.',
                ],
                [
                    'kind' => 'practice',
                    'id' => 'mock-widget-answered',
                    'type' => 'singleChoiceQuestionWidget',
                    'question' => 'Mock stored practice question?',
                    'choices' => ['Alpha', 'Beta', 'Gamma', 'Delta'],
                    'taxonomylevel' => 'WISSEN',
                    'difficultylevel' => 1,
                    'answered' => true,
                    'score' => 100.0,
                    'correct' => true,
                    'feedback' => '**Mock stored clarification.**',
                    'useranswer' => 'Alpha',
                    'answer' => 'Alpha',
                ],
                [
                    'kind' => 'practice',
                    'id' => 'mock-widget-open',
                    'type' => 'trueFalseQuestionWidget',
                    'question' => 'Mock unanswered practice question?',
                    'choices' => ['true', 'false'],
                    'taxonomylevel' => 'VERSTEHEN',
                    'difficultylevel' => 2,
                    'answered' => false,
                    'score' => null,
                    'correct' => null,
                    'feedback' => '',
                    'useranswer' => '',
                    'answer' => '',
                ],
                [
                    'kind' => 'stack',
                    'id' => 'mock-stack-0',
                    'slides' => [
                        ['id' => 'mock-slide-a', 'kind' => 'text', 'title' => 'Stored slide', 'text' => 'A **stored** slide.',
                            'image' => '', 'imageposition' => 'right'],
                    ],
                ],
                ['kind' => 'motivation', 'id' => 'mock-motivation', 'title' => '', 'text' => 'One **step** further.'],
                ['kind' => 'complete', 'id' => 'mock-complete', 'title' => '', 'text' => 'You have **completed** the mock course.'],
            ],
            'rawcount' => 7,
            'progress' => ['earnedxp' => 9.0, 'totalxp' => 15.0, 'earnedunits' => 1.0, 'totalunits' => 24.0,
                'practiceprogress' => 60.0, 'learnprogress' => 4.17, 'progress' => 32.08],
            'credits' => 0,
        ];
    }

    #[\Override]
    public function reset_conversation(string $contextid, string $userid): void {
        $this->maybe_fail();
    }

    #[\Override]
    public function untrack_resource(array $resourceids): array {
        $this->maybe_fail();

        $result = [];
        foreach ($resourceids as $resourceid) {
            $result[$resourceid] = 'removed';
        }

        return $result;
    }

    #[\Override]
    public function untrack_assets(array $assetids): array {
        $this->maybe_fail();

        $result = [];
        foreach ($assetids as $assetid) {
            $result[$assetid] = 'removed';
        }

        return $result;
    }

    #[\Override]
    public function get_resource_status(string $resourceid): array {
        $this->maybe_fail();

        return [
            'status' => 'indexed',
            'bitref' => 'mock-bit-' . sha1($resourceid),
        ];
    }

    #[\Override]
    public function check_tracked_resources(array $resourceids): array {
        $this->maybe_fail();

        $result = [];
        foreach (array_unique($resourceids) as $resourceid) {
            $result[$resourceid] = [
                'status' => 'indexed',
                'tracked' => true,
                'brainstatus' => 'done',
            ];
        }

        return $result;
    }

    #[\Override]
    public function list_brains(): array {
        $this->maybe_fail();

        return [
            [
                'brainid' => 'mock-brain-default',
                'active' => true,
                'language' => 'de',
                'tag' => 'default',
                'timecreated' => 1726722755,
            ],
            [
                'brainid' => 'mock-brain-retired',
                'active' => false,
                'language' => 'en',
                'tag' => 'default',
                'timecreated' => 1700000000,
            ],
            [
                'brainid' => 'deleted-17cff6a1-4850-4d71-987a-97ceea0e0cba-mock-brain-old',
                'active' => false,
                'language' => 'de',
                'tag' => 'default',
                'timecreated' => 1690000000,
            ],
        ];
    }

    #[\Override]
    public function generate_widgets(array $params): array {
        $this->maybe_fail();

        // Echo the question and the allowed scope back, so tests can assert
        // both what was asked and what the brain was allowed to read.
        $scope = implode(',', array_map('strval', $params['resourceids'] ?? []));

        return [
            'answer' => 'Mock chat answer for: ' . (string) ($params['query'] ?? '')
                . ' [scope: ' . $scope . ']'
                . ' [style: ' . (int) ($params['conversationstyle'] ?? 0)
                . ', dqr: ' . (int) ($params['dqr'] ?? 0) . ']',
            // Informational widgets (e.g. the welcome) are relayed as-is;
            // when and how often they appear is Tarsus's decision.
            'messages' => [['title' => 'Mock activity', 'text' => '**Mock welcome** to the activity chat!']],
            'credits' => 6,
        ];
    }

    #[\Override]
    public function generate_practice_widget(array $params): array {
        $this->maybe_fail();

        // Echo the scope and the didactic practice settings back, so tests
        // can assert exactly what the brain would have been asked for.
        $scope = implode(',', array_map('strval', $params['resourceids'] ?? []));
        $levels = implode(',', array_map('strval', $params['taxonomylevels'] ?? []));
        $typecounts = [];
        foreach (($params['questiontypes'] ?? []) as $grade => $types) {
            $typecounts[] = $grade . ':' . count((array) $types);
        }

        // Two questions per step, as the live brain usually generates.
        return [
            'widgets' => [
                [
                    'id' => 'mock-widget-1',
                    'type' => 'singleChoiceQuestionWidget',
                    'question' => 'Mock practice question?'
                        . ' [scope: ' . $scope . ']'
                        . ' [tax: ' . $levels . ']'
                        . ' [types: ' . implode(' ', $typecounts) . ']'
                        . ' [ops: ' . (string) ($params['operators'] ?? '') . ', cheat: ' . (int) !empty($params['anticheat'])
                        . ', tempo: ' . (string) ($params['learntempo'] ?? '') . ']',
                    'choices' => ['Alpha', 'Beta', 'Gamma', 'Delta'],
                    'taxonomylevel' => 'WISSEN',
                    'difficultylevel' => 1,
                ],
                [
                    'id' => 'mock-widget-2',
                    'type' => 'trueFalseQuestionWidget',
                    'question' => 'Mock second practice question?',
                    'choices' => ['true', 'false'],
                    'taxonomylevel' => 'VERSTEHEN',
                    'difficultylevel' => 2,
                ],
            ],
            'progress' => ['earnedxp' => 0.0, 'totalxp' => 6.0, 'practiceprogress' => 0.0],
            'credits' => 6,
        ];
    }

    #[\Override]
    public function generate_learn_widget(array $params): array {
        $this->maybe_fail();

        // The objectives, then one stack — both at once, so tests see each.
        return [
            'items' => [
                [
                    'kind' => 'objectives',
                    'id' => 'mock-objectives',
                    'title' => 'Mock learning goals',
                    'message' => 'Build **mock** knowledge step by step.',
                    'objectives' => [
                        ['id' => 'mock-goal-1', 'objective' => 'Name the mock basics.', 'taxonomylevel' => 'WISSEN', 'done' => false],
                        ['id' => 'mock-goal-2', 'objective' => 'Explain the mock rules.', 'taxonomylevel' => 'VERSTEHEN', 'done' => true],
                    ],
                ],
                [
                    'kind' => 'stack',
                    'id' => 'mock-stack-1',
                    'slides' => [
                        ['id' => 'mock-slide-1', 'kind' => 'text', 'taxonomylevel' => 'WISSEN', 'title' => 'First slide', 'text' => 'The **first** mock slide'
                            . ' [method: ' . (string) ($params['learningmethod'] ?? '') . ', gif: ' . (int) !empty($params['gif']) . ']',
                            'image' => '', 'imageposition' => 'right'],
                        ['id' => 'mock-slide-2', 'kind' => 'text', 'taxonomylevel' => 'VERSTEHEN', 'title' => 'Second slide', 'text' => 'The second mock slide.',
                            'image' => 'data:image/svg+xml;base64,PHN2Zy8+', 'imageposition' => 'left'],
                        ['id' => 'mock-learn-q', 'kind' => 'question', 'title' => '', 'text' => '', 'image' => '', 'imageposition' => 'right',
                            'question' => [
                                'kind' => 'practice', 'id' => 'mock-learn-q', 'type' => 'singleChoiceQuestionWidget',
                                'question' => 'Which mock answer is right?', 'choices' => ['Alpha', 'Beta'], 'variables' => [],
                                'table' => [], 'columns' => [], 'taxonomylevel' => 'WISSEN', 'difficultylevel' => 1,
                                'answered' => false, 'score' => null, 'correct' => null, 'feedback' => '', 'useranswer' => '',
                                'useranswers' => [], 'answer' => '', 'answers' => [], 'usertable' => [], 'answertable' => [],
                            ]],
                    ],
                ],
            ],
            'progress' => ['earnedxp' => 0.0, 'totalxp' => 6.0, 'earnedunits' => 2.0, 'totalunits' => 24.0,
                'practiceprogress' => 0.0, 'learnprogress' => 10.0, 'progress' => 5.0],
            'credits' => 6,
        ];
    }

    #[\Override]
    public function evaluate_practice_answer(array $params): array {
        $this->maybe_fail();

        // 'Alpha' is the fixture's correct answer, whatever the widget —
        // for a rule-of-three question, every variable must say so; for a
        // spreadsheet calculation, every asked amount.
        $usertable = (array) ($params['usertable'] ?? []);
        if ($usertable !== []) {
            $correct = true;
            $answertable = [];
            foreach ($usertable as $row) {
                $asked = ($row['amount'] ?? '') !== '' && strpos((string) ($row['position'] ?? ''), '=') === false;
                $iscorrect = strcasecmp(trim((string) ($row['amount'] ?? '')), 'Alpha') === 0;
                $correct = $correct && (!$asked || $iscorrect);
                $answertable[] = [
                    'position' => (string) ($row['position'] ?? ''),
                    'rate' => (string) ($row['rate'] ?? ''),
                    'amount' => $asked ? 'Alpha' : (string) ($row['amount'] ?? ''),
                    'rateverdict' => 0,
                    'amountverdict' => $asked ? ($iscorrect ? 1 : -1) : 0,
                ];
            }
            return [
                'correct' => $correct,
                'score' => $correct ? 100.0 : 50.0,
                'feedback' => $correct ? '**Mock clarification:** the table adds up.' : '**Mock clarification:** check the marked amounts.',
                'answer' => '',
                'choices' => [],
                'answers' => [],
                'usertable' => array_map(static fn(array $row): array => [
                    'position' => (string) ($row['position'] ?? ''),
                    'rate' => (string) ($row['rate'] ?? ''),
                    'amount' => (string) ($row['amount'] ?? ''),
                ], $usertable),
                'answertable' => $answertable,
                'progress' => ['earnedxp' => $correct ? 1.0 : 0.5, 'totalxp' => 6.0, 'practiceprogress' => 16.67],
                'credits' => 6,
            ];
        }
        $useranswers = (array) ($params['useranswers'] ?? []);
        if ($useranswers !== []) {
            $correct = true;
            $answers = [];
            foreach ($useranswers as $entry) {
                $iscorrect = strcasecmp(trim((string) ($entry['answer'] ?? '')), 'Alpha') === 0;
                $correct = $correct && $iscorrect;
                $answers[] = [
                    'variable' => (string) ($entry['variable'] ?? ''),
                    'answer' => 'Alpha',
                    'iscorrect' => $iscorrect,
                    'clarification' => $iscorrect ? 'Mock: right.' : 'Mock: the material says Alpha.',
                ];
            }
            return [
                'correct' => $correct,
                'score' => $correct ? 100.0 : 50.0,
                'feedback' => $correct ? '**Mock clarification:** all quantities right.' : '**Mock clarification:** check the highlighted quantities.',
                'answer' => '',
                'choices' => [],
                'answers' => $answers,
                'progress' => ['earnedxp' => $correct ? 1.0 : 0.0, 'totalxp' => 6.0, 'practiceprogress' => $correct ? 16.67 : 0.0,
                    // A learning unit's check question pays out units instead.
                    'earnedunits' => (($params['state'] ?? '') === 'learn' && $correct) ? 1.0 : 0.0, 'totalunits' => 6.0],
                'credits' => 6,
            ];
        }
        $correct = strcasecmp(trim((string) ($params['useranswer'] ?? '')), 'Alpha') === 0;

        return [
            'correct' => $correct,
            'score' => $correct ? 100.0 : 0.0,
            'feedback' => $correct
                ? '**Mock clarification:** well spotted.'
                : '**Mock clarification:** the material says Alpha.',
            'answer' => 'Alpha',
            'answers' => [],
            'choices' => [
                ['text' => 'Alpha', 'iscorrect' => true, 'clarification' => 'Mock: this is the one.'],
                ['text' => 'Beta', 'iscorrect' => false, 'clarification' => 'Mock: not this.'],
                ['text' => 'Gamma', 'iscorrect' => false, 'clarification' => 'Mock: not this.'],
                ['text' => 'Delta', 'iscorrect' => false, 'clarification' => 'Mock: not this.'],
            ],
            'progress' => [
                'earnedxp' => $correct ? 1.0 : 0.0,
                'totalxp' => 6.0,
                // A learning unit's check question pays out units instead.
                'earnedunits' => (($params['state'] ?? '') === 'learn' && $correct) ? 1.0 : 0.0,
                'totalunits' => 6.0,
                'practiceprogress' => $correct ? 16.67 : 0.0,
            ],
            'credits' => 6,
        ];
    }

    #[\Override]
    public function evaluate_answer(string $contextid, array $params): array {
        // Phase 2.
        throw new \coding_exception('evaluate_answer is not implemented in Phase 1.');
    }

    #[\Override]
    public function get_usage(): array {
        // Phase 2.
        throw new \coding_exception('get_usage is not implemented in Phase 1.');
    }
}
