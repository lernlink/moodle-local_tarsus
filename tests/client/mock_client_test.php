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
 * Tests for the deterministic mock client and the client factory.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\client\mock_client
 * @covers     \local_tarsus\client\client_factory
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mock_client_test extends \advanced_testcase {

    /**
     * Grounded search returns a deterministic answer with sources.
     *
     * @return void
     */
    public function test_search_grounded_returns_sources(): void {
        $client = new mock_client();
        $result = $client->search_grounded(
            'What is photosynthesis?',
            'pseudonym-1',
            ['site:1:mod_page:2:main', 'site:1:mod_page:3:main']
        );

        $this->assertArrayHasKey('answer', $result);
        $this->assertTrue($result['answered']);
        // The mock echoes the allowed scope back, so callers can assert
        // exactly what was offered to the brain.
        $this->assertSame(
            ['site:1:mod_page:2:main', 'site:1:mod_page:3:main'],
            array_column($result['sources'], 'resourceid')
        );
    }

    /**
     * The chat mock echoes the question and the allowed scope.
     *
     * @return void
     */
    public function test_generate_widgets_echoes_query_and_scope(): void {
        $client = new mock_client();
        $result = $client->generate_widgets([
            'query' => 'What is photosynthesis?',
            'resourceids' => ['site:1:mod_page:2:main'],
        ]);

        $this->assertStringContainsString('What is photosynthesis?', $result['answer']);
        $this->assertStringContainsString('site:1:mod_page:2:main', $result['answer']);
        $this->assertNotEmpty($result['messages']);
    }

    /**
     * The conversation mock replays a deterministic history.
     *
     * @return void
     */
    public function test_retrieve_widgets_replays_a_conversation(): void {
        $client = new mock_client();
        $result = $client->retrieve_widgets([
            'contextid' => 'site:tarsuszero:1',
            'userid' => 'pseudonym-1',
            'resourceids' => ['site:1:mod_page:2:main'],
        ]);

        $this->assertCount(2, $result['turns']);
        $this->assertSame('', $result['turns'][0]['query']);
        $this->assertSame('Mock question?', $result['turns'][1]['query']);
    }

    /**
     * The practice mock generates a question with the settings echoed back
     * and never leaks correctness information.
     *
     * @return void
     */
    public function test_generate_practice_widget_echoes_settings(): void {
        $client = new mock_client();
        $result = $client->generate_practice_widget([
            'resourceids' => ['site:1:mod_page:2:main'],
            'taxonomylevels' => [1, 3],
            'questiontypes' => [1 => ['singleChoiceQuestionWidget'], 3 => ['openQuestionWidget']],
        ]);

        $widget = $result['widget'];
        $this->assertSame('mock-widget-1', $widget['id']);
        $this->assertSame('singleChoiceQuestionWidget', $widget['type']);
        $this->assertStringContainsString('[scope: site:1:mod_page:2:main]', $widget['question']);
        $this->assertStringContainsString('[tax: 1,3]', $widget['question']);
        $this->assertStringContainsString('[types: 1:1 3:1]', $widget['question']);
        $this->assertSame(['Alpha', 'Beta', 'Gamma', 'Delta'], $widget['choices']);
        // The generation result must not reveal which choice is correct.
        $this->assertArrayNotHasKey('iscorrect', $widget);
        $this->assertSame(6.0, $result['progress']['totalxp']);
    }

    /**
     * The practice mock judges the fixture answer and pays out experience.
     *
     * @return void
     */
    public function test_evaluate_practice_answer_judges_the_fixture(): void {
        $client = new mock_client();

        $right = $client->evaluate_practice_answer(['widgetid' => 'mock-widget-1', 'useranswer' => 'alpha']);
        $this->assertTrue($right['correct']);
        $this->assertSame('Alpha', $right['answer']);
        $this->assertSame(1.0, $right['progress']['earnedxp']);

        $wrong = $client->evaluate_practice_answer(['widgetid' => 'mock-widget-1', 'useranswer' => 'Beta']);
        $this->assertFalse($wrong['correct']);
        $this->assertStringContainsString('Alpha', $wrong['feedback']);
        $this->assertTrue($wrong['choices'][0]['iscorrect']);
        $this->assertSame(0.0, $wrong['progress']['earnedxp']);
    }

    /**
     * Resetting a conversation honours the simulated failure switch.
     *
     * @return void
     */
    public function test_reset_conversation_respects_simulated_failure(): void {
        // A healthy client resets without complaint.
        (new mock_client())->reset_conversation('site:tarsuszero:1', 'pseudonym-1');

        $failing = new mock_client(true, 500);
        try {
            $failing->reset_conversation('site:tarsuszero:1', 'pseudonym-1');
            $this->fail('Expected transport_exception was not thrown.');
        } catch (transport_exception $e) {
            $this->assertSame(500, $e->httpstatus);
        }
    }

    /**
     * Async track yields pending-confirmation; sync track yields indexed.
     *
     * @return void
     */
    public function test_track_resource_sync_and_async(): void {
        $async = new mock_client(true);
        $this->assertSame('pending-confirmation', $async->track_resource(['resource_id' => 'r1'])['status']);

        $sync = new mock_client(false);
        $this->assertSame('indexed', $sync->track_resource(['resource_id' => 'r1'])['status']);
    }

    /**
     * A configured failure status raises a transport_exception carrying it.
     *
     * @return void
     */
    public function test_simulated_failure_raises_transport_exception(): void {
        $client = new mock_client(true, 429);

        try {
            $client->search_grounded('q', 'pseudonym-1', ['site:1:mod_page:2:main']);
            $this->fail('Expected transport_exception was not thrown.');
        } catch (transport_exception $e) {
            $this->assertSame(429, $e->httpstatus);
        }
    }

    /**
     * The factory returns the mock when mock mode is enabled.
     *
     * @return void
     */
    public function test_factory_returns_mock_in_mock_mode(): void {
        $this->resetAfterTest(true);
        set_config('mockmode', 1, 'local_tarsus');

        $this->assertInstanceOf(mock_client::class, client_factory::get_client());
    }

    /**
     * The factory returns the real client skeleton when mock mode is off.
     *
     * @return void
     */
    public function test_factory_returns_http_client_when_mock_off(): void {
        $this->resetAfterTest(true);
        set_config('mockmode', 0, 'local_tarsus');

        $this->assertInstanceOf(http_client::class, client_factory::get_client());
    }
}
