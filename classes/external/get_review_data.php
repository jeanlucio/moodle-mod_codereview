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
use mod_codereview\local\review_service;

/**
 * Web service that returns everything the review screen shows.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_review_data extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'submissionid' => new external_value(PARAM_INT, 'Submission to review'),
        ]);
    }

    /**
     * Returns the review payload.
     *
     * @param int $submissionid Submission to review.
     * @return array
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

        $submission = review_service::get_submission($instance, $submissionid);
        $data = review_service::get_review_data($instance, $submission);

        // The web service carries the numbers and statuses the screen refreshes.
        // The flag evidence stays out: it names another student, and a payload that
        // travels further than the page it was built for is the wrong place for it.
        unset($data['flags']);

        return $data + ['flagcount' => $DB->count_records('codereview_flags', ['submission' => $submissionid])];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'submissionid' => new external_value(PARAM_INT, 'The submission'),
            'studentname' => new external_value(PARAM_TEXT, 'Who submitted'),
            'repourl' => new external_value(PARAM_URL, 'Repository URL'),
            'repoowner' => new external_value(PARAM_TEXT, 'Repository owner'),
            'commitsha' => new external_value(PARAM_ALPHANUM, 'Submitted commit'),
            'authorlogin' => new external_value(PARAM_TEXT, 'GitHub account that authored the commit'),
            'commitauthordate' => new external_value(PARAM_INT, 'Commit date declared by the author'),
            'timecreated' => new external_value(PARAM_INT, 'When it was submitted in Moodle'),
            'islate' => new external_value(PARAM_BOOL, 'Whether it is past the due date'),
            'truncated' => new external_value(PARAM_BOOL, 'Whether the reviewed code was cut by the size budget'),
            'cistatus' => new external_value(PARAM_ALPHA, 'Automated check status'),
            'aistatus' => new external_value(PARAM_ALPHA, 'AI review status'),
            'gradestatus' => new external_value(PARAM_ALPHA, 'Grading status'),
            'errormessage' => new external_value(PARAM_TEXT, 'Why the last attempt failed, if it did'),
            'grademax' => new external_value(PARAM_INT, 'Maximum grade of the activity'),
            'suggestedgrade' => new external_value(PARAM_FLOAT, 'Suggested grade, null when none', VALUE_OPTIONAL),
            'finalgrade' => new external_value(PARAM_FLOAT, 'Approved grade, null when not approved', VALUE_OPTIONAL),
            'feedbackcomment' => new external_value(PARAM_RAW, 'Teacher comment'),
            'flagcount' => new external_value(PARAM_INT, 'How many authorship signals were raised'),
            'checkruns' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Check name'),
                    'conclusion' => new external_value(PARAM_ALPHAEXT, 'Check conclusion'),
                    'appslug' => new external_value(PARAM_TEXT, 'Owning GitHub app'),
                    'counted' => new external_value(PARAM_BOOL, 'Whether it counts towards the grade'),
                    'passed' => new external_value(PARAM_BOOL, 'Whether it passed'),
                    'detailsurl' => new external_value(PARAM_URL, 'Link to the run on GitHub'),
                ])
            ),
            'airesult' => new external_single_structure([
                'status' => new external_value(PARAM_ALPHA, 'Whether the review succeeded'),
                'provider' => new external_value(PARAM_TEXT, 'Which provider answered'),
                'model' => new external_value(PARAM_TEXT, 'Which model answered'),
                'suggestedgrade' => new external_value(PARAM_FLOAT, 'Grade suggested by the AI', VALUE_OPTIONAL),
                'feedback' => new external_value(PARAM_RAW, 'Feedback text'),
                'errormessage' => new external_value(PARAM_TEXT, 'Why the review failed, if it did'),
            ], 'The most recent AI review', VALUE_OPTIONAL),
        ]);
    }
}
