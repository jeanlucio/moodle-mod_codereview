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
use mod_codereview\exception\github_exception;
use mod_codereview\local\fingerprint_service;
use mod_codereview\local\integrity_checker;

/**
 * Records a submission's fingerprints and raises its authorship signals.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_integrity_check extends adhoc_task {
    /**
     * Queues a check for a submission, reusing any check already waiting for it.
     *
     * @param int $submissionid The submission to check.
     * @return void
     */
    public static function queue(int $submissionid): void {
        $task = new self();
        $task->set_custom_data((object) ['submissionid' => $submissionid]);
        $task->set_component('mod_codereview');

        manager::queue_adhoc_task($task, true);
    }

    /**
     * Records the fingerprints and recomputes the signals.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $submissionid = (int) ($data->submissionid ?? 0);

        $submission = $DB->get_record('codereview_submissions', ['id' => $submissionid]);
        if (!$submission) {
            return;
        }

        $instance = $DB->get_record('codereview', ['id' => $submission->codereview]);
        if (!$instance || empty($instance->integritychecks)) {
            return;
        }

        $fingerprints = fingerprint_service::for_instance($instance);

        try {
            $fingerprints->ensure_baseline($instance);
            $fingerprints->record($instance, $submission);
        } catch (github_exception $e) {
            // Without fingerprints there is nothing to compare, and a partial
            // comparison would be worse than none: it could clear a submission whose
            // files simply were not read.
            return;
        }

        $checker = new integrity_checker();
        $checker->check($instance, $submission);
        $this->refresh_matched_peers($instance, $submission, $checker);
    }

    /**
     * Recomputes the signals of the submissions this one was matched against.
     *
     * A match is symmetric, so recomputing only the new submission would leave the
     * other side's panel claiming it overlaps with nobody. Only the peers actually
     * named in a flag are refreshed: recomputing the whole cohort on every submission
     * would cost a quadratic number of queries across the activity for no extra
     * information, since a peer that matched nothing cannot have started matching.
     *
     * @param \stdClass $instance The codereview instance row.
     * @param \stdClass $submission The submission that was just recorded.
     * @param integrity_checker $checker The checker to reuse.
     * @return void
     */
    protected function refresh_matched_peers(
        \stdClass $instance,
        \stdClass $submission,
        integrity_checker $checker
    ): void {
        global $DB;

        $peerids = $DB->get_fieldset_select(
            'codereview_flags',
            'DISTINCT peersubmission',
            'submission = ? AND peersubmission IS NOT NULL',
            [$submission->id]
        );

        if (!$peerids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($peerids);
        $peers = $DB->get_records_select('codereview_submissions', "id $insql", $params);

        foreach ($peers as $peer) {
            $checker->check($instance, $peer);
        }
    }
}
