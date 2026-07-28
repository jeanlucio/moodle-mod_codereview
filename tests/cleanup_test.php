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
use mod_codereview\local\fingerprint_service;
use mod_codereview\local\integrity_checker;
use mod_codereview\local\submission_service;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/codereview/lib.php');

/**
 * Tests that removing an activity or resetting a course cleans up after itself,
 * and that neither reaches beyond what it was asked to remove.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\submission_service
 */
final class cleanup_test extends advanced_testcase {
    /** @var string[] Every table the activity owns below the instance. */
    private const CHILD_TABLES = [
        'codereview_submissions',
        'codereview_checkruns',
        'codereview_airesults',
        'codereview_commits',
        'codereview_blobs',
        'codereview_flags',
        'codereview_grades',
    ];

    /**
     * Creates an instance holding one row in every child table.
     *
     * @param stdClass $course The course to create it in.
     * @return stdClass The instance record with its cmid attached.
     */
    private function populated_instance(stdClass $course): stdClass {
        global $DB;

        $module = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);
        $instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);
        $instance->cmid = $module->cmid;

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $submissionid = $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $instance->id,
            'userid' => $student->id,
            'repourl' => 'https://github.com/octocat/hello-world',
            'repoowner' => 'octocat',
            'reponame' => 'hello-world',
            'commitsha' => str_repeat('a', 40),
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_COMPLETED,
            'gradestatus' => submission_service::GRADE_GRADED,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $rows = [
            'codereview_checkruns' => ['submission' => $submissionid, 'externalid' => 1,
                'checkname' => 'tests', 'conclusion' => 'success', 'counted' => 1, 'timecreated' => time()],
            'codereview_airesults' => ['submission' => $submissionid, 'provider' => 'stub', 'model' => 'stub',
                'suggestedgrade' => 80, 'feedback' => 'x', 'feedbackformat' => FORMAT_PLAIN,
                'status' => 'completed', 'timecreated' => time()],
            'codereview_commits' => ['submission' => $submissionid, 'codereview' => $instance->id,
                'sha' => 'abc', 'position' => 0, 'timecreated' => time()],
            'codereview_flags' => ['submission' => $submissionid,
                'flagtype' => integrity_checker::FLAG_IMPORTEDHISTORY, 'severity' => 'warning',
                'peersubmission' => null, 'detail' => '{}', 'timecreated' => time()],
            'codereview_grades' => ['submission' => $submissionid, 'graderid' => $teacher->id,
                'suggestedgrade' => 50, 'finalgrade' => 70, 'feedbackcomment' => '',
                'feedbackformat' => FORMAT_PLAIN, 'timecreated' => time(), 'timemodified' => time()],
        ];

        foreach ($rows as $table => $row) {
            $DB->insert_record($table, (object) $row);
        }

        foreach ([$submissionid, fingerprint_service::BASELINE_SUBMISSION] as $owner) {
            $DB->insert_record('codereview_blobs', (object) [
                'codereview' => $instance->id,
                'submission' => $owner,
                'path' => 'main.py',
                'blobsha' => sha1((string) $owner),
                'filesize' => 10,
                'timecreated' => time(),
            ]);
        }

        return $instance;
    }

    /**
     * Counts the rows an instance owns across every child table.
     *
     * @param int $instanceid The instance.
     * @return int
     */
    private function count_rows(int $instanceid): int {
        global $DB;

        $submissionids = $DB->get_fieldset_select('codereview_submissions', 'id', 'codereview = ?', [$instanceid]);
        $total = count($submissionids);

        $total += $DB->count_records('codereview_blobs', ['codereview' => $instanceid]);
        $total += $DB->count_records('codereview_commits', ['codereview' => $instanceid]);

        if ($submissionids) {
            [$insql, $params] = $DB->get_in_or_equal($submissionids);
            foreach (['codereview_checkruns', 'codereview_airesults', 'codereview_flags', 'codereview_grades'] as $t) {
                $total += $DB->count_records_select($t, "submission $insql", $params);
            }
        }

        return $total;
    }

    /**
     * Deleting an instance empties every child table, and only for that instance.
     *
     * The hook existing is not the same as the hook being complete: this counts the
     * tables rather than trusting that each was remembered.
     *
     * @return void
     */
    public function test_delete_instance_removes_every_child_table(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $target = $this->populated_instance($course);
        $bystander = $this->populated_instance($course);

        $this->assertGreaterThan(0, $this->count_rows((int) $target->id));
        $before = $this->count_rows((int) $bystander->id);

        codereview_delete_instance((int) $target->id);

        $this->assertFalse($DB->record_exists('codereview', ['id' => $target->id]));
        $this->assertSame(0, $this->count_rows((int) $target->id));
        $this->assertSame($before, $this->count_rows((int) $bystander->id));

        foreach (self::CHILD_TABLES as $table) {
            $this->assertSame(
                0,
                $DB->count_records_select($table, '1 = 1 AND ' . self::orphan_clause($table, (int) $target->id)),
                $table . ' still holds rows belonging to the deleted instance'
            );
        }
    }

    /**
     * Builds a clause matching rows that belonged to a deleted instance.
     *
     * @param string $table The table being checked.
     * @param int $instanceid The instance that was deleted.
     * @return string
     */
    private static function orphan_clause(string $table, int $instanceid): string {
        if (in_array($table, ['codereview_blobs', 'codereview_commits'], true)) {
            return "codereview = $instanceid";
        }

        if ($table === 'codereview_submissions') {
            return "codereview = $instanceid";
        }

        return "submission IN (SELECT id FROM {codereview_submissions} WHERE codereview = $instanceid)";
    }

    /**
     * Resetting a course clears the cohort's work but keeps the template baseline,
     * which belongs to the activity rather than to any student.
     *
     * @return void
     */
    public function test_reset_clears_user_data_but_keeps_the_baseline(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->populated_instance($course);

        $status = codereview_reset_userdata((object) [
            'courseid' => $course->id,
            'reset_codereview_submissions' => 1,
        ]);

        $this->assertNotEmpty($status);
        $this->assertSame(0, $DB->count_records('codereview_submissions', ['codereview' => $instance->id]));
        $this->assertSame(0, $DB->count_records('codereview_commits', ['codereview' => $instance->id]));

        $blobs = $DB->get_records('codereview_blobs', ['codereview' => $instance->id]);
        $this->assertCount(1, $blobs);
        $this->assertSame(
            fingerprint_service::BASELINE_SUBMISSION,
            (int) reset($blobs)->submission
        );

        // The activity itself survives a reset.
        $this->assertTrue($DB->record_exists('codereview', ['id' => $instance->id]));
    }

    /**
     * A reset that was not asked for removes nothing.
     *
     * @return void
     */
    public function test_reset_without_the_option_removes_nothing(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->populated_instance($course);
        $before = $this->count_rows((int) $instance->id);

        codereview_reset_userdata((object) ['courseid' => $course->id]);

        $this->assertSame($before, $this->count_rows((int) $instance->id));
    }

    /**
     * Resetting one course leaves another alone.
     *
     * @return void
     */
    public function test_reset_does_not_reach_other_courses(): void {
        $this->resetAfterTest();

        $first = $this->getDataGenerator()->create_course();
        $second = $this->getDataGenerator()->create_course();

        $target = $this->populated_instance($first);
        $bystander = $this->populated_instance($second);
        $before = $this->count_rows((int) $bystander->id);

        codereview_reset_userdata((object) [
            'courseid' => $first->id,
            'reset_codereview_submissions' => 1,
        ]);

        $this->assertSame($before, $this->count_rows((int) $bystander->id));
    }
}
