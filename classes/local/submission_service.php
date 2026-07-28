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

use context_module;
use mod_codereview\event\repo_submitted;
use mod_codereview\exception\github_exception;
use mod_codereview\task\poll_check_runs;
use moodle_exception;
use stdClass;

/**
 * Creates and replaces repository submissions.
 *
 * Nothing supplied by the client is trusted: the repository and commit are always
 * confirmed against the GitHub API before a row is written.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_service {
    /** @var string No automated check result has been requested yet. */
    public const CI_PENDING = 'pending';

    /** @var string The API has been queried and at least one check is still running. */
    public const CI_CHECKING = 'checking';

    /** @var string Every check-run reached a conclusion. */
    public const CI_COMPLETED = 'completed';

    /** @var string The timeout elapsed without a single check-run appearing. */
    public const CI_NOCIDETECTED = 'nocidetected';

    /** @var string Polling stopped because of an error that retrying will not fix. */
    public const CI_ERROR = 'error';

    /** @var string The AI review has not been requested for this submission. */
    public const AI_SKIPPED = 'skipped';

    /** @var string The AI review is queued or running. */
    public const AI_PENDING = 'pending';

    /** @var string A usable suggestion was stored. */
    public const AI_COMPLETED = 'completed';

    /** @var string The provider failed or answered with something unusable. */
    public const AI_ERROR = 'error';

    /** @var string The teacher has not approved a grade yet. */
    public const GRADE_NOTGRADED = 'notgraded';

    /** @var string The teacher has approved a grade. */
    public const GRADE_GRADED = 'graded';

    /** @var github_client The API client used to confirm every submission. */
    protected github_client $client;

    /**
     * Constructor.
     *
     * @param github_client $client The client to confirm submissions with.
     */
    public function __construct(github_client $client) {
        $this->client = $client;
    }

    /**
     * Builds a service authenticated with whichever token the instance resolves to.
     *
     * @param stdClass $instance The codereview instance row.
     * @return self
     */
    public static function for_instance(stdClass $instance): self {
        return new self(github_client::instance(github_token::resolve($instance)));
    }

    /**
     * Records a submission, replacing the student's previous one when there is one.
     *
     * @param stdClass $instance The codereview instance row.
     * @param context_module $context The activity context, used for the event.
     * @param int $userid The submitting student.
     * @param string $repourl The repository URL as typed by the student.
     * @param string $commitsha The commit SHA as typed by the student.
     * @return stdClass The stored submission record.
     * @throws moodle_exception If the activity is closed, already graded, or the repository is unusable.
     */
    public function submit(
        stdClass $instance,
        context_module $context,
        int $userid,
        string $repourl,
        string $commitsha
    ): stdClass {
        global $DB;

        $now = time();

        if (!empty($instance->cutoffdate) && $now > (int) $instance->cutoffdate) {
            throw new moodle_exception('errorcutoffpassed', 'mod_codereview');
        }

        $existing = $DB->get_record('codereview_submissions', [
            'codereview' => $instance->id,
            'userid' => $userid,
        ]);

        if ($existing && $existing->gradestatus === self::GRADE_GRADED) {
            throw new moodle_exception('erroralreadygraded', 'mod_codereview');
        }

        $parsed = repo_url_parser::parse($repourl);
        $sha = repo_url_parser::parse_sha($commitsha);

        $repo = $this->fetch_repo($parsed['owner'], $parsed['name']);
        $commit = $this->fetch_commit($parsed['owner'], $parsed['name'], $sha);

        $record = $this->build_record($instance, $userid, $parsed, $sha, $repo, $commit, $now);

        if ($existing) {
            $record->id = $existing->id;
            $record->timecreated = (int) $existing->timecreated;
            $this->clear_derived_data((int) $existing->id);
            $DB->update_record('codereview_submissions', $record);
        } else {
            $record->id = $DB->insert_record('codereview_submissions', $record);
        }

        repo_submitted::create_from_submission($context, $record)->trigger();

        // A short initial delay gives GitHub Actions time to register the run: polling
        // the instant the student clicks submit would almost always see nothing yet.
        poll_check_runs::queue((int) $record->id, 30);

        return $record;
    }

    /**
     * Fetches repository metadata and rejects anything that is not publicly readable.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @return array The decoded repository resource.
     * @throws moodle_exception If the repository is missing or not public.
     */
    protected function fetch_repo(string $owner, string $name): array {
        try {
            $repo = $this->client->get_repo($owner, $name);
        } catch (github_exception $e) {
            // Without a token a private repository is indistinguishable from a missing
            // one, which is precisely the visibility test described in SCOPE.md 4.1.
            if ($e->is_not_found()) {
                throw new moodle_exception('errorrepositorynotfound', 'mod_codereview');
            }
            throw $e;
        }

        if (!empty($repo['private'])) {
            throw new moodle_exception('errornotpublic', 'mod_codereview');
        }

        return $repo;
    }

    /**
     * Fetches the submitted commit.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @param string $sha The full commit SHA.
     * @return array The decoded commit resource.
     * @throws moodle_exception If the commit does not belong to that repository.
     */
    protected function fetch_commit(string $owner, string $name, string $sha): array {
        try {
            return $this->client->get_commit($owner, $name, $sha);
        } catch (github_exception $e) {
            if ($e->is_not_found()) {
                throw new moodle_exception('errorcommitnotfound', 'mod_codereview');
            }
            throw $e;
        }
    }

    /**
     * Assembles the database record for a confirmed submission.
     *
     * @param stdClass $instance The codereview instance row.
     * @param int $userid The submitting student.
     * @param array $parsed The parsed owner and name.
     * @param string $sha The validated commit SHA.
     * @param array $repo The repository resource from the API.
     * @param array $commit The commit resource from the API.
     * @param int $now The submission timestamp.
     * @return stdClass
     */
    protected function build_record(
        stdClass $instance,
        int $userid,
        array $parsed,
        string $sha,
        array $repo,
        array $commit,
        int $now
    ): stdClass {
        $record = new stdClass();
        $record->codereview = (int) $instance->id;
        $record->userid = $userid;
        $record->repourl = repo_url_parser::canonical_url($parsed['owner'], $parsed['name']);
        $record->repoowner = $parsed['owner'];
        $record->reponame = $parsed['name'];
        $record->commitsha = $sha;

        // Declared by whoever made the commit and freely forgeable with git commit
        // --date. Stored for display only; lateness is decided by $now below.
        $record->commitauthordate = $this->to_timestamp($commit['commit']['author']['date'] ?? null);

        // Generated by GitHub's own servers, so these two are trustworthy.
        $record->repocreatedat = $this->to_timestamp($repo['created_at'] ?? null);
        $record->repopushedat = $this->to_timestamp($repo['pushed_at'] ?? null);

        $record->isfork = !empty($repo['fork']) ? 1 : 0;
        $record->forkparent = $repo['parent']['full_name'] ?? null;
        $record->authorlogin = $commit['author']['login'] ?? null;

        $record->cistatus = self::CI_PENDING;
        $record->aistatus = self::AI_SKIPPED;
        $record->gradestatus = self::GRADE_NOTGRADED;
        $record->islate = (!empty($instance->duedate) && $now > (int) $instance->duedate) ? 1 : 0;
        $record->truncated = 0;
        $record->errormessage = null;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return $record;
    }

    /**
     * Removes everything derived from a previous submission of the same row.
     *
     * A resubmission points at a different commit, so check results, AI suggestions
     * and authorship evidence computed for the old one are not merely stale, they
     * would be wrong. Flags raised on other submissions that referenced this one are
     * cleared for the same reason.
     *
     * @param int $submissionid The submission being replaced.
     * @return void
     */
    protected function clear_derived_data(int $submissionid): void {
        global $DB;

        $DB->delete_records('codereview_checkruns', ['submission' => $submissionid]);
        $DB->delete_records('codereview_airesults', ['submission' => $submissionid]);
        $DB->delete_records('codereview_blobs', ['submission' => $submissionid]);
        $DB->delete_records('codereview_commits', ['submission' => $submissionid]);
        $DB->delete_records('codereview_flags', ['submission' => $submissionid]);
        $DB->delete_records('codereview_flags', ['peersubmission' => $submissionid]);
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
