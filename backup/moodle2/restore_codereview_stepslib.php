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
 * Restore structure step for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Reads the activity and its user data back out of a backup file.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_codereview_activity_structure_step extends restore_activity_structure_step {
    /** @var array New flag id mapped to the old submission id it pointed at. */
    protected array $pendingpeers = [];

    /**
     * Declares the paths this step restores.
     *
     * @return array
     */
    protected function define_structure(): array {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('codereview', '/activity/codereview');

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'codereview_submission',
                '/activity/codereview/submissions/submission'
            );
            $paths[] = new restore_path_element(
                'codereview_checkrun',
                '/activity/codereview/submissions/submission/checkruns/checkrun'
            );
            $paths[] = new restore_path_element(
                'codereview_airesult',
                '/activity/codereview/submissions/submission/airesults/airesult'
            );
            $paths[] = new restore_path_element(
                'codereview_commit',
                '/activity/codereview/submissions/submission/commits/commit'
            );
            $paths[] = new restore_path_element(
                'codereview_grade',
                '/activity/codereview/submissions/submission/grades/grade'
            );
            $paths[] = new restore_path_element('codereview_blob', '/activity/codereview/blobs/blob');
            $paths[] = new restore_path_element('codereview_flag', '/activity/codereview/flags/flag');
        }

        // Returning the prepared structure rather than the raw array is what registers
        // the old-to-new context mapping. Without it, duplicating the activity and
        // restoring a whole course both fail later in a generic step, with an error
        // that gives no hint the cause is here.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the activity instance.
     *
     * @param array $data The element data.
     * @return void
     */
    protected function process_codereview(array $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // The pointer is only meaningful for a user that exists on the target site;
        // otherwise the token chain falls back to the site level on its own.
        $data->tokenuserid = $this->get_mappingid('user', $data->tokenuserid) ?: 0;

        $newitemid = $DB->insert_record('codereview', $data);
        $this->apply_activity_instance($newitemid);
        $this->set_mapping('codereview', $oldid, $newitemid);
    }

    /**
     * Restores one submission.
     *
     * @param array $data The element data.
     * @return void
     */
    protected function process_codereview_submission(array $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->codereview = $this->get_new_parentid('codereview');
        $data->userid = $this->get_mappingid('user', $data->userid);

        // Groups are restored by an earlier task, so the mapping already exists here.
        // Falling back to zero rather than to the old id keeps a stale reference to
        // another course's group out of the table: a submission that loses its group
        // reads as individual, which is wrong but harmless, where a foreign group id
        // would silently attach the work to strangers.
        $data->groupid = empty($data->groupid) ? 0 : ((int) $this->get_mappingid('group', $data->groupid) ?: 0);

        $newitemid = $DB->insert_record('codereview_submissions', $data);
        $this->set_mapping('codereview_submission', $oldid, $newitemid);
    }

    /**
     * Restores one check-run.
     *
     * @param array $data The element data.
     * @return void
     */
    protected function process_codereview_checkrun(array $data): void {
        global $DB;

        $data = (object) $data;
        $data->submission = $this->get_new_parentid('codereview_submission');

        $DB->insert_record('codereview_checkruns', $data);
    }

    /**
     * Restores one AI suggestion.
     *
     * @param array $data The element data.
     * @return void
     */
    protected function process_codereview_airesult(array $data): void {
        global $DB;

        $data = (object) $data;
        $data->submission = $this->get_new_parentid('codereview_submission');

        $DB->insert_record('codereview_airesults', $data);
    }

    /**
     * Restores one ancestor commit hash.
     *
     * @param array $data The element data.
     * @return void
     */
    protected function process_codereview_commit(array $data): void {
        global $DB;

        $data = (object) $data;
        $data->submission = $this->get_new_parentid('codereview_submission');
        $data->codereview = $this->get_new_parentid('codereview');

        $DB->insert_record('codereview_commits', $data);
    }

    /**
     * Restores one approved grade.
     *
     * @param array $data The element data.
     * @return void
     */
    protected function process_codereview_grade(array $data): void {
        global $DB;

        $data = (object) $data;
        $data->submission = $this->get_new_parentid('codereview_submission');
        $data->graderid = $this->get_mappingid('user', $data->graderid) ?: 0;

        $DB->insert_record('codereview_grades', $data);
    }

    /**
     * Restores one content fingerprint.
     *
     * @param array $data The element data.
     * @return void
     */
    protected function process_codereview_blob(array $data): void {
        global $DB;

        $data = (object) $data;
        $data->codereview = $this->get_new_parentid('codereview');

        // Zero is the template baseline marker rather than a submission id, so it must
        // survive untouched: remapping it would either fail or point the baseline at a
        // student's files.
        $data->submission = $data->submission > 0
            ? (int) $this->get_mappingid('codereview_submission', $data->submission)
            : 0;

        $DB->insert_record('codereview_blobs', $data);
    }

    /**
     * Restores one authorship signal.
     *
     * @param array $data The element data.
     * @return void
     */
    protected function process_codereview_flag(array $data): void {
        global $DB;

        $data = (object) $data;
        $oldpeer = (int) $data->peersubmission;

        $data->submission = (int) $this->get_mappingid('codereview_submission', $data->submission);
        $data->peersubmission = null;

        $newitemid = $DB->insert_record('codereview_flags', $data);

        // The peer is another submission in this same activity, and the flags element
        // is a sibling of submissions rather than a child, so some peers have already
        // been mapped and others have not. Deferring every one of them to the end of
        // the step avoids depending on which.
        if ($oldpeer > 0) {
            $this->pendingpeers[$newitemid] = $oldpeer;
        }
    }

    /**
     * Resolves the deferred peer references once every submission has been restored.
     *
     * @return void
     */
    protected function after_execute(): void {
        global $DB;

        $this->add_related_files('mod_codereview', 'intro', null);

        foreach ($this->pendingpeers as $flagid => $oldpeer) {
            $newpeer = $this->get_mappingid('codereview_submission', $oldpeer);

            if ($newpeer) {
                $DB->set_field('codereview_flags', 'peersubmission', $newpeer, ['id' => $flagid]);
                continue;
            }

            // The peer was not part of this backup, so the evidence cannot be pointed
            // at anything. The flag is dropped rather than left dangling: a signal
            // that names a submission nobody can open is worse than no signal.
            $DB->delete_records('codereview_flags', ['id' => $flagid]);
        }

        $this->pendingpeers = [];
    }
}
