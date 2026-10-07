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

        // A team submission completes every member, so the lookup goes through the
        // group: otherwise only whoever pressed submit would ever be marked complete.
        $instance = $DB->get_record('codereview', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $submission = submission_service::find_for_user($instance, (int) $this->userid);

        if (!$submission) {
            return COMPLETION_INCOMPLETE;
        }

        return $submission->gradestatus === submission_service::GRADE_GRADED
            ? COMPLETION_COMPLETE
            : COMPLETION_INCOMPLETE;
    }

    /**
     * Lists the rules this activity defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionsubmit'];
    }

    /**
     * Describes each rule for the activity completion summary.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionsubmit' => get_string('completiondetail:submit', 'mod_codereview'),
        ];
    }

    /**
     * Orders the rules in the interface.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionsubmit', 'completionusegrade'];
    }
}
