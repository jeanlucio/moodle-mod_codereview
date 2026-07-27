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
use mod_codereview\fixtures\github_client_stub;
use stdClass;

/**
 * Tests for the check-run poller.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\checkrun_poller
 */
final class checkrun_poller_test extends advanced_testcase {
    /** @var string A valid looking commit SHA used across the tests. */
    private const SHA = '1234567890abcdef1234567890abcdef12345678';

    /** @var stdClass The activity instance under test. */
    private stdClass $instance;

    /** @var stdClass The submission being polled. */
    private stdClass $submission;

    /**
     * Loads the shared test double.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/codereview/tests/fixtures/github_client_stub.php');

        parent::setUpBeforeClass();
    }

    /**
     * Creates an instance and a submission sitting at pending.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);

        $submission = new stdClass();
        $submission->codereview = $this->instance->id;
        $submission->userid = $student->id;
        $submission->repourl = 'https://github.com/octocat/hello-world';
        $submission->repoowner = 'octocat';
        $submission->reponame = 'hello-world';
        $submission->commitsha = self::SHA;
        $submission->cistatus = submission_service::CI_PENDING;
        $submission->aistatus = submission_service::AI_SKIPPED;
        $submission->gradestatus = submission_service::GRADE_NOTGRADED;
        $submission->timecreated = time();
        $submission->timemodified = time();
        $submission->id = $DB->insert_record('codereview_submissions', $submission);

        $this->submission = $submission;
    }

    /**
     * Returns the API path the poller reads.
     *
     * @return string
     */
    private function path(): string {
        return '/repos/octocat/hello-world/commits/' . self::SHA . '/check-runs';
    }

