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

use mod_codereview\exception\github_exception;
use stdClass;

/**
 * Reads GitHub Actions check-runs for a submitted commit and records them.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checkrun_poller {
    /** @var string Only check-runs owned by this app count towards the grade. */
    public const APPSLUG_ACTIONS = 'github-actions';

    /** @var string[] Conclusions that neither pass nor fail, so they leave the ratio alone. */
    public const NEUTRAL_CONCLUSIONS = ['neutral', 'skipped'];

    /** @var github_client The API client used to read the check-runs. */
    protected github_client $client;

    /**
     * Constructor.
     *
     * @param github_client $client The client to read check-runs with.
     */
    public function __construct(github_client $client) {
        $this->client = $client;
    }

    /**
     * Builds a poller authenticated with whichever token the instance resolves to.
     *
     * @param stdClass $instance The codereview instance row.
     * @return self
     */
    public static function for_instance(stdClass $instance): self {
        return new self(github_client::instance(github_token::resolve($instance)));
    }

    /**
     * Polls once and updates the submission's automated check status.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @return string The resulting cistatus.
     */
    public function poll(stdClass $instance, stdClass $submission): string {
        global $DB;

        $timedout = $this->has_timed_out($instance, $submission);

        try {
            $response = $this->client->get_check_runs(
                $submission->repoowner,
                $submission->reponame,
                $submission->commitsha
            );
        } catch (github_exception $e) {
            return $this->handle_failure($submission, $e, $timedout);
        }

        $runs = $response['check_runs'] ?? [];
        $this->store_runs((int) $submission->id, $runs);

        $status = $this->resolve_status($runs, $timedout);
        $this->update_status($submission, $status, null);

        return $status;
    }

    /**
     * Decides what a failed request means for the submission.
     *
     * A rate limit or a transient server error says nothing about the commit, so the
     * submission keeps waiting and only records why the attempt did not land. Anything
     * else is treated as terminal, because retrying will not change the answer.
     *
     * @param stdClass $submission The submission row.
     * @param github_exception $e The failure.
     * @param bool $timedout Whether the polling window has already elapsed.
     * @return string The resulting cistatus.
     */
    protected function handle_failure(stdClass $submission, github_exception $e, bool $timedout): string {
        $transient = in_array($e->get_http_status(), [403, 429, 500, 502, 503, 504], true);

        if ($transient && !$timedout) {
            $this->update_status($submission, $submission->cistatus, $e->getMessage());

            return $submission->cistatus;
        }

        $status = $transient ? submission_service::CI_NOCIDETECTED : submission_service::CI_ERROR;
        $this->update_status($submission, $status, $e->getMessage());

        return $status;
    }

    /**
     * Writes the check-runs, keying on the GitHub id so repeated polls do not pile up.
     *
     * @param int $submissionid The submission the runs belong to.
     * @param array $runs The check_runs array from the API response.
     * @return void
     */
    protected function store_runs(int $submissionid, array $runs): void {
        global $DB;

        $existing = $DB->get_records('codereview_checkruns', ['submission' => $submissionid], '', 'externalid, id');

        foreach ($runs as $run) {
            $record = new stdClass();
            $record->submission = $submissionid;
            $record->externalid = (int) ($run['id'] ?? 0);
            $record->checkname = (string) ($run['name'] ?? '');
            $record->appslug = $run['app']['slug'] ?? null;
            $record->conclusion = $run['conclusion'] ?? null;
            $record->counted = $record->appslug === self::APPSLUG_ACTIONS ? 1 : 0;
            $record->detailsurl = $run['html_url'] ?? ($run['details_url'] ?? null);
            $record->startedat = $this->to_timestamp($run['started_at'] ?? null);
            $record->completedat = $this->to_timestamp($run['completed_at'] ?? null);

            if (isset($existing[$record->externalid])) {
                $record->id = $existing[$record->externalid]->id;
                $DB->update_record('codereview_checkruns', $record);
            } else {
                $record->timecreated = time();
                $DB->insert_record('codereview_checkruns', $record);
            }
        }
    }

    /**
     * Works out the submission status from the check-runs seen so far.
     *
     * @param array $runs The check_runs array from the API response.
     * @param bool $timedout Whether the polling window has already elapsed.
     * @return string
     */
    protected function resolve_status(array $runs, bool $timedout): string {
        if ($runs === []) {
            // Nothing at all: either GitHub Actions has not started yet, or the
            // repository simply has no workflow. Only the timeout tells them apart.
            return $timedout ? submission_service::CI_NOCIDETECTED : submission_service::CI_CHECKING;
        }

        foreach ($runs as $run) {
            if (($run['status'] ?? '') !== 'completed') {
                // Something is still running. Once the window closes we grade on what
                // did finish rather than discarding it as "no CI".
                return $timedout ? submission_service::CI_COMPLETED : submission_service::CI_CHECKING;
            }
        }

        return submission_service::CI_COMPLETED;
    }

    /**
     * Returns true when the configured polling window has elapsed.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @return bool
     */
    public function has_timed_out(stdClass $instance, stdClass $submission): bool {
        $window = max(1, (int) $instance->citimeout) * MINSECS;

        return time() > ((int) $submission->timecreated + $window);
    }

    /**
     * Persists the status and the reason the last attempt failed, if any.
     *
     * @param stdClass $submission The submission row, updated in place.
     * @param string $status The cistatus to store.
     * @param string|null $errormessage The failure detail, or null to clear it.
     * @return void
     */
    protected function update_status(stdClass $submission, string $status, ?string $errormessage): void {
        global $DB;

        $submission->cistatus = $status;
        $submission->errormessage = $errormessage;
        $submission->timemodified = time();

        $DB->update_record('codereview_submissions', $submission);
    }

    /**
     * Converts an ISO 8601 timestamp from the API into a Unix timestamp.
     *
     * @param string|null $value The value from the API response.
     * @return int Zero when absent or unparseable.
     */
    protected function to_timestamp(?string $value): int {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) max(0, strtotime($value));
    }
}
