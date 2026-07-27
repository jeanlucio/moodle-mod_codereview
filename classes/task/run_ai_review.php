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

use context_module;
use core\task\adhoc_task;
use core\task\manager;
use mod_codereview\local\ai_reviewer;

/**
 * Generates the AI grade suggestion for one submission.
 *
 * Runs after the automated checks have settled, so that their results can go into
 * the prompt: a reviewer that knows which tests failed writes more useful feedback
 * than one looking at the code alone.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_ai_review extends adhoc_task {
    /**
     * Queues a review for a submission, reusing any review already waiting for it.
     *
     * @param int $submissionid The submission to review.
     * @return void
     */
    public static function queue(int $submissionid): void {
        $task = new self();
        $task->set_custom_data((object) ['submissionid' => $submissionid]);
        $task->set_component('mod_codereview');

        manager::queue_adhoc_task($task, true);
    }

    /**
     * Runs the review.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $submissionid = (int) ($data->submissionid ?? 0);

        $submission = $DB->get_record('codereview_submissions', ['id' => $submissionid]);
        if (!$submission) {
            // Replaced or deleted while the task waited.
            return;
        }

        $instance = $DB->get_record('codereview', ['id' => $submission->codereview]);
        if (!$instance) {
            return;
        }

        $cm = get_coursemodule_from_instance('codereview', $instance->id, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        ai_reviewer::for_instance($instance)->review($instance, $submission, context_module::instance($cm->id));
    }
}
