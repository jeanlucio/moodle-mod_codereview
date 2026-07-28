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

namespace mod_codereview\output;

use moodleform;
use plugin_renderer_base;
use stdClass;

/**
 * Renderer for mod_codereview.
 *
 * Each method is a thin bridge from a renderable to its template: the decisions
 * the templates branch on are made in the renderables, where they can be asserted
 * as data rather than read back out of HTML.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Renders the teacher review screen.
     *
     * @param review_page $page The review screen.
     * @return string
     */
    public function render_review_page(review_page $page): string {
        return $this->render_from_template('mod_codereview/review_page', $page->export_for_template($this));
    }

    /**
     * Renders the state of the student's own submission.
     *
     * @param student_status $status The submission state.
     * @return string
     */
    public function render_student_status(student_status $status): string {
        return $this->render_from_template('mod_codereview/student_status', $status->export_for_template($this));
    }

    /**
     * Renders the student's submission form and the notices that go with it.
     *
     * @param moodleform $form The submission form.
     * @param stdClass $instance The codereview instance row.
     * @param int $cmid The course module id.
     * @param bool $isresubmission Whether the student already has a submission.
     * @return string
     */
    public function render_submit_form(moodleform $form, stdClass $instance, int $cmid, bool $isresubmission): string {
        return $this->render_from_template('mod_codereview/submit_form', [
            'cmid' => $cmid,
            'formhtml' => $form->render(),
            'integritychecks' => !empty($instance->integritychecks),
            'isresubmission' => $isresubmission,
        ]);
    }
}
