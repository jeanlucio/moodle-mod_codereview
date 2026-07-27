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

$cangrade = has_capability('mod/codereview:grade', $context);
$cansubmit = has_capability('mod/codereview:submit', $context);

if ($cangrade) {
    $groupid = groups_get_activity_group($cm, true);

    echo $OUTPUT->header();
    groups_print_activity_menu($cm, $PAGE->url);

    $table = new \mod_codereview\table\grading_overview_table($instance, $context, (int) $cm->id, (int) $groupid);
    $table->define_baseurl($PAGE->url);
    $table->out(30, true);

    echo $OUTPUT->footer();
    exit;
}

$submission = $DB->get_record('codereview_submissions', [
    'codereview' => $instance->id,
    'userid' => $USER->id,
]);

$notice = null;
$form = null;

if ($cansubmit) {
    $locked = $submission && $submission->gradestatus === submission_service::GRADE_GRADED;
    $closed = !empty($instance->cutoffdate) && time() > (int) $instance->cutoffdate;

    if (!$locked && !$closed) {
        $form = new mod_codereview_submit_form($PAGE->url, ['instance' => $instance]);

        if ($data = $form->get_data()) {
            try {
                $service = submission_service::for_instance($instance);
                $submission = $service->submit(
                    $instance,
                    $context,
                    (int) $USER->id,
                    (string) $data->repourl,
                    (string) $data->commitsha
                );
                redirect($PAGE->url, get_string('submitrepo', 'mod_codereview'), null, \core\output\notification::NOTIFY_SUCCESS);
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
}

echo $OUTPUT->header();

if ($notice !== null) {
    echo $OUTPUT->notification($notice, \core\output\notification::NOTIFY_WARNING);
}

if ($submission) {
    echo html_writer::tag('p', get_string('commitsha', 'mod_codereview') . ': ' . s($submission->commitsha));
    echo html_writer::tag('p', get_string('repourl', 'mod_codereview') . ': ' .
        html_writer::link($submission->repourl, s($submission->repourl), ['rel' => 'noopener']));
    if ($submission->islate) {
        echo $OUTPUT->notification(get_string('duedate', 'mod_codereview'), \core\output\notification::NOTIFY_WARNING);
    }
}

if ($form !== null) {
    echo $OUTPUT->notification(get_string('publicrepowarning', 'mod_codereview'), \core\output\notification::NOTIFY_INFO);
    if (!empty($instance->integritychecks)) {
        echo $OUTPUT->notification(get_string('authorshipnotice', 'mod_codereview'), \core\output\notification::NOTIFY_INFO);
    }
    $form->display();
}

echo $OUTPUT->footer();
