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

use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The state of the student's own submission.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_status implements renderable, templatable {
    /** @var stdClass|null The submission row, or null when there is none. */
    protected ?stdClass $submission;

    /** @var int The course module id. */
    protected int $cmid;

    /**
     * Constructor.
     *
     * @param stdClass|null $submission The submission row, or null when there is none.
     * @param int $cmid The course module id.
     */
    public function __construct(?stdClass $submission, int $cmid) {
        $this->submission = $submission;
        $this->cmid = $cmid;
    }

    /**
     * Builds the template context.
     *
     * Deliberately thin: what the checks and the AI made of the work is the teacher's to
     * read, so none of it is exported here.
     *
     * @param renderer_base $output The renderer, unused.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        if ($this->submission === null) {
            return ['cmid' => $this->cmid, 'hassubmission' => false];
        }

        $submission = $this->submission;
        $sent = (int) ($submission->timesubmitted ?: $submission->timecreated);

        return [
            'cmid' => $this->cmid,
            'hassubmission' => true,
            'repourl' => (string) $submission->repourl,
            'commitsha' => (string) $submission->commitsha,
            'submitteddateformatted' => userdate($sent),
            'islate' => (bool) $submission->islate,
            'isgraded' => $submission->gradestatus === 'graded',
        ];
    }
}
