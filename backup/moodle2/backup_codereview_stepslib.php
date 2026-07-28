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

/**
 * Backup structure step for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Writes the activity and its user data into the backup file.
 *
 * Every column of every table is listed here on purpose. A column added to the
 * schema later and forgotten in these lists is not reported by any tool: it simply
 * comes back as its database default on every restore, and only surfaces when
 * someone notices real data missing after a real restore.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_codereview_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the structure to be written.
     *
     * @return backup_nested_element
     */
    protected function define_structure(): backup_nested_element {
        $userinfo = $this->get_setting_value('userinfo');

        $codereview = new backup_nested_element('codereview', ['id'], [
            'name', 'intro', 'introformat', 'grade', 'weighttests', 'weightai',
            'citimeout', 'rubric', 'rubricformat', 'templaterepourl', 'integritychecks',
            'duedate', 'cutoffdate', 'completionsubmit', 'completionchecks',
            'tokenuserid', 'teamsubmission', 'teamsubmissiongroupingid',
            'timecreated', 'timemodified',
        ]);

        $submissions = new backup_nested_element('submissions');
        $submission = new backup_nested_element('submission', ['id'], [
            'userid', 'groupid', 'repourl', 'repoowner', 'reponame', 'commitsha',
            'commitauthordate', 'repocreatedat', 'repopushedat', 'isfork', 'forkparent',
            'authorlogin', 'cistatus', 'aistatus', 'gradestatus', 'islate', 'truncated',
            'errormessage', 'timecreated', 'timemodified',
        ]);

        $checkruns = new backup_nested_element('checkruns');
        $checkrun = new backup_nested_element('checkrun', ['id'], [
            'externalid', 'checkname', 'appslug', 'conclusion', 'counted',
            'detailsurl', 'startedat', 'completedat', 'timecreated',
        ]);

        $airesults = new backup_nested_element('airesults');
        $airesult = new backup_nested_element('airesult', ['id'], [
            'provider', 'model', 'suggestedgrade', 'feedback', 'feedbackformat',
            'status', 'errormessage', 'timecreated',
        ]);

        $commits = new backup_nested_element('commits');
        $commit = new backup_nested_element('commit', ['id'], ['sha', 'position', 'timecreated']);

        $grades = new backup_nested_element('grades');
        $grade = new backup_nested_element('grade', ['id'], [
            'graderid', 'suggestedgrade', 'finalgrade', 'feedbackcomment',
            'feedbackformat', 'timecreated', 'timemodified',
        ]);

        // Blobs and flags sit under the activity rather than under a submission: the
        // baseline blobs belong to no submission at all, and a flag names a second one.
        $blobs = new backup_nested_element('blobs');
        $blob = new backup_nested_element('blob', ['id'], [
            'submission', 'path', 'blobsha', 'filesize', 'timecreated',
        ]);

        $flags = new backup_nested_element('flags');
        $flag = new backup_nested_element('flag', ['id'], [
            'submission', 'flagtype', 'severity', 'peersubmission', 'detail', 'timecreated',
        ]);

        $codereview->add_child($submissions);
        $submissions->add_child($submission);

        $submission->add_child($checkruns);
        $checkruns->add_child($checkrun);
        $submission->add_child($airesults);
        $airesults->add_child($airesult);
        $submission->add_child($commits);
        $commits->add_child($commit);
        $submission->add_child($grades);
        $grades->add_child($grade);

        $codereview->add_child($blobs);
        $blobs->add_child($blob);
        $codereview->add_child($flags);
        $flags->add_child($flag);

        $codereview->set_source_table('codereview', ['id' => backup::VAR_ACTIVITYID]);

        if ($userinfo) {
            $submission->set_source_table('codereview_submissions', ['codereview' => backup::VAR_PARENTID]);
            $checkrun->set_source_table('codereview_checkruns', ['submission' => backup::VAR_PARENTID]);
            $airesult->set_source_table('codereview_airesults', ['submission' => backup::VAR_PARENTID]);
            $commit->set_source_table('codereview_commits', ['submission' => backup::VAR_PARENTID]);
            $grade->set_source_table('codereview_grades', ['submission' => backup::VAR_PARENTID]);
            $blob->set_source_table('codereview_blobs', ['codereview' => backup::VAR_PARENTID]);

            $flag->set_source_sql(
                'SELECT f.*
                   FROM {codereview_flags} f
                   JOIN {codereview_submissions} s ON s.id = f.submission
                  WHERE s.codereview = ?',
                [backup::VAR_PARENTID]
            );
        }

        $submission->annotate_ids('user', 'userid');
        $submission->annotate_ids('group', 'groupid');
        $grade->annotate_ids('user', 'graderid');
        $codereview->annotate_ids('user', 'tokenuserid');
        $codereview->annotate_files('mod_codereview', 'intro', null);

        return $this->prepare_activity_structure($codereview);
    }
}
