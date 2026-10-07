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
use mod_codereview\fixtures\ai_gateway_stub;
use mod_codereview\fixtures\github_client_stub;
use moodle_exception;
use stdClass;

/**
 * Tests for the manual runs a teacher starts from the review screen.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\manual_runner
 */
final class manual_runner_test extends advanced_testcase {
    /** @var string A valid looking commit SHA. */
    private const SHA = '1234567890abcdef1234567890abcdef12345678';

    /** @var stdClass The activity instance under test. */
    private stdClass $instance;

    /** @var stdClass The submission being worked on. */
    private stdClass $submission;

    /** @var context_module The activity context. */
    private context_module $context;

    /**
     * Loads the shared test doubles.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/codereview/tests/fixtures/github_client_stub.php');
        require_once($CFG->dirroot . '/mod/codereview/tests/fixtures/ai_gateway_stub.php');

        parent::setUpBeforeClass();
    }

    /**
     * Creates an instance and a submission whose checks have not been read yet.
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
            'weighttests' => 50,
            'weightai' => 50,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);
        $this->context = context_module::instance($module->cmid);

        $submission = (object) [
            'codereview' => $this->instance->id,
            'userid' => $student->id,
            'repourl' => 'https://github.com/octocat/hello-world',
            'repoowner' => 'octocat',
            'reponame' => 'hello-world',
            'commitsha' => self::SHA,
            'cistatus' => submission_service::CI_PENDING,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'truncated' => 0,
            'timesubmitted' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $id = $DB->insert_record('codereview_submissions', $submission);

        // Read back, as the services do, so the row carries every column the code expects.
        $this->submission = $DB->get_record('codereview_submissions', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Removes the doubles, which live in statics that resetAfterTest() does not touch.
     *
     * @return void
     */
    protected function tearDown(): void {
        github_client::set_instance_for_testing(null);
        ai_reviewer::set_gateway_for_testing(null);

        parent::tearDown();
    }

    /**
     * Installs a GitHub double that serves one passing check and one small repository.
     *
     * @return void
     */
    private function install_github(): void {
        $files = ['main.py' => 'print(1)'];
        $tree = [['path' => 'main.py', 'type' => 'blob', 'size' => 8, 'sha' => sha1('print(1)')]];

        $zippath = tempnam(make_temp_directory('mod_codereview_test'), 'zip');
        $zip = new \ZipArchive();
        $zip->open($zippath, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString('octocat-hello-world-abc1234/' . $name, $contents);
        }
        $zip->close();
        $bytes = file_get_contents($zippath);
        unlink($zippath);

        $stub = new github_client_stub();
        $stub->set_response('/repos/octocat/hello-world/git/trees/' . self::SHA, [
            'sha' => self::SHA,
            'tree' => $tree,
            'truncated' => false,
        ]);
        $stub->set_response('/repos/octocat/hello-world/commits/' . self::SHA . '/check-runs', [
            'total_count' => 1,
            'check_runs' => [[
                'id' => 1,
                'name' => 'pytest',
                'status' => 'completed',
                'conclusion' => 'success',
                'app' => ['slug' => 'github-actions'],
                'html_url' => 'https://github.com/octocat/hello-world/runs/1',
                'started_at' => '2026-07-20T10:00:00Z',
                'completed_at' => '2026-07-20T10:02:00Z',
            ]],
        ]);
        $stub->set_archive($bytes);

        github_client::set_instance_for_testing($stub);
    }

    /**
     * The checks are read inside the call, with no cron in between.
     *
     * @return void
     */
    public function test_recheck_reads_the_checks_in_the_same_call(): void {
        global $DB;

        $this->install_github();

        $status = manual_runner::recheck_ci($this->instance, $this->submission);

        $this->assertSame(submission_service::CI_COMPLETED, $status);
        $this->assertSame(1, $DB->count_records('codereview_checkruns', ['submission' => $this->submission->id]));
        $this->assertSame(
            submission_service::CI_COMPLETED,
            $DB->get_field('codereview_submissions', 'cistatus', ['id' => $this->submission->id])
        );
    }

    /**
     * A submission whose automatic chain died still gets its integrity check, which that
     * chain would have queued once the checks settled.
     *
     * @return void
     */
    public function test_recheck_queues_the_integrity_check_once_the_checks_settle(): void {
        $this->install_github();

        manual_runner::recheck_ci($this->instance, $this->submission);

        $tasks = \core\task\manager::get_adhoc_tasks(\mod_codereview\task\run_integrity_check::class);
        $this->assertCount(1, $tasks);
    }

    /**
     * The review is generated inside the call, and it is the provider's answer that is stored.
     *
     * @return void
     */
    public function test_rerun_generates_the_review_in_the_same_call(): void {
        global $DB;

        $this->install_github();
        $gateway = new ai_gateway_stub('{"grade": 90, "feedback": "Fine work."}');
        ai_reviewer::set_gateway_for_testing($gateway);

        $status = manual_runner::rerun_ai($this->instance, $this->submission, $this->context);

        $this->assertSame(submission_service::AI_COMPLETED, $status);
        $this->assertSame(1, $gateway->generatecalls);
        $this->assertSame(
            'Fine work.',
            $DB->get_field('codereview_airesults', 'feedback', ['submission' => $this->submission->id])
        );
    }

    /**
     * A bad answer is recorded for the teacher to read, not raised as an exception.
     *
     * @return void
     */
    public function test_rerun_records_an_unusable_answer(): void {
        global $DB;

        $this->install_github();
        ai_reviewer::set_gateway_for_testing(new ai_gateway_stub('this is not json'));

        $status = manual_runner::rerun_ai($this->instance, $this->submission, $this->context);

        $this->assertSame(submission_service::AI_ERROR, $status);
        $this->assertNotSame(
            '',
            (string) $DB->get_field('codereview_airesults', 'errormessage', ['submission' => $this->submission->id])
        );
    }

    /**
     * A second click while the first is running is refused, so the provider is not paid twice.
     *
     * @return void
     */
    public function test_a_second_run_is_refused_while_one_is_in_progress(): void {
        $this->install_github();

        $lock = manual_runner::lock_factory()->get_lock('manual_' . $this->submission->id, 0);
        $this->assertNotFalse($lock);

        try {
            $this->expectException(moodle_exception::class);
            $this->expectExceptionMessage(get_string('errorrunninginprogress', 'mod_codereview'));
            manual_runner::recheck_ci($this->instance, $this->submission);
        } finally {
            $lock->release();
        }
    }

    /**
     * The lock is let go when the run ends, so the next click works.
     *
     * @return void
     */
    public function test_the_lock_is_released_after_a_run(): void {
        $this->install_github();
        ai_reviewer::set_gateway_for_testing(new ai_gateway_stub('{"grade": 80, "feedback": "Good."}'));

        manual_runner::recheck_ci($this->instance, $this->submission);
        $status = manual_runner::rerun_ai($this->instance, $this->submission, $this->context);

        $this->assertSame(submission_service::AI_COMPLETED, $status);
    }
}
