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
 * Activity view page.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/submit_form.php');

use mod_codereview\local\submission_service;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'codereview');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/codereview:view', $context);

$instance = $DB->get_record('codereview', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url('/mod/codereview/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('path-mod-codereview');

\mod_codereview\event\course_module_viewed::create([
    'context' => $context,
    'objectid' => $instance->id,
])->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$renderer = $PAGE->get_renderer('mod_codereview');

if (has_capability('mod/codereview:grade', $context)) {
    $groupid = groups_get_activity_group($cm, true);

    echo $OUTPUT->header();
    groups_print_activity_menu($cm, $PAGE->url);

    $table = new \mod_codereview\table\grading_overview_table($instance, $context, (int) $cm->id, (int) $groupid);
    $table->define_baseurl($PAGE->url);
    $table->out(30, true);

    // Students stranded outside a group would otherwise be invisible here: they
    // cannot submit, so they never produce a row for the teacher to miss.
    if (!empty($instance->teamsubmission)) {
        $stranded = \mod_codereview\local\group_resolver::count_ungrouped($instance, $context, (int) $groupid);
        if ($stranded > 0) {
            echo $OUTPUT->notification(
                get_string('membersungrouped', 'mod_codereview', $stranded),
                \core\output\notification::NOTIFY_WARNING
            );
        }
    }

    echo $OUTPUT->footer();
    exit;
}

require_capability('mod/codereview:submit', $context);

// Resolved through the group when this is a team submission, so every member sees
// the one repository the team submitted rather than an empty form of their own.
$submission = submission_service::find_for_user($instance, (int) $USER->id);

$locked = $submission && $submission->gradestatus === submission_service::GRADE_GRADED;
$closed = !empty($instance->cutoffdate) && time() > (int) $instance->cutoffdate;
$notice = null;
$form = null;

if (!$locked && !$closed) {
    $form = new mod_codereview_submit_form($PAGE->url, ['instance' => $instance]);

    if ($data = $form->get_data()) {
        // Reached only without JavaScript: the AMD module posts through the web
        // service instead. Both paths go through the same service, so the rules hold
        // either way and the activity still works with scripting switched off.
        try {
            $submission = submission_service::for_instance($instance)->submit(
                $instance,
                $context,
                (int) $USER->id,
                (string) $data->repourl,
                (string) $data->commitsha
            );
            redirect($PAGE->url, get_string('submissionreceived', 'mod_codereview'));
        } catch (moodle_exception $e) {
            $notice = $e->getMessage();
        }
    } else if ($submission) {
        $form->set_data([
            'repourl' => $submission->repourl,
            'commitsha' => $submission->commitsha,
        ]);
    }
} else if ($locked) {
    $notice = get_string('erroralreadygraded', 'mod_codereview');
} else {
    $notice = get_string('errorcutoffpassed', 'mod_codereview');
}

$PAGE->requires->js_call_amd('mod_codereview/status_poll', 'init');
$PAGE->requires->js_call_amd('mod_codereview/submit_form', 'init');

echo $OUTPUT->header();

if ($notice !== null) {
    echo $OUTPUT->notification($notice, \core\output\notification::NOTIFY_WARNING);
}

$checkruns = [];
if ($submission) {
    $runs = $DB->get_records('codereview_checkruns', ['submission' => $submission->id], 'checkname ASC');
    $checkruns = array_values(array_map(static fn($run) => [
        'name' => (string) $run->checkname,
        'conclusion' => (string) $run->conclusion,
        'passed' => $run->conclusion === 'success',
        'detailsurl' => (string) $run->detailsurl,
    ], $runs));
}

echo html_writer::start_div('cr-student', ['data-region' => 'codereview-student']);
echo $renderer->render_student_status(
    new \mod_codereview\output\student_status($submission ?: null, (int) $cm->id, $checkruns)
);

if ($form !== null) {
    echo $renderer->render_submit_form($form, $instance, (int) $cm->id, (bool) $submission);
}

echo html_writer::end_div();
echo $OUTPUT->footer();
