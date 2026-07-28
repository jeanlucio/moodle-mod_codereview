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

namespace mod_codereview\completion;

use core_completion\activity_custom_completion;
use mod_codereview\local\grade_calculator;
use mod_codereview\local\submission_service;

/**
 * Custom activity completion rules.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Evaluates one rule for the user.
     *
     * @param string $rule The rule being evaluated.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $submission = $DB->get_record('codereview_submissions', [
            'codereview' => $this->cm->instance,
            'userid' => $this->userid,
        ]);

        if (!$submission) {
            return COMPLETION_INCOMPLETE;
        }

        if ($rule === 'completionsubmit') {
            return $submission->gradestatus === submission_service::GRADE_GRADED
                ? COMPLETION_COMPLETE
                : COMPLETION_INCOMPLETE;
        }

        $required = (int) ($this->cm->customdata['customcompletionrules']['completionchecks'] ?? 0);
        if ($required <= 0) {
            return COMPLETION_INCOMPLETE;
        }

        [$insql, $params] = $DB->get_in_or_equal(grade_calculator::PASSING, SQL_PARAMS_NAMED, 'con');
        $params['submission'] = $submission->id;

        $passed = $DB->count_records_select(
            'codereview_checkruns',
            "submission = :submission AND counted = 1 AND conclusion $insql",
            $params
        );

        return $passed >= $required ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Lists the rules this activity defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionsubmit', 'completionchecks'];
    }

    /**
     * Describes each rule for the activity completion summary.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $required = (int) ($this->cm->customdata['customcompletionrules']['completionchecks'] ?? 0);

        return [
            'completionsubmit' => get_string('completiondetail:submit', 'mod_codereview'),
            'completionchecks' => get_string('completiondetail:checks', 'mod_codereview', $required),
        ];
    }

    /**
     * Orders the rules in the interface.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionsubmit', 'completionchecks', 'completionusegrade'];
    }
}
