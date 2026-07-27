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
use mod_codereview\fixtures\github_client_stub;
use moodle_exception;
use stdClass;

/**
 * Tests for the submission service.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\submission_service
 */
final class submission_service_test extends advanced_testcase {
    /** @var string A valid looking commit SHA used across the tests. */
    private const SHA = '1234567890abcdef1234567890abcdef12345678';

    /** @var stdClass The activity instance under test. */
    private stdClass $instance;

    /** @var context_module The activity context. */
    private context_module $context;

    /** @var stdClass The submitting student. */
    private stdClass $student;

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
     * Creates a course, an activity and an enrolled student.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);

        $this->instance = $this->get_instance((int) $module->id);
        $this->context = context_module::instance($module->cmid);
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
    }

    /**
     * Reloads the instance row from the database.
     *
     * @param int $id The instance id.
     * @return stdClass
     */
    private function get_instance(int $id): stdClass {
        global $DB;

        return $DB->get_record('codereview', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Builds a stub with a public repository and commit already queued.
     *
     * @return github_client_stub
     */
    private function stub_with_public_repo(): github_client_stub {
        $stub = new github_client_stub();
        $stub->stub_public_repo('octocat', 'hello-world', self::SHA);

        return $stub;
    }

    /**
     * A valid submission is stored with the parsed repository details.
     *
     * @return void
     */
    public function test_submit_stores_submission(): void {
        $service = new submission_service($this->stub_with_public_repo());

        $submission = $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            self::SHA
        );

        $this->assertSame('octocat', $submission->repoowner);
        $this->assertSame('hello-world', $submission->reponame);
        $this->assertSame(self::SHA, $submission->commitsha);
        $this->assertSame('https://github.com/octocat/hello-world', $submission->repourl);
        $this->assertSame(submission_service::CI_PENDING, $submission->cistatus);
        $this->assertSame(submission_service::GRADE_NOTGRADED, $submission->gradestatus);
        $this->assertSame(0, $submission->islate);
    }

    /**
     * A private repository is rejected even when the API lets us read it.
     *
     * @return void
     */
    public function test_submit_rejects_private_repository(): void {
        $stub = new github_client_stub();
        $stub->stub_public_repo('octocat', 'hello-world', self::SHA, ['private' => true]);
        $service = new submission_service($stub);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('errornotpublic', 'mod_codereview'));

        $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            self::SHA
        );
    }

    /**
     * A repository that returns 404, which is also how a private one looks to an
     * unauthenticated request, is rejected.
     *
     * @return void
     */
    public function test_submit_rejects_missing_repository(): void {
        $stub = new github_client_stub();
        $stub->set_failure('/repos/octocat/hello-world', 404);
        $service = new submission_service($stub);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorrepositorynotfound', 'mod_codereview'));

        $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            self::SHA
        );
    }

    /**
     * A commit that does not belong to the repository is rejected.
     *
     * @return void
     */
    public function test_submit_rejects_unknown_commit(): void {
        $stub = new github_client_stub();
        $stub->stub_public_repo('octocat', 'hello-world', self::SHA);
        $stub->set_failure('/repos/octocat/hello-world/commits/' . self::SHA, 404);
        $service = new submission_service($stub);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorcommitnotfound', 'mod_codereview'));

        $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            self::SHA
        );
    }

    /**
     * Lateness comes from the Moodle submission time, not from the commit date, which
     * the student controls through GIT_AUTHOR_DATE.
     *
     * @return void
     */
    public function test_islate_ignores_forged_commit_date(): void {
        global $DB;

        $this->instance->duedate = time() - DAYSECS;
        $DB->update_record('codereview', $this->instance);

        $stub = new github_client_stub();
        $stub->stub_public_repo('octocat', 'hello-world', self::SHA, [], [
            'commit' => ['author' => ['date' => '2020-01-01T00:00:00Z']],
        ]);
        $service = new submission_service($stub);

        $submission = $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            self::SHA
        );

        $this->assertSame(1, $submission->islate);
    }

    /**
     * Submissions after the cut-off date are blocked, unlike late ones.
     *
     * @return void
     */
    public function test_submit_blocked_after_cutoff(): void {
        global $DB;

        $this->instance->cutoffdate = time() - HOURSECS;
        $DB->update_record('codereview', $this->instance);

        $service = new submission_service($this->stub_with_public_repo());

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorcutoffpassed', 'mod_codereview'));

        $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            self::SHA
        );
    }

    /**
     * Resubmitting replaces the live row and discards everything derived from the
     * commit that is no longer being assessed.
     *
     * @return void
     */
    public function test_resubmission_replaces_row_and_clears_derived_data(): void {
        global $DB;

        $service = new submission_service($this->stub_with_public_repo());
        $first = $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            self::SHA
        );

        $DB->insert_record('codereview_checkruns', (object) [
            'submission' => $first->id,
            'externalid' => 1,
            'checkname' => 'tests',
            'conclusion' => 'failure',
            'counted' => 1,
            'timecreated' => time(),
        ]);

        $newsha = str_repeat('f', 40);
        $stub = new github_client_stub();
        $stub->stub_public_repo('octocat', 'hello-world', $newsha);
        $service = new submission_service($stub);

        $second = $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            $newsha
        );

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame($newsha, $second->commitsha);
        $this->assertSame(1, $DB->count_records('codereview_submissions', ['codereview' => $this->instance->id]));
        $this->assertSame(0, $DB->count_records('codereview_checkruns', ['submission' => $first->id]));
    }

    /**
     * Once the teacher has approved a grade the student cannot silently replace the
     * work that was assessed.
     *
     * @return void
     */
    public function test_resubmission_blocked_after_grading(): void {
        global $DB;

        $service = new submission_service($this->stub_with_public_repo());
        $submission = $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            self::SHA
        );

        $DB->set_field('codereview_submissions', 'gradestatus', submission_service::GRADE_GRADED, [
            'id' => $submission->id,
        ]);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('erroralreadygraded', 'mod_codereview'));

        $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/octocat/hello-world',
            self::SHA
        );
    }

    /**
     * Repository metadata that the integrity layer will need is captured at submission
     * time, including the fork parent and the server-generated timestamps.
     *
     * @return void
     */
    public function test_submit_captures_integrity_metadata(): void {
        $stub = new github_client_stub();
        $stub->stub_public_repo('student', 'hello-world', self::SHA, [
            'fork' => true,
            'parent' => ['full_name' => 'classmate/hello-world'],
            'created_at' => '2026-07-01T10:00:00Z',
        ]);
        $service = new submission_service($stub);

        $submission = $service->submit(
            $this->instance,
            $this->context,
            (int) $this->student->id,
            'https://github.com/student/hello-world',
            self::SHA
        );

        $this->assertSame(1, $submission->isfork);
        $this->assertSame('classmate/hello-world', $submission->forkparent);
        $this->assertSame(strtotime('2026-07-01T10:00:00Z'), $submission->repocreatedat);
        $this->assertSame('student', $submission->authorlogin);
    }
}
