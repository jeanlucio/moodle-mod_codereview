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

namespace mod_codereview\task;

use advanced_testcase;
use core\task\manager;
use mod_codereview\fixtures\github_client_stub;
use mod_codereview\local\github_client;
use mod_codereview\local\submission_service;
use stdClass;

/**
 * Tests for the adhoc task that polls GitHub for a submission's check-runs.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\task\poll_check_runs
 */
final class poll_check_runs_test extends advanced_testcase {
    /** @var string A valid looking commit SHA used across the tests. */
    private const SHA = '1234567890abcdef1234567890abcdef12345678';

    /** @var int The submission being polled. */
    private int $submissionid;

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
     * Creates a submission waiting for checks that GitHub reports as still running.
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

        $this->submissionid = (int) $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $module->id,
            'userid' => $student->id,
            'repourl' => 'https://github.com/octocat/hello-world',
            'repoowner' => 'octocat',
            'reponame' => 'hello-world',
            'commitsha' => self::SHA,
            'cistatus' => submission_service::CI_PENDING,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timesubmitted' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $stub = new github_client_stub();
        $stub->set_response('/repos/octocat/hello-world/commits/' . self::SHA . '/check-runs', [
            'total_count' => 1,
            'check_runs' => [[
                'id' => 1,
                'name' => 'tests',
                'status' => 'in_progress',
                'conclusion' => null,
                'app' => ['slug' => 'github-actions'],
                'html_url' => 'https://github.com/octocat/hello-world/runs/1',
                'started_at' => '2026-07-20T10:00:00Z',
                'completed_at' => null,
            ]],
        ]);
        github_client::set_instance_for_testing($stub);
    }

    /**
     * Drops the test double so it cannot leak into other tests.
     *
     * @return void
     */
    protected function tearDown(): void {
        github_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Returns the polls queued for the submission.
     *
     * @return stdClass[]
     */
    private function queued_polls(): array {
        global $DB;

        return array_values(array_filter(
            $DB->get_records('task_adhoc', ['classname' => '\\' . poll_check_runs::class]),
            fn(stdClass $record): bool => (int) json_decode($record->customdata)->submissionid === $this->submissionid
        ));
    }

    /**
     * Runs one queued task the way cron does: its row stays in task_adhoc while it executes.
     *
     * @param stdClass $record The task_adhoc row.
     * @return void
     */
    private function run_as_cron(stdClass $record): void {
        $task = manager::adhoc_task_from_record($record);
        $task->set_lock($this->createStub(\core\lock\lock::class));
        $task->execute();
        manager::adhoc_task_complete($task);
    }

    /**
     * While checks are still running, the poll queues the next one. Core keeps the running
     * task's own row until it finishes, so a duplicate check that counted it would drop the
     * next poll and leave the submission waiting for the hourly reconcile task instead.
     *
     * @return void
     */
    public function test_requeues_itself_while_checks_run(): void {
        poll_check_runs::queue($this->submissionid);
        [$first] = $this->queued_polls();

        $this->run_as_cron($first);

        $polls = $this->queued_polls();
        $this->assertCount(1, $polls);
        $this->assertNotEquals($first->id, $polls[0]->id);
        $this->assertGreaterThan(time() - 1, (int) $polls[0]->nextruntime);
    }

    /**
     * A poll already waiting for the same submission (for instance one queued by "check
     * again") makes the next one redundant: polls never stack up against the GitHub quota.
     *
     * @return void
     */
    public function test_does_not_stack_a_second_waiting_poll(): void {
        poll_check_runs::queue($this->submissionid);
        [$first] = $this->queued_polls();

        $waiting = new poll_check_runs();
        $waiting->set_custom_data((object) ['submissionid' => $this->submissionid]);
        $waiting->set_component('mod_codereview');
        $waiting->set_next_run_time(time() + 300);
        manager::queue_adhoc_task($waiting);

        $this->run_as_cron($first);

        $this->assertCount(1, $this->queued_polls());
    }
}
