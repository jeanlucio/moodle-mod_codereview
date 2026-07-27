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

namespace mod_codereview\local;

use core_user;
use moodle_url;
use stdClass;

/**
 * Assembles everything the teacher review screen needs.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_service {
    /**
     * Loads a submission, confirming it belongs to the instance being reviewed.
     *
     * The submission id arrives from a URL or a web service call, so it is never
     * trusted on its own: binding the lookup to the already-validated instance is
     * what stops a crafted id from reaching another activity's data.
     *
     * @param stdClass $instance The codereview instance row.
     * @param int $submissionid The submission being opened.
     * @return stdClass
     */
    public static function get_submission(stdClass $instance, int $submissionid): stdClass {
        global $DB;

        return $DB->get_record('codereview_submissions', [
            'id' => $submissionid,
            'codereview' => $instance->id,
        ], '*', MUST_EXIST);
    }

    /**
     * Builds the full review payload for one submission.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @return array
     */
    public static function get_review_data(stdClass $instance, stdClass $submission): array {
        global $DB;

        $student = core_user::get_user((int) $submission->userid);
        $grade = $DB->get_record('codereview_grades', ['submission' => $submission->id]);

        return [
            'submissionid' => (int) $submission->id,
            'studentname' => $student ? fullname($student) : '',
            'repourl' => (string) $submission->repourl,
            'repoowner' => (string) $submission->repoowner,
            'commitsha' => (string) $submission->commitsha,
            'authorlogin' => (string) $submission->authorlogin,
            'commitauthordate' => (int) $submission->commitauthordate,
            'timecreated' => (int) $submission->timecreated,
            'islate' => (bool) $submission->islate,
            'truncated' => (bool) $submission->truncated,
            'cistatus' => (string) $submission->cistatus,
            'aistatus' => (string) $submission->aistatus,
            'gradestatus' => (string) $submission->gradestatus,
            'errormessage' => (string) $submission->errormessage,
            'grademax' => (int) $instance->grade,
            'suggestedgrade' => grade_calculator::calculate($instance, $submission),
            'checkruns' => self::checkruns($submission),
            'airesult' => self::airesult($submission),
            'flags' => self::flags($instance, $submission),
            'finalgrade' => $grade && $grade->finalgrade !== null ? (float) $grade->finalgrade : null,
            'feedbackcomment' => $grade ? (string) $grade->feedbackcomment : '',
        ];
    }

    /**
     * Returns the recorded check-runs.
     *
     * @param stdClass $submission The submission row.
     * @return array
     */
    protected static function checkruns(stdClass $submission): array {
        global $DB;

        $runs = $DB->get_records('codereview_checkruns', ['submission' => $submission->id], 'checkname ASC');

        return array_values(array_map(static fn($run) => [
            'name' => (string) $run->checkname,
            'conclusion' => (string) $run->conclusion,
            'appslug' => (string) $run->appslug,
            'counted' => (bool) $run->counted,
            'passed' => $run->conclusion === 'success',
            'detailsurl' => (string) $run->detailsurl,
        ], $runs));
    }

    /**
     * Returns the most recent AI suggestion, successful or not.
     *
     * @param stdClass $submission The submission row.
     * @return array|null
     */
    protected static function airesult(stdClass $submission): ?array {
        global $DB;

        $records = $DB->get_records(
            'codereview_airesults',
            ['submission' => $submission->id],
            'timecreated DESC',
            '*',
            0,
            1
        );

        if (!$records) {
            return null;
        }

        $result = reset($records);

        return [
            'status' => (string) $result->status,
            'provider' => (string) $result->provider,
            'model' => (string) $result->model,
            'suggestedgrade' => $result->suggestedgrade !== null ? (float) $result->suggestedgrade : null,
            'feedback' => (string) $result->feedback,
            'errormessage' => (string) $result->errormessage,
        ];
    }

    /**
     * Returns the authorship signals, each with the evidence behind it.
     *
     * The peer is identified by name because the reader holds the grading
     * capability. Nothing here is exposed to students.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @return array
     */
    protected static function flags(stdClass $instance, stdClass $submission): array {
        global $DB;

        $records = $DB->get_records('codereview_flags', ['submission' => $submission->id], 'severity ASC, flagtype ASC');
        if (!$records) {
            return [];
        }

        $peerids = array_filter(array_map(static fn($f) => (int) $f->peersubmission, $records));
        $peers = [];

        if ($peerids) {
            [$insql, $params] = $DB->get_in_or_equal(array_unique($peerids), SQL_PARAMS_NAMED);
            $sql = "SELECT s.id, s.userid, s.repourl, s.repocreatedat
                      FROM {codereview_submissions} s
                     WHERE s.id $insql";
            $peers = $DB->get_records_sql($sql, $params);
        }

        $out = [];
        foreach ($records as $flag) {
            $detail = json_decode((string) $flag->detail, true) ?: [];
            $peer = $peers[$flag->peersubmission] ?? null;
            $peeruser = $peer ? core_user::get_user((int) $peer->userid) : null;

            $out[] = [
                'flagtype' => (string) $flag->flagtype,
                'severity' => (string) $flag->severity,
                'description' => self::describe($flag, $detail),
                'haspeer' => $peer !== null,
                'peername' => $peeruser ? fullname($peeruser) : '',
                'peerrepourl' => $peer ? (string) $peer->repourl : '',
                'peerreviewurl' => $peer
                    ? (new moodle_url('/mod/codereview/review.php', ['id' => $flag->peersubmission]))->out(false)
                    : '',
                'publishedfirst' => $peer ? self::published_first($submission, $peer) : '',
            ];
        }

        return $out;
    }

    /**
     * Turns a flag and its evidence into a sentence a teacher can act on.
     *
     * The wording states what was observed, never a verdict: the reader decides what
     * it means, and false positives are expected.
     *
     * @param stdClass $flag The flag record.
     * @param array $detail The decoded evidence.
     * @return string
     */
    protected static function describe(stdClass $flag, array $detail): string {
        switch ($flag->flagtype) {
            case integrity_checker::FLAG_CONTENTOVERLAP:
                return get_string('flagcontentoverlap', 'mod_codereview', (object) [
                    'shared' => (int) ($detail['shared'] ?? 0),
                    'total' => (int) ($detail['total'] ?? 0),
                ]);
            case integrity_checker::FLAG_SHAREDHISTORY:
                return get_string('flagsharedhistory', 'mod_codereview', (object) [
                    'shared' => (int) ($detail['shared'] ?? 0),
                    'total' => (int) ($detail['total'] ?? 0),
                ]);
            case integrity_checker::FLAG_FOREIGNAUTHOR:
                return get_string('flagforeignauthor', 'mod_codereview', $detail['authorlogin'] ?? '');
            case integrity_checker::FLAG_FORKOFPEER:
                return get_string('flagforkofpeer', 'mod_codereview');
            case integrity_checker::FLAG_IDENTICALCOMMIT:
                return get_string('flagidenticalcommit', 'mod_codereview');
            case integrity_checker::FLAG_DUPLICATEREPO:
                return get_string('flagduplicaterepo', 'mod_codereview');
            case integrity_checker::FLAG_IMPORTEDHISTORY:
                return get_string('flagimportedhistory', 'mod_codereview');
            default:
                return '';
        }
    }

    /**
     * Says which of two repositories existed first.
     *
     * Matches are symmetric, so the panel would otherwise leave the teacher unable to
     * tell which side is the original. The creation timestamp comes from GitHub's
     * servers and cannot be forged.
     *
     * @param stdClass $submission The submission under review.
     * @param stdClass $peer The other submission.
     * @return string A language string, or empty when it cannot be told.
     */
    protected static function published_first(stdClass $submission, stdClass $peer): string {
        $own = (int) $submission->repocreatedat;
        $other = (int) $peer->repocreatedat;

        if ($own <= 0 || $other <= 0 || $own === $other) {
            return '';
        }

        return $own < $other
            ? get_string('publishedfirstthis', 'mod_codereview')
            : get_string('publishedfirstpeer', 'mod_codereview');
    }
}
