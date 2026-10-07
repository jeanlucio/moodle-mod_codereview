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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_codereview\local\manual_runner;
use mod_codereview\local\submission_service;

/**
 * Web service that generates the AI review again, right away.
 *
 * Providers fail, time out and answer with unusable text. Without this the
 * submission would keep whatever failure it landed on forever. It runs inside the
 * request, not through cron: the teacher who pressed the button is watching, and the
 * reason for a failure is exactly what they need to read.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rerun_ai_review extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'submissionid' => new external_value(PARAM_INT, 'Submission to review again'),
        ]);
    }

    /**
     * Runs a fresh review and reports how it ended.
     *
     * @param int $submissionid Submission to review again.
     * @return array The resulting AI status and, when it failed, why.
     */
    public static function execute(int $submissionid): array {
        global $DB;

        ['submissionid' => $submissionid] = self::validate_parameters(
            self::execute_parameters(),
            ['submissionid' => $submissionid]
        );

        $instanceid = $DB->get_field('codereview_submissions', 'codereview', ['id' => $submissionid], MUST_EXIST);
        $instance = $DB->get_record('codereview', ['id' => $instanceid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('codereview', $instance->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/codereview:grade', $context);
        require_sesskey();

        $submission = $DB->get_record('codereview_submissions', ['id' => $submissionid], '*', MUST_EXIST);

        // A second click while this one runs is refused by the runner rather than
        // queued, so the provider budget is not spent twice on the same submission.
        $status = manual_runner::rerun_ai($instance, $submission, $context);

        $error = '';
        if ($status === submission_service::AI_ERROR) {
            $latest = $DB->get_records(
                'codereview_airesults',
                ['submission' => $submissionid],
                'id DESC',
                'id, errormessage',
                0,
                1
            );
            $error = (string) (reset($latest)->errormessage ?? '');
        }

        return ['aistatus' => $status, 'error' => $error];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'aistatus' => new external_value(PARAM_ALPHA, 'AI review status after the run'),
            'error' => new external_value(PARAM_TEXT, 'Why the run failed, empty when it did not'),
        ]);
    }
}
