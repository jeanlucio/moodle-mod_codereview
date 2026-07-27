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

namespace mod_codereview\local;

use completion_info;
use context_module;
use mod_codereview\event\grade_approved;
use mod_codereview\event\submission_reopened;
use moodle_exception;
use stdClass;

/**
 * Writes the teacher's decision and releases it to the gradebook.
 *
 * Nothing else in the plugin writes a grade. The automated checks, the AI review
 * and the authorship signals all stop at producing a suggestion; the gradebook
 * only ever hears from a teacher who pressed approve.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_approval_service {
    /**
     * Records an approved grade and pushes it to the gradebook.
     *
     * @param stdClass $instance The codereview instance row.
     * @param context_module $context The activity context.
     * @param stdClass $submission The submission being graded.
     * @param int $graderid The teacher approving.
     * @param float $finalgrade The grade to release.
     * @param string $feedback The comment for the student.
     * @return stdClass The stored decision.
     * @throws moodle_exception If the grade is outside the instance scale.
     */
    public function approve(
        stdClass $instance,
        context_module $context,
        stdClass $submission,
        int $graderid,
        float $finalgrade,
        string $feedback
    ): stdClass {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/codereview/lib.php');

        $grademax = (int) $instance->grade;
        if ($finalgrade < 0 || $finalgrade > $grademax) {
            throw new moodle_exception('errorgradeoutofrange', 'mod_codereview', '', $grademax);
        }

        $now = time();
        $existing = $DB->get_record('codereview_grades', ['submission' => $submission->id]);

        $record = new stdClass();
        $record->submission = (int) $submission->id;
        $record->graderid = $graderid;
        // The suggestion is frozen alongside the decision so that the two remain
        // comparable later, even after a resubmission changes what would be suggested.
        $record->suggestedgrade = grade_calculator::calculate($instance, $submission);
        $record->finalgrade = $finalgrade;
        $record->feedbackcomment = $feedback;
        $record->feedbackformat = FORMAT_PLAIN;
        $record->timemodified = $now;

        if ($existing) {
            $record->id = $existing->id;
            $record->timecreated = (int) $existing->timecreated;
            $DB->update_record('codereview_grades', $record);
        } else {
            $record->timecreated = $now;
            $record->id = $DB->insert_record('codereview_grades', $record);
        }

        $DB->set_field('codereview_submissions', 'gradestatus', submission_service::GRADE_GRADED, [
            'id' => $submission->id,
        ]);
        $DB->set_field('codereview_submissions', 'timemodified', $now, ['id' => $submission->id]);

        codereview_update_grades($instance, (int) $submission->userid);
        $this->update_completion($instance, $context, (int) $submission->userid);

        grade_approved::create_from_submission($context, $submission, $finalgrade)->trigger();

        return $record;
    }

    /**
     * Returns a submission to the editable state so the student can submit again.
     *
     * The grade already in the gradebook is left alone: reopening invites new work,
     * it does not retract the mark that was given for the old.
     *
     * @param stdClass $instance The codereview instance row.
     * @param context_module $context The activity context.
     * @param stdClass $submission The submission to reopen.
     * @param int $graderid The teacher reopening it.
     * @return void
     */
    public function reopen(
        stdClass $instance,
        context_module $context,
        stdClass $submission,
        int $graderid
    ): void {
        global $DB;

        $DB->set_field('codereview_submissions', 'gradestatus', submission_service::GRADE_NOTGRADED, [
            'id' => $submission->id,
        ]);
        $DB->set_field('codereview_submissions', 'timemodified', time(), ['id' => $submission->id]);

        $this->update_completion($instance, $context, (int) $submission->userid);

        submission_reopened::create_from_submission($context, $submission)->trigger();
    }

    /**
     * Recomputes activity completion for the student.
     *
     * @param stdClass $instance The codereview instance row.
     * @param context_module $context The activity context.
     * @param int $userid The student.
     * @return void
     */
    protected function update_completion(stdClass $instance, context_module $context, int $userid): void {
        $cm = get_coursemodule_from_instance('codereview', $instance->id, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $course = get_course($instance->course);
        $completion = new completion_info($course);

        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }
}
