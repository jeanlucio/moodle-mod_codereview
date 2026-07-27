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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Web service that reports the status of the caller's own submission.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_submission_status extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the activity'),
        ]);
    }

    /**
     * Returns the caller's submission status and its check-runs.
     *
     * @param int $cmid Course module id of the activity.
     * @return array The submission status.
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'codereview');
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/codereview:submit', $context);

        // Bound to both the instance and the caller: a submission id is never trusted
        // from the client, so a foreign one cannot be read through this function.
        $submission = $DB->get_record('codereview_submissions', [
            'codereview' => $cm->instance,
            'userid' => $USER->id,
        ]);

        if (!$submission) {
            return [
                'hassubmission' => false,
                'cistatus' => '',
                'aistatus' => '',
                'gradestatus' => '',
                'islate' => false,
                'errormessage' => '',
                'checkruns' => [],
            ];
        }

        $checkruns = $DB->get_records('codereview_checkruns', ['submission' => $submission->id], 'checkname ASC');

        return [
            'hassubmission' => true,
            'cistatus' => $submission->cistatus,
            'aistatus' => $submission->aistatus,
            'gradestatus' => $submission->gradestatus,
            'islate' => (bool) $submission->islate,
            'errormessage' => (string) $submission->errormessage,
            'checkruns' => array_values(array_map(static fn($run) => [
                'name' => $run->checkname,
                'conclusion' => (string) $run->conclusion,
                'counted' => (bool) $run->counted,
                'detailsurl' => (string) $run->detailsurl,
            ], $checkruns)),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hassubmission' => new external_value(PARAM_BOOL, 'Whether the caller has submitted'),
            'cistatus' => new external_value(PARAM_ALPHA, 'Automated check status'),
            'aistatus' => new external_value(PARAM_ALPHA, 'AI review status'),
            'gradestatus' => new external_value(PARAM_ALPHA, 'Grading status'),
            'islate' => new external_value(PARAM_BOOL, 'Whether the submission is past the due date'),
            'errormessage' => new external_value(PARAM_TEXT, 'Why the last attempt failed, if it did'),
            'checkruns' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Check name'),
                    'conclusion' => new external_value(PARAM_ALPHAEXT, 'Check conclusion'),
                    'counted' => new external_value(PARAM_BOOL, 'Whether it counts towards the grade'),
                    'detailsurl' => new external_value(PARAM_URL, 'Link to the run on GitHub'),
                ])
            ),
        ]);
    }
}
