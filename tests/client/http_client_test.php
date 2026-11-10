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
 * Tests for how the HTTP client reads the brain's learn-mode widgets.
 *
 * @package    local_tarsus
 * @covers     \local_tarsus\client\http_client
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class http_client_test extends \advanced_testcase {
    /**
     * Call the private learn-mode widget reader.
     *
     * @param array $widget One element of the brain's widgets list.
     * @return array[] The feed items.
     */
    private function learn_items(array $widget): array {
        $method = new \ReflectionMethod(http_client::class, 'learn_items');
        return $method->invoke(null, $widget);
    }

    /**
     * A stacked slide as the brain sends it.
     *
     * @param string $id   The widget id.
     * @param string $type The bit type.
     * @param array  $data The bit data.
     * @return array The slide.
     */
    private function slide(string $id, string $type, array $data): array {
        return ['_id' => $id, 'state' => 'learn', 'bit' => ['type' => $type, 'data' => $data]];
    }

    /**
     * An interactive learning step wrapped in a stack becomes a web item.
     *
     * @return void
     */
    public function test_stacked_web_widget_becomes_web_item(): void {
        $items = $this->learn_items(['stackId' => 'stack-1', 'stackedWidgets' => [
            $this->slide('w1', 'genericWebWidget', [
                'stepId' => 's1', 'taxonomyLevel' => '2', 'title' => 'Orbits', 'text' => 'Summary',
                'code' => '<!DOCTYPE html><html><body>Orbits</body></html>',
            ]),
        ]]);

        $this->assertCount(1, $items);
        $this->assertSame('web', $items[0]['kind']);
        $this->assertSame('w1', $items[0]['id']);
        $this->assertSame('learn', $items[0]['origin']);
        $this->assertSame('Orbits', $items[0]['title']);
        $this->assertStringContainsString('<body>Orbits</body>', $items[0]['code']);
    }

    /**
     * Web slides leave a mixed stack in order; the other slides stay stacked.
     *
     * @return void
     */
    public function test_mixed_stack_is_split_in_order(): void {
        $items = $this->learn_items(['stackId' => 'stack-2', 'stackedWidgets' => [
            $this->slide('t1', 'microLearningTextWidget', ['title' => 'One', 'text' => 'First']),
            $this->slide('w1', 'genericWebWidget', ['stepId' => 's1', 'code' => '<html></html>']),
            $this->slide('t2', 'microLearningTextWidget', ['title' => 'Two', 'text' => 'Second']),
        ]]);

        $this->assertSame(['stack', 'web', 'stack'], array_column($items, 'kind'));
        $this->assertSame('stack-2', $items[0]['id']);
        $this->assertSame(['t1'], array_column($items[0]['slides'], 'id'));
        $this->assertSame(['t2'], array_column($items[2]['slides'], 'id'));
    }

    /**
     * A plain stack stays one item, and a web widget without a document
     * falls back to a text slide.
     *
     * @return void
     */
    public function test_plain_stack_is_untouched(): void {
        $items = $this->learn_items(['stackId' => 'stack-3', 'stackedWidgets' => [
            $this->slide('t1', 'microLearningTextWidget', ['title' => 'One', 'text' => 'First']),
            $this->slide('w1', 'genericWebWidget', ['title' => 'Two', 'text' => 'Second', 'code' => '']),
        ]]);

        $this->assertSame(['stack'], array_column($items, 'kind'));
        $this->assertSame(['t1', 'w1'], array_column($items[0]['slides'], 'id'));
        $this->assertSame([], $this->learn_items(['bit' => ['type' => 'welcomeWidget', 'text' => 'Hi']]));
    }

    /**
     * Call a private static reader of the HTTP client.
     *
     * @param string $name The method name.
     * @param array  $args Its arguments.
     * @return mixed The result.
     */
    private function read(string $name, array $args) {
        $method = new \ReflectionMethod(http_client::class, $name);
        return $method->invokeArgs(null, $args);
    }

    /**
     * A table question keeps the brain's own columns, however many.
     *
     * @return void
     */
    public function test_question_table_follows_the_brain_columns(): void {
        $data = ['questionTable' => [
            ['Himmelskörper' => 'Pluto', 'Einordnung' => '?'],
            ['Himmelskörper' => 'Ceres', 'Einordnung' => '?', 'Entdeckt' => '1801'],
        ]];

        $this->assertSame(['Himmelskörper', 'Einordnung', 'Entdeckt'], $this->read('model_columns', [$data]));
        $table = $this->read('question_table', [$data]);
        $this->assertCount(2, $table);
        $this->assertSame(['Pluto', '?', ''], array_column($table[0]['cells'], 'value'));
        $this->assertSame([false, true, false], array_column($table[0]['cells'], 'ask'));
        $this->assertSame('1801', $table[1]['cells'][2]['value']);
    }

    /**
     * The verdicts follow the evaluation mask by column name; the row
     * clarification is not a column, and stray keys of the learner's table
     * are dropped.
     *
     * @return void
     */
    public function test_table_verdicts_follow_the_mask(): void {
        $data = [
            'questionTable' => [['Position' => 'Listenpreis', 'Satz' => '', 'Betrag' => '200'],
                ['Position' => 'Rabatt', 'Satz' => '?', 'Betrag' => '?']],
            'evaluation' => [
                'evaluated' => true,
                'answerTable' => [['Position' => 'Listenpreis', 'Satz' => '', 'Betrag' => '200', 'clarification' => 'Given.'],
                    ['Position' => 'Rabatt', 'Satz' => '10 %', 'Betrag' => '20', 'clarification' => 'Ten percent.']],
                'evaluationMask' => [['Position' => 0, 'Satz' => 0, 'Betrag' => 0], ['Position' => 0, 'Satz' => 1, 'Betrag' => -1]],
                'userTable' => [['Position' => 'Listenpreis', 'Satz' => '', 'Betrag' => '200', '' => ''],
                    ['Position' => 'Rabatt', 'Satz' => '10 %', 'Betrag' => '25', '' => '']],
            ],
        ];

        $model = $this->read('table_verdicts', [$data]);
        $this->assertSame(['Rabatt', '10 %', '20'], array_column($model[1]['cells'], 'value'));
        $this->assertSame([0, 1, -1], array_column($model[1]['cells'], 'verdict'));
        $user = $this->read('user_table', [$data]);
        $this->assertSame(['Rabatt', '10 %', '25'], array_column($user[1]['cells'], 'value'));
    }
}
