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

namespace mod_codereview\event;

use advanced_testcase;
use mod_codereview\local\submission_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests that restoring a backup with logs points the logged events at the restored rows.
 *
 * Without a mapping the core prints a debugging message for every restored log record and copies
 * the id as it was, which may now name another row. The check is end to end: real events, a real
 * backup with logs and a real restore into a second course.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\event\course_module_viewed
 * @covers     \mod_codereview\event\grade_approved
 * @covers     \mod_codereview\event\repo_submitted
 * @covers     \mod_codereview\event\submission_reopened
 */
final class restore_mappings_test extends advanced_testcase {
    /**
     * Backs the course up with its logs and restores it into a new course.
     *
     * @param \stdClass $course Source course.
     * @return \stdClass The course the backup was restored into.
     */
    private function backup_with_logs_and_restore(\stdClass $course): \stdClass {
        global $CFG;

        $admin = get_admin();
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id
        );
        $bc->get_plan()->get_setting('logs')->set_value(true);
        $bc->execute_plan();
        $backupfile = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $newcourse = $this->getDataGenerator()->create_course();
        $tempdir = \restore_controller::get_tempdir_name($newcourse->id, $admin->id);
        $backupfile->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            make_backup_temp_directory($tempdir)
        );
        $rc = new \restore_controller(
            $tempdir,
            $newcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        return $newcourse;
    }

    /**
     * Every event of the activity is restored pointing at the restored activity or submission.
     */
    public function test_restored_logs_point_at_the_restored_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);
        // The events are triggered by an enrolled teacher: a log record whose user is not in the backup
        // is skipped on restore, with or without a mapping.
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // A row before the one that matters, so that the restored ids cannot equal the original ones.
        $DB->insert_record('codereview_submissions', $this->submission_record($module->id, (int) $first->id, 'first'));
        $submissionid = $DB->insert_record(
            'codereview_submissions',
            $this->submission_record($module->id, (int) $second->id, 'second')
        );
        $submission = $DB->get_record('codereview_submissions', ['id' => $submissionid], '*', MUST_EXIST);
        $context = \context_module::instance(get_coursemodule_from_instance('codereview', $module->id)->id);

        $this->setUser($teacher);
        course_module_viewed::create(['objectid' => $module->id, 'context' => $context])->trigger();
        repo_submitted::create_from_submission($context, $submission)->trigger();
        grade_approved::create_from_submission($context, $submission, 80.0)->trigger();
        submission_reopened::create_from_submission($context, $submission)->trigger();
        $this->setAdminUser();

        $newcourse = $this->backup_with_logs_and_restore($course);

        $newmodule = $DB->get_record('codereview', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newsubmissions = $DB->get_records('codereview_submissions', ['codereview' => $newmodule->id], 'id ASC');
        $newsubmission = end($newsubmissions);
        $this->assertCount(2, $newsubmissions);
        $this->assertNotEquals($submissionid, $newsubmission->id);

        $expected = [
            course_module_viewed::class => $newmodule->id,
            repo_submitted::class => $newsubmission->id,
            grade_approved::class => $newsubmission->id,
            submission_reopened::class => $newsubmission->id,
        ];
        foreach ($expected as $eventclass => $objectid) {
            $restored = $DB->get_record('logstore_standard_log', [
                'eventname' => '\\' . $eventclass,
                'courseid' => $newcourse->id,
            ], '*', MUST_EXIST);
            $this->assertEquals($objectid, $restored->objectid, $eventclass);
        }
    }

    /**
     * Builds a submission row.
     *
     * @param int $codereviewid The activity.
     * @param int $userid The student.
     * @param string $owner Repository owner, also used to make the commit hash unique.
     * @return \stdClass
     */
    private function submission_record(int $codereviewid, int $userid, string $owner): \stdClass {
        return (object) [
            'codereview' => $codereviewid,
            'userid' => $userid,
            'repourl' => 'https://github.com/' . $owner . '/assignment',
            'repoowner' => $owner,
            'reponame' => 'assignment',
            'commitsha' => str_pad($owner, 40, '0'),
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_COMPLETED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
    }
}
