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
 * Personal GitHub token page.
 *
 * Reachable only from the owner's own preferences page. The stored value is never
 * sent back to the browser: the form only ever reports whether one exists.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/mytoken_form.php');

use mod_codereview\local\github_token;

require_login();

$context = context_user::instance($USER->id);

if (!github_token::personal_tokens_allowed((int) $USER->id)) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('mytoken', 'mod_codereview'));
}

$url = new moodle_url('/mod/codereview/mytoken.php');

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('mytoken', 'mod_codereview'));
$PAGE->set_heading(fullname($USER));

$hastoken = github_token::has_personal_token((int) $USER->id);
$form = new mod_codereview_mytoken_form($url, ['hastoken' => $hastoken]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/user/preferences.php'));
}

if ($data = $form->get_data()) {
    require_sesskey();

    if (!empty($data->removetoken)) {
        github_token::set_personal_token((int) $USER->id, '');
        redirect($url, get_string('personaltokenremoved', 'mod_codereview'));
    }

    if (!empty($data->token)) {
        github_token::set_personal_token((int) $USER->id, (string) $data->token);
        redirect($url, get_string('personaltokensaved', 'mod_codereview'));
    }

    redirect($url);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mytoken', 'mod_codereview'));

echo $OUTPUT->notification(
    $hastoken
        ? get_string('personaltokenstored', 'mod_codereview')
        : get_string('personaltokennotset', 'mod_codereview'),
    \core\output\notification::NOTIFY_INFO
);

$form->display();

echo $OUTPUT->footer();
