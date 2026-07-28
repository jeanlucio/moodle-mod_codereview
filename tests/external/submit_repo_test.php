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

use advanced_testcase;
use externallib_advanced_testcase;
use mod_codereview\fixtures\github_client_stub;
use mod_codereview\local\github_client;
use moodle_exception;
use required_capability_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for the submission web service.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\external\submit_repo
 * @covers     \mod_codereview\external\recheck_ci
 */
final class submit_repo_test extends externallib_advanced_testcase {
    /** @var string A valid looking commit SHA. */
    private const SHA = '1234567890abcdef1234567890abcdef12345678';

    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The course module. */
    private stdClass $cm;

    /** @var stdClass The student. */
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
     * Creates an activity with a student, and installs the API double.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->cm = $this->getDataGenerator()->create_module('codereview', ['course' => $this->course->id]);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $stub = new github_client_stub();
        $stub->stub_public_repo('octocat', 'hello-world', self::SHA);
        github_client::set_instance_for_testing($stub);
    }

    /**
     * Removes the double so it cannot leak into another test.
     *
     * @return void
     */
    protected function tearDown(): void {
        github_client::set_instance_for_testing(null);

        parent::tearDown();
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
     * A student with the capability can submit.
     *
     * @return void
     */
    public function test_submit_stores_the_submission(): void {
        global $DB;

        $this->login_as($this->student);

        $result = submit_repo::execute(
            (int) $this->cm->cmid,
            'https://github.com/octocat/hello-world',
            self::SHA
        );

        $this->assertSame('pending', $result['cistatus']);
        $this->assertTrue($DB->record_exists('codereview_submissions', [
            'id' => $result['submissionid'],
            'userid' => $this->student->id,
        ]));
    }

    /**
     * A user without the submit capability is refused before anything is stored.
     *
     * @return void
     */
    public function test_submit_requires_the_capability(): void {
        global $DB;

        $viewer = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->login_as($viewer);

        $this->expectException(required_capability_exception::class);

        try {
            submit_repo::execute((int) $this->cm->cmid, 'https://github.com/octocat/hello-world', self::SHA);
        } finally {
            $this->assertSame(0, $DB->count_records('codereview_submissions'));
        }
    }

    /**
     * A malformed repository URL never reaches an outgoing request.
     *
     * @return void
     */
    public function test_submit_rejects_a_crafted_url(): void {
        $this->login_as($this->student);

        $this->expectException(moodle_exception::class);

        submit_repo::execute(
            (int) $this->cm->cmid,
            'https://github.com.evil.example/octocat/hello-world',
            self::SHA
        );
    }

    /**
     * Rechecking someone else's submission needs the grading capability, so a
     * classmate cannot spend the site's quota on a repository that is not theirs.
     *
     * @return void
     */
    public function test_recheck_of_another_student_requires_grading(): void {
        $this->login_as($this->student);
        $submission = submit_repo::execute(
            (int) $this->cm->cmid,
            'https://github.com/octocat/hello-world',
            self::SHA
        );

        $classmate = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->login_as($classmate);

        $this->expectException(required_capability_exception::class);

        recheck_ci::execute((int) $this->cm->cmid, (int) $this->student->id);
    }

    /**
     * A teacher can recheck on the student's behalf.
     *
     * @return void
     */
    public function test_teacher_can_recheck_for_a_student(): void {
        global $DB;

        $this->login_as($this->student);
        submit_repo::execute((int) $this->cm->cmid, 'https://github.com/octocat/hello-world', self::SHA);

        $submissionid = $DB->get_field('codereview_submissions', 'id', ['userid' => $this->student->id]);
        $DB->set_field('codereview_submissions', 'cistatus', 'nocidetected', ['id' => $submissionid]);

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->login_as($teacher);

        $result = recheck_ci::execute((int) $this->cm->cmid, (int) $this->student->id);

        $this->assertSame('checking', $result['cistatus']);
    }
}
