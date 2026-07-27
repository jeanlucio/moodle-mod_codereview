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
 * Lists all CodeReview activities in a course.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);

$context = context_course::instance($course->id);

$PAGE->set_url('/mod/codereview/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_codereview'));

$instances = get_all_instances_in_course('codereview', $course);

if (!$instances) {
    echo $OUTPUT->notification(get_string('noinstances', 'moodle'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [get_string('name'), get_string('sectionname', 'format_' . $course->format)];

foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/codereview/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name)
    );
    $table->data[] = [$link, get_section_name($course, $instance->section)];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
