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

namespace local_tarsus\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_tarsus.
 *
 * Declares the local usage ledger and the external transmission of a
 * pseudonymized user id and query text to the Tarsus RAG engine, and
 * implements export/delete for the locally stored user data.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_tarsus_usage', [
            'contextid' => 'privacy:metadata:local_tarsus_usage:contextid',
            'userid' => 'privacy:metadata:local_tarsus_usage:userid',
            'operation' => 'privacy:metadata:local_tarsus_usage:operation',
            'credits' => 'privacy:metadata:local_tarsus_usage:credits',
            'timecreated' => 'privacy:metadata:local_tarsus_usage:timecreated',
        ], 'privacy:metadata:local_tarsus_usage');

        $collection->add_external_location_link('tarsus', [
            'userid' => 'privacy:metadata:tarsus:userid',
            'username' => 'privacy:metadata:tarsus:username',
            'language' => 'privacy:metadata:tarsus:language',
            'query' => 'privacy:metadata:tarsus:query',
        ], 'privacy:metadata:tarsus');

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            'SELECT DISTINCT contextid FROM {local_tarsus_usage} WHERE userid = :userid',
            ['userid' => $userid]
        );

        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {local_tarsus_usage} WHERE contextid = :contextid',
            ['contextid' => $context->id]
        );
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $records = $DB->get_records('local_tarsus_usage', [
                'contextid' => $context->id,
                'userid' => $userid,
            ]);

            if (!$records) {
                continue;
            }

            $data = array_map(static function ($record) {
                return [
                    'operation' => $record->operation,
                    'credits' => $record->credits,
                    'timecreated' => $record->timecreated,
                ];
            }, array_values($records));

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_tarsus')],
                (object) ['usage' => $data]
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        $DB->delete_records('local_tarsus_usage', ['contextid' => $context->id]);
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $DB->delete_records('local_tarsus_usage', [
                'contextid' => $context->id,
                'userid' => $userid,
            ]);
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (!$userids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;

        $DB->delete_records_select(
            'local_tarsus_usage',
            "contextid = :contextid AND userid {$insql}",
            $params
        );
    }
}
