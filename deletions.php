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

/**
 * Admin page for deletions the brain has not confirmed yet.
 *
 * Content removed from Moodle while the brain was unreachable leaves a row
 * here. Nothing else can reach it — the activity it belonged to is gone — so
 * this page is where support can see the backlog and push it through.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_tarsus\index\deletion_sweeper;

admin_externalpage_setup('local_tarsus_deletions');

$pageurl = new moodle_url('/local/tarsus/deletions.php');

if (optional_param('action', '', PARAM_ALPHA) === 'retry') {
    require_sesskey();
    require_capability('moodle/site:config', context_system::instance());

    $cleared = deletion_sweeper::run();

    redirect(
        $pageurl,
        get_string('deletions_retried', 'local_tarsus', $cleared),
        null,
        $cleared > 0
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING
    );
}

$outstanding = deletion_sweeper::outstanding();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('deletions', 'local_tarsus'));

if ($outstanding === []) {
    echo $OUTPUT->notification(
        get_string('deletions_none', 'local_tarsus'),
        \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    echo $OUTPUT->notification(
        get_string('deletions_pending', 'local_tarsus', count($outstanding)),
        \core\output\notification::NOTIFY_WARNING
    );

    $table = new html_table();
    $table->head = [
        get_string('deletions_resource', 'local_tarsus'),
        get_string('deletions_since', 'local_tarsus'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($outstanding as $row) {
        $table->data[] = [
            s($row->resourceid),
            userdate((int) $row->timemodified),
        ];
    }

    echo html_writer::table($table);

    echo $OUTPUT->single_button(
        new moodle_url($pageurl, ['action' => 'retry', 'sesskey' => sesskey()]),
        get_string('deletions_retry', 'local_tarsus'),
        'post'
    );
}

echo $OUTPUT->footer();
