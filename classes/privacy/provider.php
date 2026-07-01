<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy Subsystem implementation for local_stackhinter.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider: the plugin stores a per-user hint log (local_stackhinter_hints) and, for the
 * server-side AI backends, discloses the question text, the student's answer and the grader feedback to
 * the configured external AI provider. The on-device backend sends nothing externally (the model runs in
 * the student's browser); only the one-time model download leaves the browser.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored and disclosed by this plugin.
     *
     * @param collection $collection The metadata collection to add items to.
     * @return collection The populated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_stackhinter_hints', [
            'userid'      => 'privacy:metadata:hints:userid',
            'cmid'        => 'privacy:metadata:hints:cmid',
            'attempt'     => 'privacy:metadata:hints:attempt',
            'question'    => 'privacy:metadata:hints:question',
            'answer'      => 'privacy:metadata:hints:answer',
            'feedback'    => 'privacy:metadata:hints:feedback',
            'hint'        => 'privacy:metadata:hints:hint',
            'provider'    => 'privacy:metadata:hints:provider',
            'timecreated' => 'privacy:metadata:hints:timecreated',
        ], 'privacy:metadata:hints');

        // The external-provider disclosure below applies to the "own" and "core" AI backends only. With
        // the on-device backend the model runs in the student's browser (WebGPU) and none of this data is
        // sent to any external AI provider; only the model files are downloaded once from a public CDN,
        // and that request contains no personal data. See the privacy:metadata:provider summary string.
        $collection->add_external_location_link('aiprovider', [
            'question'  => 'privacy:metadata:provider:question',
            'answer'    => 'privacy:metadata:provider:answer',
            'feedback'  => 'privacy:metadata:provider:feedback',
            'diagnosis' => 'privacy:metadata:provider:diagnosis',
        ], 'privacy:metadata:provider');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contexts in which the user has hint data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {local_stackhinter_hints} h
                  JOIN {context} ctx ON ctx.instanceid = h.cmid AND ctx.contextlevel = :cl
                 WHERE h.userid = :userid";
        $contextlist->add_from_sql($sql, ['cl' => CONTEXT_MODULE, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_module) {
            $userlist->add_from_sql(
                'userid',
                "SELECT userid FROM {local_stackhinter_hints} WHERE cmid = :cmid",
                ['cmid' => $context->instanceid]
            );
        }
    }

    /**
     * Export all hint data for the approved contexts of a user.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $records = $DB->get_records('local_stackhinter_hints', ['cmid' => $context->instanceid, 'userid' => $userid]);
            if ($records) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_stackhinter')],
                    (object) ['hints' => array_values($records)]
                );
            }
        }
    }

    /**
     * Delete all hint data for all users in a context.
     *
     * @param \context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context instanceof \context_module) {
            $DB->delete_records('local_stackhinter_hints', ['cmid' => $context->instanceid]);
        }
    }

    /**
     * Delete hint data for a user across the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module) {
                $DB->delete_records('local_stackhinter_hints', ['cmid' => $context->instanceid, 'userid' => $userid]);
            }
        }
    }

    /**
     * Delete hint data for several users within a single context.
     *
     * @param approved_userlist $userlist The approved users to delete for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params['cmid'] = $context->instanceid;
        $DB->delete_records_select('local_stackhinter_hints', "cmid = :cmid AND userid $insql", $params);
    }
}
