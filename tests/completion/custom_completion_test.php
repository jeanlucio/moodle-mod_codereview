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

namespace mod_codereview\completion;

use advanced_testcase;
use cm_info;
use mod_codereview\local\submission_service;
use stdClass;

/**
 * Tests for the custom completion rules.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\completion\custom_completion
 */
final class custom_completion_test extends advanced_testcase {
    /** @var stdClass The course the activity belongs to. */
    private stdClass $course;

    /** @var stdClass The student. */
    private stdClass $student;

    /**
     * Creates a course and an enrolled student.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Creates an activity with the given completion rules switched on.
     *
     * @param array $rules Extra instance settings.
     * @return cm_info
     */
    private function activity(array $rules): cm_info {
        $module = $this->getDataGenerator()->create_module('codereview', array_merge([
            'course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ], $rules));

        return cm_info::create(get_coursemodule_from_id('codereview', $module->cmid, 0, false, MUST_EXIST));
    }

    /**
     * Creates a submission for the student.
     *
     * @param cm_info $cm The activity.
     * @param string $gradestatus The grading status to store.
     * @return int The submission id.
     */
    private function submission(cm_info $cm, string $gradestatus): int {
        global $DB;

        return $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $cm->instance,
            'userid' => $this->student->id,
            'repourl' => 'https://github.com/octocat/hello-world',
            'repoowner' => 'octocat',
            'reponame' => 'hello-world',
            'commitsha' => str_repeat('a', 40),
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => $gradestatus,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * The customdata the completion API reads must carry the rule values. Without it
     * the rules are silently skipped even though the class is present and correct.
     *
     * @return void
     */
    public function test_coursemodule_info_exposes_the_rules(): void {
        $cm = $this->activity(['completionsubmit' => 1, 'completionchecksenabled' => 1, 'completionchecks' => 2]);

        $rules = $cm->customdata['customcompletionrules'] ?? [];

        $this->assertSame(1, $rules['completionsubmit']);
        $this->assertSame(2, $rules['completionchecks']);
    }

    /**
     * Without a submission nothing is complete.
     *
     * @return void
     */
    public function test_no_submission_is_incomplete(): void {
        $cm = $this->activity(['completionsubmit' => 1]);
        $completion = new custom_completion($cm, (int) $this->student->id);

        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionsubmit'));
    }

    /**
     * Submitting is not enough on its own: the rule asks for a graded submission, and
     * grading only happens when a teacher approves.
     *
     * @return void
     */
    public function test_submitted_but_ungraded_is_incomplete(): void {
        $cm = $this->activity(['completionsubmit' => 1]);
        $this->submission($cm, submission_service::GRADE_NOTGRADED);

        $completion = new custom_completion($cm, (int) $this->student->id);

        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionsubmit'));
    }

    /**
     * An approved grade completes the rule.
     *
     * @return void
     */
    public function test_graded_submission_is_complete(): void {
        $cm = $this->activity(['completionsubmit' => 1]);
        $this->submission($cm, submission_service::GRADE_GRADED);

        $completion = new custom_completion($cm, (int) $this->student->id);

        $this->assertSame(COMPLETION_COMPLETE, $completion->get_state('completionsubmit'));
    }

    /**
     * The check rule counts only passing checks that count towards the grade, so a
     * third-party check cannot complete the activity on the student's behalf.
     *
     * @return void
     */
    public function test_check_rule_counts_only_counted_passes(): void {
        global $DB;

        $cm = $this->activity(['completionchecksenabled' => 1, 'completionchecks' => 2]);
        $submissionid = $this->submission($cm, submission_service::GRADE_NOTGRADED);

        $runs = [
            ['tests', 'success', 1],
            ['lint', 'failure', 1],
            ['CodeQL', 'success', 0],
        ];
        foreach ($runs as $i => [$name, $conclusion, $counted]) {
            $DB->insert_record('codereview_checkruns', (object) [
                'submission' => $submissionid,
                'externalid' => $i + 1,
                'checkname' => $name,
                'conclusion' => $conclusion,
                'counted' => $counted,
                'timecreated' => time(),
            ]);
        }

        $completion = new custom_completion($cm, (int) $this->student->id);

        // One counted pass against a requirement of two.
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionchecks'));

        $DB->set_field('codereview_checkruns', 'conclusion', 'success', [
            'submission' => $submissionid,
            'checkname' => 'lint',
        ]);

        $this->assertSame(COMPLETION_COMPLETE, $completion->get_state('completionchecks'));
    }

    /**
     * Both rules are declared, so core can discover them.
     *
     * @return void
     */
    public function test_rules_are_declared(): void {
        $this->assertSame(
            ['completionsubmit', 'completionchecks'],
            custom_completion::get_defined_custom_rules()
        );
    }
}
