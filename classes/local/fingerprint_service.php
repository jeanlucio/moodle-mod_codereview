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
 * Records the content and history fingerprints a submission can be compared by.
 *
 * Git is content addressed, so the hashes GitHub already computed are enough to
 * recognise identical work: a blob SHA survives a rewritten history, a new commit
 * on top, and a renamed file, because it hashes the bytes rather than the path.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fingerprint_service {
    /** @var int Marks a blob row as part of the instance template baseline. */
    public const BASELINE_SUBMISSION = 0;

    /** @var float Share of submissions a blob must appear in to look like boilerplate. */
    public const BOILERPLATE_RATIO = 0.7;

    /** @var int How many ancestors to remember for each submission. */
    public const HISTORY_DEPTH = 100;

    /** @var github_client The API client used to read the repositories. */
    protected github_client $client;

    /**
     * Constructor.
     *
     * @param github_client $client The client to read repositories with.
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
        return new self(new github_client(github_token::resolve($instance)));
    }

    /**
     * Records the file hashes and the commit ancestry of a submission.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @return void
     * @throws github_exception If the repository cannot be read.
     */
    public function record(stdClass $instance, stdClass $submission): void {
        global $DB;

        $DB->delete_records('codereview_blobs', ['submission' => $submission->id]);
        $DB->delete_records('codereview_commits', ['submission' => $submission->id]);

        $tree = $this->client->get_tree($submission->repoowner, $submission->reponame, $submission->commitsha);
        $this->store_blobs((int) $instance->id, (int) $submission->id, $tree['tree'] ?? []);

        $commits = $this->client->get_commits(
            $submission->repoowner,
            $submission->reponame,
            $submission->commitsha,
            self::HISTORY_DEPTH
        );
        $this->store_commits((int) $instance->id, (int) $submission->id, $commits);
    }

    /**
     * Fetches the template repository once and stores it as the comparison baseline.
     *
     * Without this every student would look identical to every other, because they
     * all start from the same files. Subtracting the template is what turns a shared
     * hash into evidence instead of noise.
     *
     * @param stdClass $instance The codereview instance row.
     * @return void
     */
    public function ensure_baseline(stdClass $instance): void {
        global $DB;

        if (trim((string) $instance->templaterepourl) === '') {
            return;
        }

        $exists = $DB->record_exists('codereview_blobs', [
            'codereview' => $instance->id,
            'submission' => self::BASELINE_SUBMISSION,
        ]);
        if ($exists) {
            return;
        }

        try {
            $parsed = repo_url_parser::parse((string) $instance->templaterepourl);
            $repo = $this->client->get_repo($parsed['owner'], $parsed['name']);
            $branch = (string) ($repo['default_branch'] ?? 'main');
            $tree = $this->client->get_tree($parsed['owner'], $parsed['name'], $branch);
        } catch (\Throwable $e) {
            // A template that cannot be read must not stop the submission being
            // fingerprinted; the fallback below still filters out shared boilerplate.
            return;
        }

        $this->store_blobs((int) $instance->id, self::BASELINE_SUBMISSION, $tree['tree'] ?? []);
    }

    /**
     * Returns the hashes that must not count as evidence of copying.
     *
     * Prefers the configured template. Without one, anything present in most
     * submissions is treated as boilerplate, which self-calibrates but is noisier in
     * a small cohort.
     *
     * @param stdClass $instance The codereview instance row.
     * @return string[] Blob SHAs to ignore.
     */
    public static function excluded_shas(stdClass $instance): array {
        global $DB;

        $baseline = $DB->get_fieldset_select(
            'codereview_blobs',
            'DISTINCT blobsha',
            'codereview = ? AND submission = ?',
            [$instance->id, self::BASELINE_SUBMISSION]
        );

        if ($baseline) {
            return $baseline;
        }

        $total = $DB->count_records_sql(
            'SELECT COUNT(DISTINCT submission) FROM {codereview_blobs} WHERE codereview = ? AND submission > 0',
            [$instance->id]
        );

        if ($total < 3) {
            // Too few submissions for a frequency threshold to mean anything.
            return [];
        }

        $threshold = (int) ceil($total * self::BOILERPLATE_RATIO);

        return $DB->get_fieldset_sql(
            'SELECT blobsha
               FROM {codereview_blobs}
              WHERE codereview = ? AND submission > 0
           GROUP BY blobsha
             HAVING COUNT(DISTINCT submission) >= ?',
            [$instance->id, $threshold]
        );
    }

    /**
     * Stores the blob hashes of a tree.
     *
     * @param int $instanceid The instance the rows belong to.
     * @param int $submissionid The submission, or the baseline marker.
     * @param array $tree The tree entries from the API.
     * @return void
     */
    protected function store_blobs(int $instanceid, int $submissionid, array $tree): void {
        global $DB;

        $now = time();
        $records = [];

        foreach ($tree as $entry) {
            if (($entry['type'] ?? '') !== 'blob' || empty($entry['sha'])) {
                continue;
            }

            $records[] = (object) [
                'codereview' => $instanceid,
                'submission' => $submissionid,
                'path' => (string) substr((string) ($entry['path'] ?? ''), 0, 255),
                'blobsha' => (string) $entry['sha'],
                'filesize' => (int) ($entry['size'] ?? 0),
                'timecreated' => $now,
            ];
        }

        if ($records) {
            $DB->insert_records('codereview_blobs', $records);
        }
    }

    /**
     * Stores the ancestry of a submitted commit.
     *
     * @param int $instanceid The instance the rows belong to.
     * @param int $submissionid The submission.
     * @param array $commits The commit list from the API.
     * @return void
     */
    protected function store_commits(int $instanceid, int $submissionid, array $commits): void {
        global $DB;

        $now = time();
        $records = [];
        $position = 0;

        foreach ($commits as $commit) {
            if (empty($commit['sha'])) {
                continue;
            }

            $records[] = (object) [
                'submission' => $submissionid,
                'codereview' => $instanceid,
                'sha' => (string) $commit['sha'],
                'position' => $position++,
                'timecreated' => $now,
            ];
        }

        if ($records) {
            $DB->insert_records('codereview_commits', $records);
        }
    }
}
