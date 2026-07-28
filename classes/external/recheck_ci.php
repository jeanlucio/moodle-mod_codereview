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
use mod_codereview\task\poll_check_runs;
use moodle_exception;

/**
 * Web service that asks for the automated checks to be read again.
 *
 * This is the escape hatch for every way polling can end without an answer: a
 * workflow that finished after the timeout, a rate limit hit mid-window, GitHub
 * being briefly unreachable.
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
            'userid' => new external_value(PARAM_INT, 'Whose submission to recheck, 0 for the caller', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Queues a fresh poll for the submission.
     *
     * @param int $cmid Course module id of the activity.
     * @param int $userid Whose submission to recheck, 0 for the caller.
     * @return array The submission status after queueing.
     */
    public static function execute(int $cmid, int $userid = 0): array {
        global $DB, $USER;

        [
            'cmid' => $cmid,
            'userid' => $userid,
        ] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'userid' => $userid]);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'codereview');
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_sesskey();

        $targetid = $userid > 0 ? $userid : (int) $USER->id;

        // Rechecking someone else's submission is a grader action; rechecking your own
        // only needs the submit capability.
        if ($targetid !== (int) $USER->id) {
            require_capability('mod/codereview:grade', $context);
        } else {
            require_capability('mod/codereview:submit', $context);
        }

        $instance = $DB->get_record('codereview', ['id' => $cm->instance], '*', MUST_EXIST);
        $submission = submission_service::find_for_user($instance, $targetid);

        if (!$submission) {
            throw new moodle_exception('errornosubmission', 'mod_codereview');
        }

        if ($submission->cistatus === submission_service::CI_PENDING) {
            throw new moodle_exception('errorrecheckpending', 'mod_codereview');
        }

        $DB->set_field('codereview_submissions', 'cistatus', submission_service::CI_CHECKING, [
            'id' => $submission->id,
        ]);

        // Reusing any poll already queued for this submission is what keeps a repeated
        // click from stacking parallel pollers against the site's GitHub quota.
        poll_check_runs::queue((int) $submission->id);

        return ['cistatus' => submission_service::CI_CHECKING];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cistatus' => new external_value(PARAM_ALPHA, 'Automated check status after queueing'),
        ]);
    }
}
