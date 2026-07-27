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

/**
 * Teacher review screen for one submission.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/grade_form.php');

use mod_codereview\local\grade_approval_service;
use mod_codereview\local\review_service;
use mod_codereview\local\submission_service;

$submissionid = required_param('id', PARAM_INT);
$reopen = optional_param('reopen', 0, PARAM_BOOL);

$instanceid = $DB->get_field('codereview_submissions', 'codereview', ['id' => $submissionid], MUST_EXIST);
$instance = $DB->get_record('codereview', ['id' => $instanceid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('codereview', $instance->id, 0, false, MUST_EXIST);
$course = get_course($instance->course);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/codereview:grade', $context);

$submission = review_service::get_submission($instance, $submissionid);

$url = new moodle_url('/mod/codereview/review.php', ['id' => $submissionid]);
$backurl = new moodle_url('/mod/codereview/view.php', ['id' => $cm->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('path-mod-codereview');

$service = new grade_approval_service();

if ($reopen) {
    require_sesskey();
    $service->reopen($instance, $context, $submission, (int) $USER->id);
    redirect($url, get_string('submissionreopened', 'mod_codereview'));
}

$data = review_service::get_review_data($instance, $submission);
$form = new mod_codereview_grade_form($url, ['grademax' => $data['grademax']]);

if ($form->is_cancelled()) {
    redirect($backurl);
}

if ($formdata = $form->get_data()) {
    require_sesskey();
    $service->approve(
        $instance,
        $context,
        $submission,
        (int) $USER->id,
        (float) $formdata->finalgrade,
        (string) $formdata->feedbackcomment
    );
    redirect($backurl, get_string('gradeapproved', 'mod_codereview'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$form->set_data([
    'finalgrade' => $data['finalgrade'] ?? $data['suggestedgrade'],
    'feedbackcomment' => $data['feedbackcomment'] ?: (string) ($data['airesult']['feedback'] ?? ''),
]);

$renderer = $PAGE->get_renderer('mod_codereview');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reviewing', 'mod_codereview', $data['studentname']));
echo $renderer->render_review_page($data, $cm->id);

if ($data['gradestatus'] === submission_service::GRADE_GRADED) {
    echo $OUTPUT->notification(get_string('alreadygradednotice', 'mod_codereview'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->single_button(
        new moodle_url($url, ['reopen' => 1, 'sesskey' => sesskey()]),
        get_string('reopensubmission', 'mod_codereview'),
        'post'
    );
}

$form->display();

echo $OUTPUT->footer();
