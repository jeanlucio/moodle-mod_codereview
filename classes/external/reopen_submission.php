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
use mod_codereview\local\grade_approval_service;
use mod_codereview\local\review_service;

/**
 * Web service that returns a graded submission to the editable state.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reopen_submission extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'submissionid' => new external_value(PARAM_INT, 'Submission to reopen'),
        ]);
    }

    /**
     * Reopens the submission.
     *
     * @param int $submissionid Submission to reopen.
     * @return array The resulting grading status.
     */
    public static function execute(int $submissionid): array {
        global $DB, $USER;

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

        $submission = review_service::get_submission($instance, $submissionid);
        (new grade_approval_service())->reopen($instance, $context, $submission, (int) $USER->id);

        return ['gradestatus' => 'notgraded'];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'gradestatus' => new external_value(PARAM_ALPHA, 'Grading status after reopening'),
        ]);
    }
}
