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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_codereview\local\submission_service;

/**
 * Web service that records a repository submission.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_repo extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the activity'),
            'repourl' => new external_value(PARAM_RAW_TRIMMED, 'Public GitHub repository URL'),
            'commitsha' => new external_value(PARAM_RAW_TRIMMED, 'Full 40 character commit SHA'),
        ]);
    }

    /**
     * Records the submission after confirming it against the GitHub API.
     *
     * @param int $cmid Course module id of the activity.
     * @param string $repourl Public GitHub repository URL.
     * @param string $commitsha Full 40 character commit SHA.
     * @return array The stored submission status.
     */
    public static function execute(int $cmid, string $repourl, string $commitsha): array {
        global $DB, $USER;

        [
            'cmid' => $cmid,
            'repourl' => $repourl,
            'commitsha' => $commitsha,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'repourl' => $repourl,
            'commitsha' => $commitsha,
        ]);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'codereview');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/codereview:submit', $context);
        require_sesskey();

        $instance = $DB->get_record('codereview', ['id' => $cm->instance], '*', MUST_EXIST);

        $service = submission_service::for_instance($instance);
        $submission = $service->submit($instance, $context, (int) $USER->id, $repourl, $commitsha);

        return [
            'submissionid' => (int) $submission->id,
            'cistatus' => $submission->cistatus,
            'islate' => (bool) $submission->islate,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'submissionid' => new external_value(PARAM_INT, 'Id of the stored submission'),
            'cistatus' => new external_value(PARAM_ALPHA, 'Current automated check status'),
            'islate' => new external_value(PARAM_BOOL, 'Whether the submission is past the due date'),
        ]);
    }
}
