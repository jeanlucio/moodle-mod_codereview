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
 * Core callbacks for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declares which core features the activity supports.
 *
 * FEATURE_COMPLETION_HAS_RULES is deliberately absent until the custom completion
 * class, the get_coursemodule_info hook and the update_state call all exist, since
 * declaring it alone produces a rule that is silently never evaluated.
 *
 * @param string $feature The feature constant being queried.
 * @return mixed True or false for boolean features, a string for purpose, null when unknown.
 */
function codereview_supports(string $feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Creates a new activity instance.
 *
 * @param stdClass $data The form data.
 * @param mod_codereview_mod_form|null $mform The form itself, unused.
 * @return int The id of the new instance.
 */
function codereview_add_instance(stdClass $data, ?mod_codereview_mod_form $mform = null): int {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    codereview_prepare_instance_data($data);

    return $DB->insert_record('codereview', $data);
}

/**
 * Updates an existing activity instance.
 *
 * @param stdClass $data The form data.
 * @param mod_codereview_mod_form|null $mform The form itself, unused.
 * @return bool
 */
function codereview_update_instance(stdClass $data, ?mod_codereview_mod_form $mform = null): bool {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    codereview_prepare_instance_data($data);

    return $DB->update_record('codereview', $data);
}

/**
 * Normalises form data before it reaches the database.
 *
 * @param stdClass $data The form data, modified in place.
 * @return void
 */
function codereview_prepare_instance_data(stdClass $data): void {
    global $USER;

    // The form guarantees the two weights add up to 100; clamping here only guards
    // against a caller that bypassed it, such as the test generator.
    $data->weighttests = max(0, min(100, (int) ($data->weighttests ?? 50)));
    $data->weightai = max(0, min(100, (int) ($data->weightai ?? (100 - $data->weighttests))));
    $data->citimeout = max(1, (int) ($data->citimeout ?? 30));
    $data->integritychecks = empty($data->integritychecks) ? 0 : 1;

    // The instance stores only a pointer to whoever owns the token, never the token
    // itself, so that no secret ever travels through a course-scoped form.
    if (!empty($data->tokenusemine)) {
        $data->tokenuserid = (int) $USER->id;
    } else if (!isset($data->tokenuserid)) {
        $data->tokenuserid = 0;
    }

    unset($data->tokenusemine);
}

/**
 * Deletes an activity instance and everything derived from it.
 *
 * @param int $id The instance id.
 * @return bool
 */
function codereview_delete_instance(int $id): bool {
    global $DB;

    if (!$DB->record_exists('codereview', ['id' => $id])) {
        return false;
    }

    $submissionids = $DB->get_fieldset_select('codereview_submissions', 'id', 'codereview = ?', [$id]);

    if ($submissionids) {
        [$insql, $params] = $DB->get_in_or_equal($submissionids);
        $DB->delete_records_select('codereview_checkruns', "submission $insql", $params);
        $DB->delete_records_select('codereview_airesults', "submission $insql", $params);
        $DB->delete_records_select('codereview_grades', "submission $insql", $params);
        $DB->delete_records_select('codereview_flags', "submission $insql", $params);
    }

    $DB->delete_records('codereview_blobs', ['codereview' => $id]);
    $DB->delete_records('codereview_submissions', ['codereview' => $id]);
    $DB->delete_records('codereview', ['id' => $id]);

    return true;
}

/**
 * Adds a "My GitHub token" entry to the user's own preferences page.
 *
 * Core discovers this callback exclusively through lib.php, which is why it lives
 * here rather than in an autoloaded class.
 *
 * @param navigation_node $usersetting The user settings navigation node.
 * @param stdClass $user The user whose preferences are being shown.
 * @param context_user $context The user context.
 * @param stdClass $course The course, when reached from within one.
 * @param context_course $coursecontext The course context.
 * @return void
 */
function codereview_extend_navigation_user_settings(
    navigation_node $usersetting,
    stdClass $user,
    context_user $context,
    stdClass $course,
    context_course $coursecontext
): void {
    global $USER;

    if (!\mod_codereview\local\github_token::personal_tokens_allowed((int) $user->id)) {
        return;
    }

    // Only ever expose this on the owner's own preferences page: the token is a
    // personal credential and must not be reachable through anyone else's profile.
    if ((int) $user->id !== (int) $USER->id) {
        return;
    }

    $usersetting->add(
        get_string('mytoken', 'mod_codereview'),
        new moodle_url('/mod/codereview/mytoken.php'),
        navigation_node::TYPE_SETTING
    );
}
