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

namespace mod_codereview\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_codereview\local\github_token;

/**
 * Privacy implementation for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Declares what the plugin stores and where it sends data.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('codereview_submissions', [
            'userid' => 'privacy:metadata:codereview_submissions:userid',
            'repourl' => 'privacy:metadata:codereview_submissions:repourl',
            'commitsha' => 'privacy:metadata:codereview_submissions:commitsha',
            'authorlogin' => 'privacy:metadata:codereview_submissions:authorlogin',
            'timecreated' => 'privacy:metadata:codereview_submissions:timecreated',
        ], 'privacy:metadata:codereview_submissions');

        $collection->add_database_table('codereview_checkruns', [
            'checkname' => 'privacy:metadata:codereview_checkruns:checkname',
            'conclusion' => 'privacy:metadata:codereview_checkruns:conclusion',
        ], 'privacy:metadata:codereview_checkruns');

        $collection->add_database_table('codereview_airesults', [
            'suggestedgrade' => 'privacy:metadata:codereview_airesults:suggestedgrade',
            'feedback' => 'privacy:metadata:codereview_airesults:feedback',
        ], 'privacy:metadata:codereview_airesults');

        $collection->add_database_table('codereview_blobs', [
            'path' => 'privacy:metadata:codereview_blobs:path',
            'blobsha' => 'privacy:metadata:codereview_blobs:blobsha',
        ], 'privacy:metadata:codereview_blobs');

        $collection->add_database_table('codereview_flags', [
            'flagtype' => 'privacy:metadata:codereview_flags:flagtype',
            'severity' => 'privacy:metadata:codereview_flags:severity',
        ], 'privacy:metadata:codereview_flags');

        $collection->add_database_table('codereview_grades', [
            'graderid' => 'privacy:metadata:codereview_grades:graderid',
            'finalgrade' => 'privacy:metadata:codereview_grades:finalgrade',
            'feedbackcomment' => 'privacy:metadata:codereview_grades:feedbackcomment',
        ], 'privacy:metadata:codereview_grades');

        $collection->add_user_preference(
            github_token::PREFERENCE,
            'privacy:metadata:preference:githubtoken'
        );

        $collection->add_external_location_link('github', [
            'repourl' => 'privacy:metadata:github:repourl',
            'commitsha' => 'privacy:metadata:github:commitsha',
        ], 'privacy:metadata:github');

        $collection->add_external_location_link('aiprovider', [
            'sourcecode' => 'privacy:metadata:aiprovider:sourcecode',
        ], 'privacy:metadata:aiprovider');

        return $collection;
    }

    /**
     * Returns the contexts holding data about a user.
     *
     * @param int $userid The user to look for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {codereview} c ON c.id = cm.instance
                  JOIN {codereview_submissions} s ON s.codereview = c.id
             LEFT JOIN {codereview_grades} g ON g.submission = s.id
                 WHERE s.userid = :userid OR g.graderid = :graderid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'codereview',
            'userid' => $userid,
            'graderid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Returns the users holding data in a context.
     *
     * @param userlist $userlist The list to populate.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof context_module) {
            return;
        }

        $params = ['instanceid' => $context->instanceid, 'modname' => 'codereview'];

        $userlist->add_from_sql('userid', "SELECT s.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {codereview} c ON c.id = cm.instance
                  JOIN {codereview_submissions} s ON s.codereview = c.id
                 WHERE cm.id = :instanceid", $params);

        $userlist->add_from_sql('graderid', "SELECT g.graderid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {codereview} c ON c.id = cm.instance
                  JOIN {codereview_submissions} s ON s.codereview = c.id
                  JOIN {codereview_grades} g ON g.submission = s.id
                 WHERE cm.id = :instanceid", $params);
    }

    /**
     * Exports the user's own data.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('codereview', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            $submission = $DB->get_record('codereview_submissions', [
                'codereview' => $cm->instance,
                'userid' => $userid,
            ]);

            if (!$submission) {
                continue;
            }

            writer::with_context($context)->export_data([], (object) [
                'repourl' => $submission->repourl,
                'commitsha' => $submission->commitsha,
                'authorlogin' => $submission->authorlogin,
                'islate' => transform::yesno($submission->islate),
                'cistatus' => $submission->cistatus,
                'aistatus' => $submission->aistatus,
                'gradestatus' => $submission->gradestatus,
                'timecreated' => transform::datetime($submission->timecreated),
                'checkruns' => self::export_checkruns((int) $submission->id),
                'airesults' => self::export_airesults((int) $submission->id),
                'grade' => self::export_grade((int) $submission->id),
                'authorshipsignals' => self::export_flags((int) $submission->id),
            ]);
        }
    }

    /**
     * Exports the automated check results of a submission.
     *
     * @param int $submissionid The submission.
     * @return array
     */
    protected static function export_checkruns(int $submissionid): array {
        global $DB;

        $runs = $DB->get_records('codereview_checkruns', ['submission' => $submissionid]);

        return array_values(array_map(static fn($run) => [
            'checkname' => $run->checkname,
            'conclusion' => $run->conclusion,
        ], $runs));
    }

    /**
     * Exports the AI suggestions of a submission.
     *
     * @param int $submissionid The submission.
     * @return array
     */
    protected static function export_airesults(int $submissionid): array {
        global $DB;

        $results = $DB->get_records('codereview_airesults', ['submission' => $submissionid]);

        return array_values(array_map(static fn($result) => [
            'suggestedgrade' => $result->suggestedgrade,
            'feedback' => $result->feedback,
            'timecreated' => transform::datetime($result->timecreated),
        ], $results));
    }

    /**
     * Exports the approved grade of a submission.
     *
     * @param int $submissionid The submission.
     * @return array
     */
    protected static function export_grade(int $submissionid): array {
        global $DB;

        $grade = $DB->get_record('codereview_grades', ['submission' => $submissionid]);

        if (!$grade) {
            return [];
        }

        return [
            'finalgrade' => $grade->finalgrade,
            'feedbackcomment' => $grade->feedbackcomment,
            'timemodified' => transform::datetime($grade->timemodified),
        ];
    }

    /**
     * Exports the authorship signals raised on a submission, without the peer.
     *
     * A signal is a statement about two submissions, so the record names another
     * student. That identity belongs to whoever holds the grading capability, not in
     * the subject access request of the person on the other side of the comparison:
     * exporting it would use one person's right of access to disclose someone else.
     *
     * @param int $submissionid The submission.
     * @return array
     */
    protected static function export_flags(int $submissionid): array {
        global $DB;

        $flags = $DB->get_records('codereview_flags', ['submission' => $submissionid]);

        return array_values(array_map(static fn($flag) => [
            'flagtype' => $flag->flagtype,
            'severity' => $flag->severity,
            'timecreated' => transform::datetime($flag->timecreated),
        ], $flags));
    }

    /**
     * Exports the user preferences the plugin stores.
     *
     * @param int $userid The user.
     * @return void
     */
    public static function export_user_preferences(int $userid): void {
        if (get_user_preferences(github_token::PREFERENCE, null, $userid) === null) {
            return;
        }

        // The value is a live credential to a third party account. Writing it into a
        // downloadable archive would work against the duty to keep it secure, so the
        // export records that it exists and what it is for, and nothing more.
        writer::export_user_preference(
            'mod_codereview',
            github_token::PREFERENCE,
            get_string('privacy:redacted', 'mod_codereview'),
            get_string('privacy:metadata:preference:githubtoken', 'mod_codereview')
        );
    }

    /**
     * Deletes everything held in a context.
     *
     * @param context $context The context being purged.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('codereview', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        self::delete_submissions($DB->get_fieldset_select(
            'codereview_submissions',
            'id',
            'codereview = ?',
            [$cm->instance]
        ));

        $DB->delete_records('codereview_blobs', ['codereview' => $cm->instance]);
        $DB->delete_records('codereview_commits', ['codereview' => $cm->instance]);
    }

    /**
     * Deletes the data of one user across the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('codereview', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            self::delete_submissions(self::deletable_submissions($cm->instance, [$userid]));
        }
    }

    /**
     * Deletes the data of several users in one context.
     *
     * @param approved_userlist $userlist The approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('codereview', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm || !$userlist->get_userids()) {
            return;
        }

        self::delete_submissions(self::deletable_submissions($cm->instance, $userlist->get_userids()));
    }

    /**
     * Returns the submissions that may be removed on behalf of the given users.
     *
     * A team submission is not one student's to erase. It carries the work of
     * everyone still in the group, and the group's other members did not ask to have
     * theirs deleted — so a group submission is only removed once nobody is left in
     * the group. Until then the row stays, and the departing student is disconnected
     * from it by the submitter field being reassigned to a remaining member.
     *
     * @param int $instanceid The codereview instance id.
     * @param int[] $userids The users whose data is being deleted.
     * @return int[] Submission ids that can be removed outright.
     */
    protected static function deletable_submissions(int $instanceid, array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['codereview'] = $instanceid;

        $candidates = $DB->get_records_select(
            'codereview_submissions',
            "codereview = :codereview AND userid $insql",
            $params,
            '',
            'id, userid, groupid'
        );

        $deletable = [];
        foreach ($candidates as $submission) {
            if (empty($submission->groupid)) {
                $deletable[] = (int) $submission->id;
                continue;
            }

            $remaining = array_diff(
                array_map('intval', array_keys(groups_get_members((int) $submission->groupid, 'u.id'))),
                array_map('intval', $userids)
            );

            if (!$remaining) {
                $deletable[] = (int) $submission->id;
                continue;
            }

            // Hand the row to someone who is staying, so it no longer names the
            // person whose data is being erased.
            $DB->set_field('codereview_submissions', 'userid', reset($remaining), ['id' => $submission->id]);
        }

        return $deletable;
    }

    /**
     * Removes a set of submissions and everything derived from them.
     *
     * Flags raised on other submissions that pointed at these are removed too: the
     * evidence they carry is about a repository that no longer exists here.
     *
     * @param array $submissionids The submissions to remove.
     * @return void
     */
    protected static function delete_submissions(array $submissionids): void {
        global $DB;

        if (!$submissionids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($submissionids);

        foreach (['codereview_checkruns', 'codereview_airesults', 'codereview_grades', 'codereview_blobs'] as $table) {
            $DB->delete_records_select($table, "submission $insql", $params);
        }

        $DB->delete_records_select('codereview_commits', "submission $insql", $params);
        $DB->delete_records_select('codereview_flags', "submission $insql", $params);
        $DB->delete_records_select('codereview_flags', "peersubmission $insql", $params);
        $DB->delete_records_select('codereview_submissions', "id $insql", $params);
    }
}
