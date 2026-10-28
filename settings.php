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
 * Admin settings for local_tarsus.
 *
 * Single place to connect the plugin to Tarsus and to bound traffic and
 * credit spend. No per-course or per-brain management (one brain per client).
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // One node in the admin tree holding every Tarsus page, rather than three
    // siblings scattered among the other local plugins.
    $ADMIN->add('localplugins', new admin_category(
        'local_tarsus_category',
        get_string('pluginname', 'local_tarsus')
    ));

    // Keep the page name: /admin/settings.php?section=local_tarsus is what
    // the plugin overview and existing links point at.
    $settings = new admin_settingpage('local_tarsus', get_string('settings'));
    $ADMIN->add('local_tarsus_category', $settings);

    $ADMIN->add('local_tarsus_category', new admin_externalpage(
        'local_tarsus_brains',
        get_string('brains', 'local_tarsus'),
        new moodle_url('/local/tarsus/brains.php')
    ));

    $ADMIN->add('local_tarsus_category', new admin_externalpage(
        'local_tarsus_deletions',
        get_string('deletions', 'local_tarsus'),
        new moodle_url('/local/tarsus/deletions.php')
    ));

    $settings->add(new admin_setting_heading(
        'local_tarsus/connection',
        get_string('settings_connection', 'local_tarsus'),
        get_string('settings_connection_desc', 'local_tarsus')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_tarsus/api_key',
        get_string('apikey', 'local_tarsus'),
        get_string('apikey_desc', 'local_tarsus'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_tarsus/brain_id',
        get_string('brainid', 'local_tarsus'),
        get_string('brainid_desc', 'local_tarsus'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_tarsus/base_url',
        get_string('baseurl', 'local_tarsus'),
        get_string('baseurl_desc', 'local_tarsus'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_description(
        'local_tarsus/brainpicker',
        get_string('brains', 'local_tarsus'),
        get_string('brains_desc', 'local_tarsus', (new moodle_url('/local/tarsus/brains.php'))->out())
    ));

    $settings->add(new admin_setting_heading(
        'local_tarsus/assistant',
        get_string('settings_assistant', 'local_tarsus'),
        get_string('settings_assistant_desc', 'local_tarsus')
    ));

    $settings->add(new admin_setting_configtext(
        'local_tarsus/robot_name',
        get_string('robotname', 'local_tarsus'),
        get_string('robotname_desc', 'local_tarsus'),
        'Tarsus',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_tarsus/robot_id',
        get_string('robotid', 'local_tarsus'),
        get_string('robotid_desc', 'local_tarsus'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_tarsus/robot_description',
        get_string('robotdescription', 'local_tarsus'),
        get_string('robotdescription_desc', 'local_tarsus'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configselect(
        'local_tarsus/voice_mode',
        get_string('voice_mode', 'local_tarsus'),
        get_string('voice_mode_desc', 'local_tarsus'),
        \local_tarsus\voices::MODE_AUTO,
        [
            \local_tarsus\voices::MODE_AUTO => get_string('voice_mode_auto', 'local_tarsus'),
            \local_tarsus\voices::MODE_FIXED => get_string('voice_mode_fixed', 'local_tarsus'),
        ]
    ));

    // The voice list comes from the brain (cached for a day); only the
    // settings page itself needs it, not every admin tree build.
    $voiceoptions = [];
    if ($ADMIN->fulltree) {
        $voiceoptions = \local_tarsus\voices::options();
    }
    $currentvoice = (string) get_config('local_tarsus', 'voice');
    if ($currentvoice !== '' && !isset($voiceoptions[$currentvoice])) {
        $voiceoptions = [$currentvoice => $currentvoice] + $voiceoptions;
    }
    if ($voiceoptions === []) {
        $voiceoptions = [\local_tarsus\voices::DEFAULT_VOICE => \local_tarsus\voices::DEFAULT_VOICE];
    }
    $settings->add(new admin_setting_configselect(
        'local_tarsus/voice',
        get_string('voice', 'local_tarsus'),
        get_string('voice_desc', 'local_tarsus'),
        \local_tarsus\voices::DEFAULT_VOICE,
        $voiceoptions
    ));

    $settings->add(new admin_setting_configstoredfile(
        'local_tarsus/robot_avatar',
        get_string('robotavatar', 'local_tarsus'),
        get_string('robotavatar_desc', 'local_tarsus'),
        'robotavatar',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['web_image']]
    ));

    $settings->add(new admin_setting_heading(
        'local_tarsus/guardrails',
        get_string('settings_guardrails', 'local_tarsus'),
        get_string('settings_guardrails_desc', 'local_tarsus')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_tarsus/mockmode',
        get_string('mockmode', 'local_tarsus'),
        get_string('mockmode_desc', 'local_tarsus'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_tarsus/killswitch',
        get_string('killswitch', 'local_tarsus'),
        get_string('killswitch_desc', 'local_tarsus'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_tarsus/ratelimit',
        get_string('ratelimit', 'local_tarsus'),
        get_string('ratelimit_desc', 'local_tarsus'),
        '60',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_tarsus/creditbudget',
        get_string('creditbudget', 'local_tarsus'),
        get_string('creditbudget_desc', 'local_tarsus'),
        '100000',
        PARAM_INT
    ));
}
