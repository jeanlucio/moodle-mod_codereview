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
use mod_codereview\local\submission_service;
use mod_codereview\task\run_ai_review;

/**
 * Web service that asks for the AI review to be generated again.
 *
 * Providers fail, time out and answer with unusable text. Without this the
 * submission would keep whatever failure it landed on forever.
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
     * Queues a fresh review.
     *
     * @param int $submissionid Submission to review again.
     * @return array The resulting AI status.
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

        $DB->set_field('codereview_submissions', 'aistatus', submission_service::AI_PENDING, [
            'id' => $submissionid,
        ]);

        // Reusing any review already queued keeps a repeated click from spending the
        // provider budget several times over on the same submission.
        run_ai_review::queue($submissionid);

        return ['aistatus' => submission_service::AI_PENDING];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'aistatus' => new external_value(PARAM_ALPHA, 'AI review status after queueing'),
        ]);
    }
}
