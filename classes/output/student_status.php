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
    /** @var string[] Statuses that will still change without anyone acting. */
    public const PENDING_STATUSES = ['pending', 'checking'];

    /** @var stdClass|null The submission row, or null when there is none. */
    protected ?stdClass $submission;

    /** @var int The course module id. */
    protected int $cmid;

    /** @var array The recorded check results. */
    protected array $checkruns;

    /**
     * Constructor.
     *
     * @param stdClass|null $submission The submission row, or null when there is none.
     * @param int $cmid The course module id.
     * @param array $checkruns The recorded check results.
     */
    public function __construct(?stdClass $submission, int $cmid, array $checkruns = []) {
        $this->submission = $submission;
        $this->cmid = $cmid;
        $this->checkruns = $checkruns;
    }

    /**
     * Builds the template context.
     *
     * @param renderer_base $output The renderer, unused.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        if ($this->submission === null) {
            return ['cmid' => $this->cmid, 'hassubmission' => false];
        }

        $submission = $this->submission;
        $pending = in_array($submission->cistatus, self::PENDING_STATUSES, true);

        return [
            'cmid' => $this->cmid,
            'hassubmission' => true,
            'repourl' => (string) $submission->repourl,
            'commitsha' => (string) $submission->commitsha,
            'cistatus' => (string) $submission->cistatus,
            'cistatuslabel' => get_string('ci' . $submission->cistatus, 'mod_codereview'),
            'aistatus' => (string) $submission->aistatus,
            'aistatuslabel' => get_string('ai' . $submission->aistatus, 'mod_codereview'),
            'gradestatus' => (string) $submission->gradestatus,
            'islate' => (bool) $submission->islate,
            'errormessage' => (string) $submission->errormessage,
            // Offering a recheck before the first poll has produced anything would only
            // spend quota on a question the queued task is already asking.
            'canrecheck' => !$pending || $this->checkruns !== [],
            'hascheckruns' => $this->checkruns !== [],
            'checkruns' => $this->checkruns,
        ];
    }
}
