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
use mod_codereview\fixtures\ai_gateway_stub;
use mod_codereview\fixtures\github_client_stub;
use mod_codereview\local\ai_reviewer;
use mod_codereview\local\github_client;
use mod_codereview\local\manual_runner;
use mod_codereview\local\submission_service;
use moodle_exception;
use required_capability_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for the two actions a teacher can repeat by hand: reading the checks and the AI review.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\external\recheck_ci
 * @covers     \mod_codereview\external\rerun_ai_review
 */
final class manual_actions_test extends externallib_advanced_testcase {
    /** @var string The commit under review. */
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var stdClass The course module. */
    private stdClass $cm;

    /** @var stdClass The student. */
    private stdClass $student;

    /** @var stdClass A classmate, who has no business touching the submission. */
    private stdClass $classmate;

    /** @var stdClass The teacher. */
    private stdClass $teacher;

    /** @var int The submission under review. */
    private int $submissionid;

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
     * Creates an activity with a submission whose checks have not been read yet.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->cm = $this->getDataGenerator()->create_module('codereview', [
            'course' => $course->id,
            'weightai' => 50,
            'weighttests' => 50,
        ]);
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->classmate = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->submissionid = $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $this->cm->id,
            'userid' => $this->student->id,
            'repourl' => 'https://github.com/octocat/hello-world',
            'repoowner' => 'octocat',
            'reponame' => 'hello-world',
            'commitsha' => self::SHA,
            'cistatus' => submission_service::CI_PENDING,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $stub = new github_client_stub();
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
        $stub->set_response('/repos/octocat/hello-world/git/trees/' . self::SHA, [
            'sha' => self::SHA,
            'tree' => [['path' => 'main.py', 'type' => 'blob', 'size' => 8, 'sha' => sha1('print(1)')]],
            'truncated' => false,
        ]);

        $zippath = tempnam(make_temp_directory('mod_codereview_test'), 'zip');
        $zip = new \ZipArchive();
        $zip->open($zippath, \ZipArchive::OVERWRITE);
        $zip->addFromString('octocat-hello-world-abc1234/main.py', 'print(1)');
        $zip->close();
        $stub->set_archive(file_get_contents($zippath));
        unlink($zippath);

        github_client::set_instance_for_testing($stub);
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
     * Signs a user in and supplies the sesskey the services require.
     *
     * @param stdClass $user The user to act as.
     * @return void
     */
    private function login_as(stdClass $user): void {
        $this->setUser($user);
        $_POST['sesskey'] = sesskey();
    }

    /**
     * A teacher's recheck reads the checks now and says how it ended.
     *
     * @return void
     */
    public function test_teacher_recheck_runs_in_the_request(): void {
        $this->login_as($this->teacher);

        $result = recheck_ci::execute((int) $this->cm->cmid, (int) $this->student->id);

        $this->assertSame(submission_service::CI_COMPLETED, $result['cistatus']);
        $this->assertSame('', $result['error']);
    }

    /**
     * A teacher may force a first read even though the student is not allowed to ask for one.
     *
     * @return void
     */
    public function test_teacher_recheck_is_allowed_before_the_first_poll(): void {
        $this->login_as($this->teacher);

        $result = recheck_ci::execute((int) $this->cm->cmid, (int) $this->student->id);

        $this->assertNotSame(submission_service::CI_PENDING, $result['cistatus']);
    }

    /**
     * A student has nothing to recheck: the checks are not shown to them, and a click would
     * only spend the GitHub quota.
     *
     * @return void
     */
    public function test_a_student_cannot_recheck_even_their_own_submission(): void {
        global $DB;

        $this->login_as($this->student);

        try {
            recheck_ci::execute((int) $this->cm->cmid, (int) $this->student->id);
            $this->fail('A student must not be able to recheck the automated checks.');
        } catch (required_capability_exception $e) {
            $this->assertSame(0, $DB->count_records('codereview_checkruns'));
        }
    }

    /**
     * Rechecking a classmate's submission is a grader action.
     *
     * @return void
     */
    public function test_a_student_cannot_recheck_a_classmate(): void {
        $this->login_as($this->classmate);

        $this->expectException(required_capability_exception::class);
        recheck_ci::execute((int) $this->cm->cmid, (int) $this->student->id);
    }

    /**
     * A teacher's AI rerun generates the review in the request and returns the outcome.
     *
     * @return void
     */
    public function test_teacher_rerun_generates_the_review_in_the_request(): void {
        $this->login_as($this->teacher);
        ai_reviewer::set_gateway_for_testing(new ai_gateway_stub('{"grade": 88, "feedback": "Well done."}'));

        $result = rerun_ai_review::execute($this->submissionid);

        $this->assertSame(submission_service::AI_COMPLETED, $result['aistatus']);
        $this->assertSame('', $result['error']);
    }

    /**
     * A provider answer nobody can use comes back as a message the teacher can read.
     *
     * @return void
     */
    public function test_a_failed_rerun_returns_the_reason(): void {
        $this->login_as($this->teacher);
        ai_reviewer::set_gateway_for_testing(new ai_gateway_stub('not json at all'));

        $result = rerun_ai_review::execute($this->submissionid);

        $this->assertSame(submission_service::AI_ERROR, $result['aistatus']);
        $this->assertSame(get_string('errormalformedairesponse', 'mod_codereview'), $result['error']);
    }

    /**
     * Only graders may spend the provider budget.
     *
     * @return void
     */
    public function test_a_student_cannot_rerun_the_ai_review(): void {
        $this->login_as($this->student);
        $gateway = new ai_gateway_stub('{"grade": 50, "feedback": "x"}');
        ai_reviewer::set_gateway_for_testing($gateway);

        try {
            rerun_ai_review::execute($this->submissionid);
            $this->fail('A student must not be able to rerun the AI review.');
        } catch (required_capability_exception $e) {
            $this->assertSame(0, $gateway->generatecalls);
        }
    }

    /**
     * A second click while a run is going is refused with a message, not queued behind it.
     *
     * @return void
     */
    public function test_a_second_rerun_is_refused_while_one_is_running(): void {
        $this->login_as($this->teacher);
        ai_reviewer::set_gateway_for_testing(new ai_gateway_stub('{"grade": 50, "feedback": "x"}'));

        $lock = manual_runner::lock_factory()->get_lock('manual_' . $this->submissionid, 0);
        $this->assertNotFalse($lock);

        try {
            $this->expectException(moodle_exception::class);
            rerun_ai_review::execute($this->submissionid);
        } finally {
            $lock->release();
        }
    }
}
