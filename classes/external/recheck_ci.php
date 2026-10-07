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
use moodle_exception;

/**
 * Web service that reads the automated checks of a submission again, right away.
 *
 * This is the escape hatch for every way polling can end without an answer: a
 * workflow that finished after the timeout, a rate limit hit mid-window, GitHub
 * being briefly unreachable. It is for graders only. It runs inside the request and
 * reports the outcome, because the person who pressed the button is watching and has
 * to read any failure; a student never sees the checks, so has nothing to recheck.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recheck_ci extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the activity'),
            'userid' => new external_value(PARAM_INT, 'Whose submission to recheck'),
        ]);
    }

    /**
     * Reads the checks again and reports how it ended.
     *
     * @param int $cmid Course module id of the activity.
     * @param int $userid Whose submission to recheck.
     * @return array The submission status and, when the run failed, why.
     */
    public static function execute(int $cmid, int $userid): array {
        global $DB;

        [
            'cmid' => $cmid,
            'userid' => $userid,
        ] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'userid' => $userid]);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'codereview');
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_sesskey();
        require_capability('mod/codereview:grade', $context);

        $instance = $DB->get_record('codereview', ['id' => $cm->instance], '*', MUST_EXIST);
        $submission = submission_service::find_for_user($instance, $userid);

        if (!$submission) {
            throw new moodle_exception('errornosubmission', 'mod_codereview');
        }

        $status = manual_runner::recheck_ci($instance, $submission);
        $error = (string) $DB->get_field('codereview_submissions', 'errormessage', ['id' => $submission->id]);

        return ['cistatus' => $status, 'error' => $error];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cistatus' => new external_value(PARAM_ALPHA, 'Automated check status after the run'),
            'error' => new external_value(PARAM_TEXT, 'Why the run failed, empty when it did not'),
        ]);
    }
}
