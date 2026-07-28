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

namespace mod_codereview\external;

use externallib_advanced_testcase;
use mod_codereview\local\submission_service;
use moodle_exception;
use required_capability_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for the grading web services.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\external\approve_grade
 * @covers     \mod_codereview\external\reopen_submission
 * @covers     \mod_codereview\external\get_review_data
 */
final class approve_grade_test extends externallib_advanced_testcase {
    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The course module. */
    private stdClass $cm;

    /** @var stdClass The student. */
    private stdClass $student;

    /** @var stdClass The teacher. */
    private stdClass $teacher;

    /** @var int The submission being graded. */
    private int $submissionid;

    /**
     * Creates an activity with a submission ready to be graded.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->cm = $this->getDataGenerator()->create_module('codereview', [
            'course' => $this->course->id,
            'grade' => 100,
        ]);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $this->submissionid = $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $this->cm->id,
            'userid' => $this->student->id,
            'repourl' => 'https://github.com/octocat/hello-world',
            'repoowner' => 'octocat',
            'reponame' => 'hello-world',
            'commitsha' => str_repeat('a', 40),
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Signs a user in and supplies the sesskey the services require.
     *
     * The services call require_sesskey() because they are reached over AJAX from a
     * logged-in page. A test has to provide it the same way the browser would.
     *
     * @param \stdClass $user The user to act as.
     * @return void
     */
    private function login_as(\stdClass $user): void {
        $this->setUser($user);
        $_POST['sesskey'] = sesskey();
    }

    /**
     * A teacher can approve, and the grade reaches the gradebook.
     *
     * @return void
     */
    public function test_teacher_can_approve(): void {
        $this->login_as($this->teacher);

        $result = approve_grade::execute($this->submissionid, 82.0, 'Good work');

        $this->assertEqualsWithDelta(82.0, $result['finalgrade'], 0.0001);

        $grades = grade_get_grades(
            $this->course->id,
            'mod',
            'codereview',
            $this->cm->id,
            [$this->student->id]
        );
        $this->assertEqualsWithDelta(
            82.0,
            (float) $grades->items[0]->grades[$this->student->id]->grade,
            0.0001
        );
    }

    /**
     * The student cannot grade their own submission, however the call is made.
     *
     * @return void
     */
    public function test_student_cannot_approve(): void {
        global $DB;

        $this->login_as($this->student);

        $this->expectException(required_capability_exception::class);

        try {
            approve_grade::execute($this->submissionid, 100.0, '');
        } finally {
            $this->assertSame(0, $DB->count_records('codereview_grades'));
        }
    }

    /**
     * A grade outside the scale is refused at the service boundary too, not only in
     * the form.
     *
     * @return void
     */
    public function test_grade_out_of_range_is_refused(): void {
        $this->login_as($this->teacher);

        $this->expectException(moodle_exception::class);

        approve_grade::execute($this->submissionid, 500.0, '');
    }

    /**
     * A teacher of one course cannot read or grade a submission belonging to another.
     *
     * @return void
     */
    public function test_submission_from_another_course_is_refused(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');

        $this->login_as($outsider);

        // Refused by validate_context() before the capability check is even reached:
        // the user is not enrolled where the submission lives.
        $this->expectException(\core\exception\require_login_exception::class);

        get_review_data::execute($this->submissionid);
    }

    /**
     * Reopening returns the submission to the editable state.
     *
     * @return void
     */
    public function test_reopen_returns_the_submission(): void {
        global $DB;

        $this->login_as($this->teacher);
        approve_grade::execute($this->submissionid, 70.0, '');

        $result = reopen_submission::execute($this->submissionid);

        $this->assertSame('notgraded', $result['gradestatus']);
        $this->assertSame(
            submission_service::GRADE_NOTGRADED,
            $DB->get_field('codereview_submissions', 'gradestatus', ['id' => $this->submissionid])
        );
    }

    /**
     * The review payload leaves out the flag evidence, which names another student.
     *
     * @return void
     */
    public function test_review_payload_omits_flag_evidence(): void {
        global $DB;

        $DB->insert_record('codereview_flags', (object) [
            'submission' => $this->submissionid,
            'flagtype' => 'identicalcommit',
            'severity' => 'high',
            'peersubmission' => null,
            'detail' => '{}',
            'timecreated' => time(),
        ]);

        $this->login_as($this->teacher);
        $data = get_review_data::execute($this->submissionid);

        $this->assertSame(1, $data['flagcount']);
        $this->assertArrayNotHasKey('flags', $data);
    }
}
