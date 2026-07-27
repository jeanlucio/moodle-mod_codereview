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
use stdClass;

/**
 * Tests for the suggested grade calculation.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\grade_calculator
 */
final class grade_calculator_test extends advanced_testcase {
    /**
     * Weight combinations and the grade they must produce.
     *
     * @return array[]
     */
    public static function combination_provider(): array {
        return [
            'half and half, all checks passing, perfect AI' => [100, 50, 50, 4, 4, 100.0, 100.0],
            'half and half, half the checks, half the AI' => [100, 50, 50, 2, 4, 50.0, 50.0],
            'tests only, three of four passing' => [100, 100, 0, 3, 4, null, 75.0],
            'AI only' => [100, 0, 100, 0, 0, 80.0, 80.0],
            'tests dominant' => [100, 80, 20, 5, 5, 50.0, 90.0],
            'non default maximum grade' => [20, 50, 50, 1, 2, 10.0, 10.0],
        ];
    }

    /**
     * The two components combine according to their configured weights.
     *
     * @dataProvider combination_provider
     * @param int $grademax The instance maximum grade.
     * @param int $weighttests The configured weight of the checks.
     * @param int $weightai The configured weight of the AI review.
     * @param int $passed Countable checks that passed.
     * @param int $countable Checks that produced a real result.
     * @param float|null $aigrade The AI suggestion.
     * @param float $expected The expected suggested grade.
     * @return void
     */
    public function test_combine(
        int $grademax,
        int $weighttests,
        int $weightai,
        int $passed,
        int $countable,
        ?float $aigrade,
        float $expected
    ): void {
        $this->assertEqualsWithDelta(
            $expected,
            grade_calculator::combine($grademax, $weighttests, $weightai, $passed, $countable, $aigrade),
            0.0001
        );
    }

    /**
     * With no check-runs at all, the AI carries the whole grade rather than the
     * missing component being scored as zero. A teacher who never set up a workflow
     * must not cost the student half the marks.
     *
     * @return void
     */
    public function test_absent_checks_do_not_score_zero(): void {
        $this->assertEqualsWithDelta(
            80.0,
            grade_calculator::combine(100, 50, 50, 0, 0, 80.0),
            0.0001
        );
    }

    /**
     * The same holds in reverse when no AI provider answered.
     *
     * @return void
     */
    public function test_absent_ai_does_not_score_zero(): void {
        $this->assertEqualsWithDelta(
            75.0,
            grade_calculator::combine(100, 50, 50, 3, 4, null),
            0.0001
        );
    }

    /**
     * With neither component available there is nothing to suggest, and no division
     * by zero either.
     *
     * @return void
     */
    public function test_no_component_yields_no_suggestion(): void {
        $this->assertNull(grade_calculator::combine(100, 50, 50, 0, 0, null));
    }

    /**
     * Loading from the database ignores neutral and skipped conclusions entirely, and
     * leaves out checks belonging to other GitHub apps.
     *
     * @return void
     */
    public function test_calculate_ignores_neutral_and_uncounted_runs(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', [
            'course' => $course->id,
            'weighttests' => 100,
            'weightai' => 0,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);

        $submission = (object) [
            'codereview' => $instance->id,
            'userid' => $student->id,
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
        $submission->id = $DB->insert_record('codereview_submissions', $submission);

        $runs = [
            ['tests', 'success', 1],
            ['lint', 'failure', 1],
            ['optional', 'skipped', 1],
            ['docs', 'neutral', 1],
            ['CodeQL', 'success', 0],
        ];
        foreach ($runs as $i => [$name, $conclusion, $counted]) {
            $DB->insert_record('codereview_checkruns', (object) [
                'submission' => $submission->id,
                'externalid' => $i + 1,
                'checkname' => $name,
                'conclusion' => $conclusion,
                'counted' => $counted,
                'timecreated' => time(),
            ]);
        }

        // Only tests and lint are countable, so the ratio is one of two, not one of
        // five and not two of five.
        $this->assertEqualsWithDelta(50.0, grade_calculator::calculate($instance, $submission), 0.0001);
    }
}
