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

    $data->id = $DB->insert_record('codereview', $data);
    codereview_grade_item_update($data);

    return $data->id;
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

    $updated = $DB->update_record('codereview', $data);
    codereview_grade_item_update($data);

    return $updated;
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

    $instance = $DB->get_record('codereview', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    codereview_grade_item_update($instance, 'reset');
    grade_update('mod/codereview', $instance->course, 'mod', 'codereview', $id, 0, null, ['deleted' => 1]);

    $submissionids = $DB->get_fieldset_select('codereview_submissions', 'id', 'codereview = ?', [$id]);

    if ($submissionids) {
        [$insql, $params] = $DB->get_in_or_equal($submissionids);
        $DB->delete_records_select('codereview_checkruns', "submission $insql", $params);
        $DB->delete_records_select('codereview_airesults', "submission $insql", $params);
        $DB->delete_records_select('codereview_grades', "submission $insql", $params);
        $DB->delete_records_select('codereview_flags', "submission $insql", $params);
    }

    $DB->delete_records('codereview_commits', ['codereview' => $id]);
    $DB->delete_records('codereview_blobs', ['codereview' => $id]);
    $DB->delete_records('codereview_submissions', ['codereview' => $id]);
    $DB->delete_records('codereview', ['id' => $id]);

    return true;
}

/**
 * Creates or updates the grade item for an instance.
 *
 * @param stdClass $codereview The instance record.
 * @param mixed $grades Grades to push, 'reset' to clear, or null for none.
 * @return int A GRADE_UPDATE_* status.
 */
function codereview_grade_item_update(stdClass $codereview, $grades = null): int {
    global $CFG;

    require_once($CFG->libdir . '/gradelib.php');

    $item = [
        'itemname' => clean_param($codereview->name, PARAM_NOTAGS),
        'gradetype' => GRADE_TYPE_VALUE,
    ];

    if ($codereview->grade > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax'] = $codereview->grade;
        $item['grademin'] = 0;
    } else if ($codereview->grade < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid'] = -$codereview->grade;
    } else {
        $item['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/codereview', $codereview->course, 'mod', 'codereview', $codereview->id, 0, $grades, $item);
}

/**
 * Returns the approved grades of an instance in gradebook format.
 *
 * Only approved decisions are returned. The raw suggestion never reaches the
 * gradebook: a grade exists once a teacher has said so, and not before.
 *
 * @param stdClass $codereview The instance record.
 * @param int $userid A single user to fetch, or 0 for everyone.
 * @return array Grades keyed by user id.
 */
function codereview_get_user_grades(stdClass $codereview, int $userid = 0): array {
    global $DB;

    $params = ['codereview' => $codereview->id];
    $where = '';

    if ($userid) {
        $where = ' AND s.userid = :userid';
        $params['userid'] = $userid;
    }

    $sql = "SELECT s.userid AS userid, g.finalgrade AS rawgrade, g.timemodified AS dategraded,
                   g.graderid AS usermodified, g.timecreated AS datesubmitted
              FROM {codereview_submissions} s
              JOIN {codereview_grades} g ON g.submission = s.id
             WHERE s.codereview = :codereview AND g.finalgrade IS NOT NULL" . $where;

    $grades = [];
    foreach ($DB->get_records_sql($sql, $params) as $record) {
        $record->id = $record->userid;
        $grades[$record->userid] = $record;
    }

    return $grades;
}

/**
 * Pushes approved grades into the gradebook.
 *
 * @param stdClass $codereview The instance record.
 * @param int $userid A single user to update, or 0 for everyone.
 * @param bool $nullifnone Whether to write a null grade when none exists.
 * @return void
 */
function codereview_update_grades(stdClass $codereview, int $userid = 0, bool $nullifnone = true): void {
    $grades = codereview_get_user_grades($codereview, $userid);

    if ($grades) {
        codereview_grade_item_update($codereview, $grades);

        return;
    }

    if ($userid && $nullifnone) {
        codereview_grade_item_update($codereview, (object) ['userid' => $userid, 'rawgrade' => null]);

        return;
    }

    codereview_grade_item_update($codereview);
}

/**
 * Reports whether a scale is in use by any instance.
 *
 * @param int $scaleid The scale to look for.
 * @return bool
 */
function codereview_scale_used_anywhere(int $scaleid): bool {
    global $DB;

    return $scaleid && $DB->record_exists('codereview', ['grade' => -$scaleid]);
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
