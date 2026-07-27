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

namespace mod_codereview\local;

use advanced_testcase;
use context_module;
use moodle_exception;
use stdClass;

/**
 * Tests for grade approval and reopening.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\grade_approval_service
 * @covers     \mod_codereview\local\review_service
 */
final class grade_approval_service_test extends advanced_testcase {
    /** @var stdClass The activity instance under test. */
    private stdClass $instance;

    /** @var context_module The activity context. */
    private context_module $context;

    /** @var stdClass The submission being graded. */
    private stdClass $submission;

    /** @var stdClass The student. */
    private stdClass $student;

    /** @var stdClass The teacher. */
    private stdClass $teacher;

    /**
     * Creates a course, an activity, a student submission and a teacher.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', [
            'course' => $course->id,
            'grade' => 100,
            'weighttests' => 100,
            'weightai' => 0,
        ]);

        $this->instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);
        $this->context = context_module::instance($module->cmid);
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $submission = (object) [
            'codereview' => $this->instance->id,
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
        ];
        // Reloaded rather than reused: every production caller receives a full row from
        // the database, so a hand-built object would exercise a shape that never occurs.
        $submissionid = $DB->insert_record('codereview_submissions', $submission);
        $this->submission = $DB->get_record('codereview_submissions', ['id' => $submissionid], '*', MUST_EXIST);

        foreach ([['tests', 'success'], ['lint', 'failure']] as $i => [$name, $conclusion]) {
            $DB->insert_record('codereview_checkruns', (object) [
                'submission' => $this->submission->id,
                'externalid' => $i + 1,
                'checkname' => $name,
                'conclusion' => $conclusion,
                'counted' => 1,
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * Approving stores the decision, marks the submission graded and releases the
     * grade to the gradebook.
     *
     * @return void
     */
    public function test_approve_releases_the_grade(): void {
        global $DB;

        (new grade_approval_service())->approve(
            $this->instance,
            $this->context,
            $this->submission,
            (int) $this->teacher->id,
            77.0,
            'Good work.'
        );

        $stored = $DB->get_record('codereview_grades', ['submission' => $this->submission->id]);
        $this->assertEqualsWithDelta(77.0, (float) $stored->finalgrade, 0.0001);
        $this->assertSame((int) $this->teacher->id, (int) $stored->graderid);

        $this->assertSame(
            submission_service::GRADE_GRADED,
            $DB->get_field('codereview_submissions', 'gradestatus', ['id' => $this->submission->id])
        );

        $grades = grade_get_grades(
            $this->instance->course,
            'mod',
            'codereview',
            $this->instance->id,
            [$this->student->id]
        );
        $this->assertEqualsWithDelta(
            77.0,
            (float) $grades->items[0]->grades[$this->student->id]->grade,
            0.0001
        );
    }

    /**
     * The suggestion is frozen alongside the decision, so the two stay comparable
     * even after later activity would change what is suggested.
     *
     * @return void
     */
    public function test_approve_freezes_the_suggestion(): void {
        global $DB;

        (new grade_approval_service())->approve(
            $this->instance,
            $this->context,
            $this->submission,
            (int) $this->teacher->id,
            30.0,
            ''
        );

        $stored = $DB->get_record('codereview_grades', ['submission' => $this->submission->id]);

        // One of two countable checks passed, so the suggestion was 50 even though the
        // teacher decided 30.
        $this->assertEqualsWithDelta(50.0, (float) $stored->suggestedgrade, 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) $stored->finalgrade, 0.0001);
    }

    /**
     * A grade outside the activity scale is refused rather than stored.
     *
     * @return void
     */
    public function test_approve_rejects_grade_out_of_range(): void {
        $this->expectException(moodle_exception::class);

        (new grade_approval_service())->approve(
            $this->instance,
            $this->context,
            $this->submission,
            (int) $this->teacher->id,
            250.0,
            ''
        );
    }

    /**
     * Approving twice updates the decision instead of creating a second one.
     *
     * @return void
     */
    public function test_approving_again_updates_the_same_decision(): void {
        global $DB;

        $service = new grade_approval_service();
        $service->approve($this->instance, $this->context, $this->submission, (int) $this->teacher->id, 40.0, '');
        $service->approve($this->instance, $this->context, $this->submission, (int) $this->teacher->id, 60.0, '');

        $this->assertSame(1, $DB->count_records('codereview_grades', ['submission' => $this->submission->id]));
        $this->assertEqualsWithDelta(
            60.0,
            (float) $DB->get_field('codereview_grades', 'finalgrade', ['submission' => $this->submission->id]),
            0.0001
        );
    }

    /**
     * Reopening returns the submission to the editable state without retracting the
     * grade that was already given.
     *
     * @return void
     */
    public function test_reopen_leaves_the_released_grade_alone(): void {
        global $DB;

        $service = new grade_approval_service();
        $service->approve($this->instance, $this->context, $this->submission, (int) $this->teacher->id, 80.0, '');
        $service->reopen($this->instance, $this->context, $this->submission, (int) $this->teacher->id);

        $this->assertSame(
            submission_service::GRADE_NOTGRADED,
            $DB->get_field('codereview_submissions', 'gradestatus', ['id' => $this->submission->id])
        );

        $grades = grade_get_grades(
            $this->instance->course,
            'mod',
            'codereview',
            $this->instance->id,
            [$this->student->id]
        );
        $this->assertEqualsWithDelta(
            80.0,
            (float) $grades->items[0]->grades[$this->student->id]->grade,
            0.0001
        );
    }

    /**
     * Nothing reaches the gradebook before a teacher approves. The checks alone,
     * however conclusive, never release a grade.
     *
     * @return void
     */
    public function test_nothing_is_released_without_approval(): void {
        $grades = grade_get_grades(
            $this->instance->course,
            'mod',
            'codereview',
            $this->instance->id,
            [$this->student->id]
        );

        $this->assertNull($grades->items[0]->grades[$this->student->id]->grade);
    }

    /**
     * The review payload carries the suggestion, the checks and the grading state.
     *
     * @return void
     */
    public function test_review_data_is_assembled(): void {
        $data = review_service::get_review_data($this->instance, $this->submission);

        $this->assertSame(fullname($this->student), $data['studentname']);
        $this->assertCount(2, $data['checkruns']);
        $this->assertEqualsWithDelta(50.0, $data['suggestedgrade'], 0.0001);
        $this->assertSame(submission_service::GRADE_NOTGRADED, $data['gradestatus']);
        $this->assertNull($data['finalgrade']);
    }

    /**
     * A submission id from another activity cannot be opened through this instance,
     * however it was obtained.
     *
     * @return void
     */
    public function test_submission_from_another_instance_is_refused(): void {
        global $DB;

        $other = $this->getDataGenerator()->create_module('codereview', ['course' => $this->instance->course]);
        $otherinstance = $DB->get_record('codereview', ['id' => $other->id], '*', MUST_EXIST);

        $this->expectException(\dml_exception::class);

        review_service::get_submission($otherinstance, (int) $this->submission->id);
    }
}
