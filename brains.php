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
 * Admin page listing the brains reachable by the configured API key.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_tarsus\client\transport_exception;
use local_tarsus\output\brain_list;

admin_externalpage_setup('local_tarsus_brains');

$action = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/local/tarsus/brains.php');

if ($action === 'activate') {
    require_sesskey();
    require_capability('moodle/site:config', context_system::instance());

    $brainid = required_param('brainid', PARAM_ALPHANUMEXT);

    try {
        $brains = brain_list::fetch();
    } catch (moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    // Only ever point the site at a brain the API key really reports as active.
    if (!$brains->can_activate($brainid)) {
        redirect($pageurl, get_string('brain_activate_failed', 'local_tarsus'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    set_config('brain_id', $brainid, 'local_tarsus');

    redirect($pageurl, get_string('brain_activated', 'local_tarsus', s($brainid)), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('brains', 'local_tarsus'));

try {
    echo $OUTPUT->render_from_template(
        'local_tarsus/brain_list',
        brain_list::fetch()->export_for_template($PAGE->get_renderer('core'))
    );
} catch (transport_exception | moodle_exception $e) {
    echo $OUTPUT->notification($e->getMessage(), \core\output\notification::NOTIFY_ERROR);
    echo $OUTPUT->single_button(
        new moodle_url('/admin/settings.php', ['section' => 'local_tarsus']),
        get_string('brain_gotosettings', 'local_tarsus'),
        'get'
    );
}

echo $OUTPUT->footer();
