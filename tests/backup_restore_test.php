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

namespace mod_codereview;

use advanced_testcase;
use backup;
use backup_controller;
use mod_codereview\local\fingerprint_service;
use mod_codereview\local\integrity_checker;
use mod_codereview\local\submission_service;
use restore_controller;
use restore_dbops;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests that a course backup carries the activity across intact.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_codereview_activity_structure_step
 * @covers     \restore_codereview_activity_structure_step
 */
final class backup_restore_test extends advanced_testcase {
    /**
     * Backs the course up and restores it into a new one.
     *
     * @param int $courseid The course to duplicate.
     * @param int $userid The user performing the operation.
     * @return int The new course id.
     */
    private function duplicate_course(int $courseid, int $userid): int {
        global $CFG;

        $backupid = 'cr' . uniqid();

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $courseid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $results['backup_destination']->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            $CFG->tempdir . '/backup/' . $backupid
        );
        $bc->destroy();

        $newcourseid = restore_dbops::create_new_course('Restored', 'RESTORED' . uniqid(), 1);

        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid,
            backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    /**
     * Every table travels, the foreign keys land on the restored rows, and the
     * template baseline keeps its zero marker rather than being pointed at a student.
     *
     * @return void
     */
    public function test_backup_and_restore_preserves_everything(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', [
            'course' => $course->id,
            'name' => 'Assignment one',
        ]);
        $instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);

        $ana = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bruno = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $anaid = $this->make_submission($instance, $ana, 'ana');
        $brunoid = $this->make_submission($instance, $bruno, 'bruno');

        $DB->insert_record('codereview_grades', (object) [
            'submission' => $anaid,
            'graderid' => $teacher->id,
            'suggestedgrade' => 50,
            'finalgrade' => 70,
            'feedbackcomment' => 'Well done',
            'feedbackformat' => FORMAT_PLAIN,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // The baseline belongs to the activity rather than to any submission.
        $DB->insert_record('codereview_blobs', (object) [
            'codereview' => $instance->id,
            'submission' => fingerprint_service::BASELINE_SUBMISSION,
            'path' => 'README.md',
            'blobsha' => 'templatesha',
            'filesize' => 5,
            'timecreated' => time(),
        ]);

        // A signal on Ana's submission that names Bruno's.
        $DB->insert_record('codereview_flags', (object) [
            'submission' => $anaid,
            'flagtype' => integrity_checker::FLAG_CONTENTOVERLAP,
            'severity' => 'high',
            'peersubmission' => $brunoid,
            'detail' => json_encode(['shared' => 3, 'total' => 4]),
            'timecreated' => time(),
        ]);

        // Backup and restore run as an administrator: restoring into a new course is a
        // site-level capability the course teacher does not hold.
        $newcourseid = $this->duplicate_course((int) $course->id, (int) get_admin()->id);

        $newinstance = $DB->get_record('codereview', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertSame('Assignment one', $newinstance->name);
        $this->assertNotEquals($instance->id, $newinstance->id);

        $submissions = $DB->get_records('codereview_submissions', ['codereview' => $newinstance->id]);
        $this->assertCount(2, $submissions);

        $newana = null;
        $newbruno = null;
        foreach ($submissions as $submission) {
            if ($submission->repoowner === 'ana') {
                $newana = $submission;
            } else {
                $newbruno = $submission;
            }
        }

        $this->assertSame((int) $ana->id, (int) $newana->userid);
        $this->assertSame(str_pad('ana', 40, '0'), $newana->commitsha);

        // Children follow their own submission, not the original ids.
        $this->assertSame(1, $DB->count_records('codereview_checkruns', ['submission' => $newana->id]));
        $this->assertSame(1, $DB->count_records('codereview_airesults', ['submission' => $newana->id]));
        $this->assertSame(1, $DB->count_records('codereview_commits', ['submission' => $newana->id]));

        $grade = $DB->get_record('codereview_grades', ['submission' => $newana->id]);
        $this->assertEqualsWithDelta(70.0, (float) $grade->finalgrade, 0.0001);
        $this->assertSame((int) $teacher->id, (int) $grade->graderid);

        // The peer reference points at the restored sibling, not at the original.
        $flag = $DB->get_record('codereview_flags', ['submission' => $newana->id]);
        $this->assertSame((int) $newbruno->id, (int) $flag->peersubmission);
        $this->assertNotEquals($brunoid, (int) $flag->peersubmission);

        // The baseline marker survives as zero rather than being remapped.
        $baseline = $DB->get_records('codereview_blobs', [
            'codereview' => $newinstance->id,
            'submission' => fingerprint_service::BASELINE_SUBMISSION,
        ]);
        $this->assertCount(1, $baseline);
        $this->assertSame('templatesha', reset($baseline)->blobsha);
    }

    /**
     * Creates a submission with one row in every child table.
     *
     * @param stdClass $instance The activity instance.
     * @param stdClass $user The submitting student.
     * @param string $owner The repository owner.
     * @return int The submission id.
     */
    private function make_submission(stdClass $instance, stdClass $user, string $owner): int {
        global $DB;

        $id = $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $instance->id,
            'userid' => $user->id,
            'repourl' => 'https://github.com/' . $owner . '/assignment',
            'repoowner' => $owner,
            'reponame' => 'assignment',
            'commitsha' => str_pad($owner, 40, '0'),
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_COMPLETED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('codereview_checkruns', (object) [
            'submission' => $id,
            'externalid' => $id,
            'checkname' => 'tests',
            'conclusion' => 'success',
            'counted' => 1,
            'timecreated' => time(),
        ]);

        $DB->insert_record('codereview_airesults', (object) [
            'submission' => $id,
            'provider' => 'stub',
            'model' => 'stub',
            'suggestedgrade' => 80,
            'feedback' => 'Feedback',
            'feedbackformat' => FORMAT_PLAIN,
            'status' => 'completed',
            'timecreated' => time(),
        ]);

        $DB->insert_record('codereview_commits', (object) [
            'submission' => $id,
            'codereview' => $instance->id,
            'sha' => $owner . 'commit',
            'position' => 0,
            'timecreated' => time(),
        ]);

        $DB->insert_record('codereview_blobs', (object) [
            'codereview' => $instance->id,
            'submission' => $id,
            'path' => 'main.py',
            'blobsha' => sha1($owner),
            'filesize' => 10,
            'timecreated' => time(),
        ]);

        return $id;
    }
}
