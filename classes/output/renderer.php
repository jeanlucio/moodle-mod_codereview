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

use plugin_renderer_base;

/**
 * Renderer for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Renders the student's submission form and the notices that go with it.
     *
     * @param \moodleform $form The submission form.
     * @param \stdClass $instance The codereview instance row.
     * @param int $cmid The course module id.
     * @param bool $isresubmission Whether the student already has a submission.
     * @return string
     */
    public function render_submit_form(\moodleform $form, \stdClass $instance, int $cmid, bool $isresubmission): string {
        return $this->render_from_template('mod_codereview/submit_form', [
            'cmid' => $cmid,
            'formhtml' => $form->render(),
            'integritychecks' => !empty($instance->integritychecks),
            'isresubmission' => $isresubmission,
        ]);
    }

    /**
     * Renders the state of the student's own submission.
     *
     * @param \stdClass|false $submission The submission row, or false when there is none.
     * @param int $cmid The course module id.
     * @return string
     */
    public function render_student_status($submission, int $cmid): string {
        global $DB;

        if (!$submission) {
            return $this->render_from_template('mod_codereview/student_status', [
                'cmid' => $cmid,
                'hassubmission' => false,
            ]);
        }

        $runs = $DB->get_records('codereview_checkruns', ['submission' => $submission->id], 'checkname ASC');

        $checkruns = array_values(array_map(static fn($run) => [
            'name' => (string) $run->checkname,
            'conclusion' => (string) $run->conclusion,
            'passed' => $run->conclusion === 'success',
            'detailsurl' => (string) $run->detailsurl,
        ], $runs));

        $pending = in_array($submission->cistatus, ['pending', 'checking'], true);

        return $this->render_from_template('mod_codereview/student_status', [
            'cmid' => $cmid,
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
            // Rechecking before the first poll has produced anything would only spend
            // quota on a question the queued task is already asking.
            'canrecheck' => !$pending || $checkruns !== [],
            'hascheckruns' => $checkruns !== [],
            'checkruns' => $checkruns,
        ]);
    }

    /**
     * Renders the teacher review screen.
     *
     * @param array $data The payload from review_service.
     * @param int $cmid The course module id, for the action buttons.
     * @return string
     */
    public function render_review_page(array $data, int $cmid): string {
        $context = $data;

        $context['hasflags'] = !empty($data['flags']);
        $context['hascheckruns'] = !empty($data['checkruns']);
        $context['hasairesult'] = !empty($data['airesult']);
        $context['aisucceeded'] = ($data['airesult']['status'] ?? '') === 'completed';
        $context['hassuggestion'] = $data['suggestedgrade'] !== null;
        $context['suggestedgradeformatted'] = $data['suggestedgrade'] !== null
            ? format_float($data['suggestedgrade'], 2)
            : '';
        $context['aigradeformatted'] = isset($data['airesult']['suggestedgrade'])
            && $data['airesult']['suggestedgrade'] !== null
            ? format_float((float) $data['airesult']['suggestedgrade'], 2)
            : '';
        $context['commitdateformatted'] = $data['commitauthordate'] > 0
            ? userdate($data['commitauthordate'])
            : '';
        $context['submitteddateformatted'] = userdate($data['timecreated']);
        $context['cistatuslabel'] = get_string('ci' . $data['cistatus'], 'mod_codereview');
        $context['aistatuslabel'] = get_string('ai' . $data['aistatus'], 'mod_codereview');
        $context['sesskey'] = sesskey();
        $context['cmid'] = $cmid;

        foreach ($context['flags'] as $index => $flag) {
            $context['flags'][$index]['severitylabel'] = get_string('severity' . $flag['severity'], 'mod_codereview');
            $context['flags'][$index]['ishigh'] = $flag['severity'] === 'high';
        }

        return $this->render_from_template('mod_codereview/review_page', $context);
    }
}
