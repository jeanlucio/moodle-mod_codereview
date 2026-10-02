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
use mod_codereview\local\submission_service;
use stdClass;

/**
 * Tests for the task that closes out submissions stuck waiting for CI.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\task\reconcile_submissions
 */
final class reconcile_submissions_test extends advanced_testcase {
    /**
     * Inserts a submission still waiting for its checks.
     *
     * @param stdClass $instance The activity instance.
     * @param int $userid The student.
     * @param int $timecreated When the student first submitted.
     * @param int $timesubmitted When the student last submitted.
     * @return int The submission id.
     */
    private function pending_submission(stdClass $instance, int $userid, int $timecreated, int $timesubmitted): int {
        global $DB;

        return (int) $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $instance->id,
            'userid' => $userid,
            'repourl' => 'https://github.com/octocat/hello-world',
            'repoowner' => 'octocat',
            'reponame' => 'hello-world',
            'commitsha' => str_repeat('a', 40),
            'cistatus' => submission_service::CI_PENDING,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timesubmitted' => $timesubmitted,
            'timecreated' => $timecreated,
            'timemodified' => $timesubmitted,
        ]);
    }

    /**
     * Only a submission whose latest submit is past the timeout is closed out. One that
     * was first submitted long ago but resubmitted just now is still within its window.
     *
     * @return void
     */
    public function test_timeout_counts_from_latest_submission(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id, 'citimeout' => 30]);
        $instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);
        $resubmitter = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $stale = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $twodaysago = time() - 2 * DAYSECS;
        $resubmitted = $this->pending_submission($instance, (int) $resubmitter->id, $twodaysago, time());
        $stuck = $this->pending_submission($instance, (int) $stale->id, $twodaysago, $twodaysago);

        (new reconcile_submissions())->execute();
        $sink->close();

        $this->assertSame(
            submission_service::CI_PENDING,
            $DB->get_field('codereview_submissions', 'cistatus', ['id' => $resubmitted])
        );
        $this->assertSame(
            submission_service::CI_NOCIDETECTED,
            $DB->get_field('codereview_submissions', 'cistatus', ['id' => $stuck])
        );
    }
}
