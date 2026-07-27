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

use core\task\adhoc_task;
use core\task\manager;
use mod_codereview\local\checkrun_poller;
use mod_codereview\local\notifier;
use mod_codereview\local\submission_service;

/**
 * Polls GitHub for the check-run results of one submission.
 *
 * The task carries nothing but the submission id. Backoff is derived from how long
 * the submission has been waiting rather than from an attempt counter, which keeps
 * the custom data identical across reschedules so that
 * {@see manager::queue_adhoc_task()} can deduplicate them: a student hammering
 * "check again" cannot stack up parallel pollers against the GitHub quota.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poll_check_runs extends adhoc_task {
    /**
     * Queues a poll for a submission, reusing any poll already waiting for it.
     *
     * @param int $submissionid The submission to poll for.
     * @param int $delay Seconds to wait before the first attempt.
     * @return void
     */
    public static function queue(int $submissionid, int $delay = 0): void {
        $task = new self();
        $task->set_custom_data((object) ['submissionid' => $submissionid]);
        $task->set_component('mod_codereview');

        if ($delay > 0) {
            $task->set_next_run_time(time() + $delay);
        }

        manager::queue_adhoc_task($task, true);
    }

    /**
     * Polls once and reschedules itself while there is still something to wait for.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $submissionid = (int) ($data->submissionid ?? 0);

        $submission = $DB->get_record('codereview_submissions', ['id' => $submissionid]);
        if (!$submission) {
            // The submission was replaced or the activity deleted while the task waited.
            return;
        }

        $instance = $DB->get_record('codereview', ['id' => $submission->codereview]);
        if (!$instance) {
            return;
        }

        $previous = $submission->cistatus;
        $poller = checkrun_poller::for_instance($instance);
        $status = $poller->poll($instance, $submission);

        if ($status === submission_service::CI_CHECKING || $status === submission_service::CI_PENDING) {
            self::queue($submissionid, $this->next_delay($submission));

            return;
        }

        if ($status === submission_service::CI_NOCIDETECTED && $previous !== $status) {
            notifier::notify_no_ci_detected($instance, $submission);
        }
    }

    /**
     * Returns how long to wait before the next attempt.
     *
     * Checks usually finish within a couple of minutes, so the first attempts are
     * close together and then spread out, keeping the request count per submission
     * low without making a fast workflow wait needlessly.
     *
     * @param \stdClass $submission The submission being polled.
     * @return int Seconds.
     */
    protected function next_delay(\stdClass $submission): int {
        $waiting = time() - (int) $submission->timecreated;

        if ($waiting < 2 * MINSECS) {
            return 30;
        }
        if ($waiting < 10 * MINSECS) {
            return 2 * MINSECS;
        }

        return 5 * MINSECS;
    }
}