    /**
     * Builds a check-run resource.
     *
     * @param int $id The GitHub check-run id.
     * @param string $name The check name.
     * @param string $status queued, in_progress or completed.
     * @param string|null $conclusion The conclusion, when completed.
     * @param string $appslug The owning GitHub app.
     * @return array
     */
    private function checkrun(
        int $id,
        string $name,
        string $status = 'completed',
        ?string $conclusion = 'success',
        string $appslug = 'github-actions'
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'status' => $status,
            'conclusion' => $conclusion,
            'app' => ['slug' => $appslug],
            'html_url' => 'https://github.com/octocat/hello-world/runs/' . $id,
            'started_at' => '2026-07-20T10:00:00Z',
            'completed_at' => $status === 'completed' ? '2026-07-20T10:02:00Z' : null,
        ];
    }

    /**
     * Runs a poll against a stubbed response.
     *
     * @param array $runs The check_runs to return.
     * @return string The resulting cistatus.
     */
    private function poll_with(array $runs): string {
        $stub = new github_client_stub();
        $stub->set_response($this->path(), ['total_count' => count($runs), 'check_runs' => $runs]);

        return (new checkrun_poller($stub))->poll($this->instance, $this->submission);
    }

    /**
     * Completed check-runs are stored and close the submission out.
     *
     * @return void
     */
    public function test_poll_records_completed_runs(): void {
        global $DB;

        $status = $this->poll_with([
            $this->checkrun(1, 'placeholder-substituido'),
            $this->checkrun(2, 'secao-sobre-mim', 'completed', 'failure'),
        ]);

        $this->assertSame(submission_service::CI_COMPLETED, $status);
        $this->assertSame(2, $DB->count_records('codereview_checkruns', ['submission' => $this->submission->id]));
        $this->assertSame(
            submission_service::CI_COMPLETED,
            $DB->get_field('codereview_submissions', 'cistatus', ['id' => $this->submission->id])
        );
    }

    /**
     * A run still in progress keeps the submission waiting.
     *
     * @return void
     */
    public function test_poll_keeps_checking_while_a_run_is_unfinished(): void {
        $status = $this->poll_with([
            $this->checkrun(1, 'build'),
            $this->checkrun(2, 'tests', 'in_progress', null),
        ]);

        $this->assertSame(submission_service::CI_CHECKING, $status);
    }

    /**
     * Polling twice over the same check-runs must not duplicate rows, because the
     * poller runs repeatedly against the same commit.
     *
     * @return void
     */
    public function test_poll_is_idempotent(): void {
        global $DB;

        $runs = [$this->checkrun(1, 'build', 'in_progress', null)];
        $this->poll_with($runs);
        $this->poll_with([$this->checkrun(1, 'build', 'completed', 'success')]);

        $stored = $DB->get_records('codereview_checkruns', ['submission' => $this->submission->id]);

        $this->assertCount(1, $stored);
        $this->assertSame('success', reset($stored)->conclusion);
    }

    /**
     * Checks owned by other GitHub apps are recorded for the teacher to see but kept
     * out of the grade denominator, so an installed bot cannot silently become an
     * assessment criterion.
     *
     * @return void
     */
    public function test_third_party_checks_are_not_counted(): void {
        global $DB;

        $this->poll_with([
            $this->checkrun(1, 'tests'),
            $this->checkrun(2, 'CodeQL', 'completed', 'success', 'github-code-scanning'),
            $this->checkrun(3, 'dependabot', 'completed', 'success', 'dependabot'),
        ]);

        $counted = $DB->get_records('codereview_checkruns', [
            'submission' => $this->submission->id,
            'counted' => 1,
        ]);

        $this->assertCount(1, $counted);
        $this->assertSame('tests', reset($counted)->checkname);
    }

    /**
     * With no check-runs and the window still open, the submission keeps waiting.
     *
     * @return void
     */
    public function test_no_runs_before_timeout_keeps_checking(): void {
        $this->assertSame(submission_service::CI_CHECKING, $this->poll_with([]));
    }

    /**
     * With no check-runs once the window has closed, the submission is reported as
     * having no CI rather than waiting forever.
     *
     * @return void
     */
    public function test_no_runs_after_timeout_reports_no_ci(): void {
        global $DB;

        $this->submission->timecreated = time() - (($this->instance->citimeout + 1) * MINSECS);
        $DB->update_record('codereview_submissions', $this->submission);

        $this->assertSame(submission_service::CI_NOCIDETECTED, $this->poll_with([]));
    }

    /**
     * When the window closes with some checks still running, the results that did
     * arrive are kept rather than discarded as "no CI".
     *
     * @return void
     */
    public function test_partial_runs_after_timeout_complete(): void {
        global $DB;

        $this->submission->timecreated = time() - (($this->instance->citimeout + 1) * MINSECS);
        $DB->update_record('codereview_submissions', $this->submission);

        $status = $this->poll_with([
            $this->checkrun(1, 'build'),
            $this->checkrun(2, 'tests', 'in_progress', null),
        ]);

        $this->assertSame(submission_service::CI_COMPLETED, $status);
    }

    /**
     * A rate limit says nothing about the commit, so the submission keeps waiting and
     * only records why the attempt did not land.
     *
     * @return void
     */
    public function test_rate_limit_is_transient(): void {
        global $DB;

        $this->submission->cistatus = submission_service::CI_CHECKING;
        $DB->update_record('codereview_submissions', $this->submission);

        $stub = new github_client_stub();
        $stub->set_failure($this->path(), 429);
        $status = (new checkrun_poller($stub))->poll($this->instance, $this->submission);

        $this->assertSame(submission_service::CI_CHECKING, $status);
        $this->assertNotEmpty($DB->get_field('codereview_submissions', 'errormessage', [
            'id' => $this->submission->id,
        ]));
    }

    /**
     * A failure that retrying cannot fix stops the polling and says why.
     *
     * @return void
     */
    public function test_permanent_failure_stops_polling(): void {
        global $DB;

        $stub = new github_client_stub();
        $stub->set_failure($this->path(), 404);

        $this->assertSame(
            submission_service::CI_ERROR,
            (new checkrun_poller($stub))->poll($this->instance, $this->submission)
        );
        $this->assertNotEmpty($DB->get_field('codereview_submissions', 'errormessage', [
            'id' => $this->submission->id,
        ]));
    }
}
