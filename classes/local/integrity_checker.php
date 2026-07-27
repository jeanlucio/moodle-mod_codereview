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

use stdClass;

/**
 * Raises authorship signals by comparing a submission against its cohort.
 *
 * Every signal produced here is evidence for a teacher to read, never a verdict.
 * Nothing in this class changes a grade, blocks a submission, or notifies the
 * student: false positives are expected, and the cost of acting on one
 * automatically would fall on someone who did nothing wrong.
 *
 * The comparison detects exact duplication. Renaming variables or reordering
 * functions defeats it, so an absence of flags is never proof of originality.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class integrity_checker {
    /** @var string The repository is a fork of a classmate's repository. */
    public const FLAG_FORKOFPEER = 'forkofpeer';

    /** @var string The exact commit was already submitted by someone else. */
    public const FLAG_IDENTICALCOMMIT = 'identicalcommit';

    /** @var string Part of the commit history is shared with another submission. */
    public const FLAG_SHAREDHISTORY = 'sharedhistory';

    /** @var string Files are byte-identical to another submission's. */
    public const FLAG_CONTENTOVERLAP = 'contentoverlap';

    /** @var string The commit was authored by a different GitHub account. */
    public const FLAG_FOREIGNAUTHOR = 'foreignauthor';

    /** @var string The history predates the repository that holds it. */
    public const FLAG_IMPORTEDHISTORY = 'importedhistory';

    /** @var string Two submissions point at the same repository. */
    public const FLAG_DUPLICATEREPO = 'duplicaterepo';

    /** @var float Share of a submission's own files that must match to be worth reporting. */
    public const OVERLAP_THRESHOLD = 0.4;

    /** @var int Fewest shared files worth reporting, whatever the ratio. */
    public const OVERLAP_MINIMUM = 2;

    /**
     * Recomputes every signal for a submission.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @return int How many flags were raised.
     */
    public function check(stdClass $instance, stdClass $submission): int {
        global $DB;

        $DB->delete_records('codereview_flags', ['submission' => $submission->id]);

        if (empty($instance->integritychecks)) {
            return 0;
        }

        $peers = $DB->get_records_select(
            'codereview_submissions',
            'codereview = ? AND id <> ?',
            [$instance->id, $submission->id]
        );

        $flags = array_merge(
            $this->check_duplicate_repo($submission, $peers),
            $this->check_fork_of_peer($submission, $peers),
            $this->check_identical_commit($submission, $peers),
            $this->check_shared_history($instance, $submission, $peers),
            $this->check_foreign_author($submission, $peers),
            $this->check_imported_history($submission),
            $this->check_content_overlap($instance, $submission)
        );

        foreach ($flags as $flag) {
            $flag->submission = $submission->id;
            $flag->timecreated = time();
            $DB->insert_record('codereview_flags', $flag);
        }

        return count($flags);
    }

    /**
     * Two submissions naming the same repository.
     *
     * @param stdClass $submission The submission row.
     * @param stdClass[] $peers The other submissions in the instance.
     * @return stdClass[]
     */
    protected function check_duplicate_repo(stdClass $submission, array $peers): array {
        $flags = [];

        foreach ($peers as $peer) {
            if (strcasecmp((string) $peer->repourl, (string) $submission->repourl) === 0) {
                $flags[] = $this->flag(self::FLAG_DUPLICATEREPO, 'high', (int) $peer->id, [
                    'repourl' => $submission->repourl,
                ]);
            }
        }

        return $flags;
    }

    /**
     * A repository forked from a classmate's, which GitHub states outright.
     *
     * @param stdClass $submission The submission row.
     * @param stdClass[] $peers The other submissions in the instance.
     * @return stdClass[]
     */
    protected function check_fork_of_peer(stdClass $submission, array $peers): array {
        if (empty($submission->isfork) || trim((string) $submission->forkparent) === '') {
            return [];
        }

        $flags = [];
        $parent = strtolower((string) $submission->forkparent);

        foreach ($peers as $peer) {
            $peerfull = strtolower($peer->repoowner . '/' . $peer->reponame);
            if ($peerfull === $parent) {
                $flags[] = $this->flag(self::FLAG_FORKOFPEER, 'high', (int) $peer->id, [
                    'parent' => $submission->forkparent,
                ]);
            }
        }

        return $flags;
    }

    /**
     * The same commit SHA submitted twice.
     *
     * A commit SHA hashes the tree, the parents, the author and the message, so a
     * clone pushed unchanged keeps it literally identical. This is the sharpest
     * signal available and costs nothing but a comparison.
     *
     * @param stdClass $submission The submission row.
     * @param stdClass[] $peers The other submissions in the instance.
     * @return stdClass[]
     */
    protected function check_identical_commit(stdClass $submission, array $peers): array {
        $flags = [];

        foreach ($peers as $peer) {
            if (strcasecmp((string) $peer->commitsha, (string) $submission->commitsha) === 0) {
                $flags[] = $this->flag(self::FLAG_IDENTICALCOMMIT, 'high', (int) $peer->id, [
                    'commitsha' => $submission->commitsha,
                ]);
            }
        }

        return $flags;
    }

    /**
     * Ancestors shared with another submission.
     *
     * Catches the case where a clone got one commit of its own on top: the tip
     * differs, but everything below it does not.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @param stdClass[] $peers The other submissions in the instance.
     * @return stdClass[]
     */
    protected function check_shared_history(stdClass $instance, stdClass $submission, array $peers): array {
        global $DB;

        if (!$peers) {
            return [];
        }

        $own = $DB->get_fieldset_select('codereview_commits', 'sha', 'submission = ?', [$submission->id]);
        if (!$own) {
            return [];
        }

        [$shasql, $shaparams] = $DB->get_in_or_equal($own, SQL_PARAMS_NAMED, 'sha');
        $params = array_merge($shaparams, ['cid' => $instance->id, 'sid' => $submission->id]);

        $shared = $DB->get_records_sql(
            "SELECT submission, COUNT(DISTINCT sha) AS shared
               FROM {codereview_commits}
              WHERE codereview = :cid AND submission <> :sid AND sha $shasql
           GROUP BY submission",
            $params
        );

        $flags = [];
        foreach ($shared as $row) {
            $flags[] = $this->flag(self::FLAG_SHAREDHISTORY, 'high', (int) $row->submission, [
                'shared' => (int) $row->shared,
                'total' => count($own),
            ]);
        }

        return $flags;
    }

    /**
     * Commits signed by an account that is not the submitter's.
     *
     * Matching a classmate's repository owner is strong; merely differing from the
     * repository owner is common and legitimate, so it is only recorded as context.
     *
     * @param stdClass $submission The submission row.
     * @param stdClass[] $peers The other submissions in the instance.
     * @return stdClass[]
     */
    protected function check_foreign_author(stdClass $submission, array $peers): array {
        $author = strtolower(trim((string) $submission->authorlogin));
        if ($author === '') {
            return [];
        }

        foreach ($peers as $peer) {
            if (strtolower((string) $peer->repoowner) === $author) {
                return [$this->flag(self::FLAG_FOREIGNAUTHOR, 'high', (int) $peer->id, [
                    'authorlogin' => $submission->authorlogin,
                ])];
            }
        }

        if ($author !== strtolower((string) $submission->repoowner)) {
            return [$this->flag(self::FLAG_FOREIGNAUTHOR, 'info', null, [
                'authorlogin' => $submission->authorlogin,
                'repoowner' => $submission->repoowner,
            ])];
        }

        return [];
    }

    /**
     * A history older than the repository holding it.
     *
     * The repository creation timestamp comes from GitHub's own servers and cannot be
     * forged, unlike the commit date. A commit authored before its repository existed
     * was therefore written somewhere else and imported.
     *
     * @param stdClass $submission The submission row.
     * @return stdClass[]
     */
    protected function check_imported_history(stdClass $submission): array {
        $created = (int) $submission->repocreatedat;
        $authored = (int) $submission->commitauthordate;

        if ($created <= 0 || $authored <= 0 || $authored >= $created) {
            return [];
        }

        return [$this->flag(self::FLAG_IMPORTEDHISTORY, 'warning', null, [
            'repocreatedat' => $created,
            'commitauthordate' => $authored,
        ])];
    }

    /**
     * Files byte-identical to another submission's, ignoring the shared template.
     *
     * The comparison runs as a single grouped query over an inverted index of blob
     * hashes rather than pairwise, so a cohort of two hundred costs the same as a
     * cohort of two.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @return stdClass[]
     */
    protected function check_content_overlap(stdClass $instance, stdClass $submission): array {
        global $DB;

        $own = $DB->get_fieldset_select(
            'codereview_blobs',
            'DISTINCT blobsha',
            'submission = ?',
            [$submission->id]
        );
        if (!$own) {
            return [];
        }

        $excluded = array_flip(fingerprint_service::excluded_shas($instance));
        $candidates = array_values(array_filter($own, static fn($sha) => !isset($excluded[$sha])));

        if (count($candidates) < self::OVERLAP_MINIMUM) {
            return [];
        }

        [$shasql, $shaparams] = $DB->get_in_or_equal($candidates, SQL_PARAMS_NAMED, 'sha');
        $params = array_merge($shaparams, ['cid' => $instance->id, 'sid' => $submission->id]);

        $matches = $DB->get_records_sql(
            "SELECT submission, COUNT(DISTINCT blobsha) AS shared
               FROM {codereview_blobs}
              WHERE codereview = :cid AND submission > 0 AND submission <> :sid AND blobsha $shasql
           GROUP BY submission",
            $params
        );

        $flags = [];
        $total = count($candidates);

        foreach ($matches as $row) {
            $shared = (int) $row->shared;
            $ratio = $shared / $total;

            if ($shared < self::OVERLAP_MINIMUM || $ratio < self::OVERLAP_THRESHOLD) {
                continue;
            }

            $flags[] = $this->flag(self::FLAG_CONTENTOVERLAP, $ratio >= 0.9 ? 'high' : 'warning', (int) $row->submission, [
                'shared' => $shared,
                'total' => $total,
                'ratio' => round($ratio, 3),
            ]);
        }

        return $flags;
    }

    /**
     * Builds a flag record.
     *
     * @param string $type The flag type constant.
     * @param string $severity info, warning or high.
     * @param int|null $peer The other submission involved, when there is one.
     * @param array $detail The evidence backing the flag.
     * @return stdClass
     */
    protected function flag(string $type, string $severity, ?int $peer, array $detail): stdClass {
        return (object) [
            'flagtype' => $type,
            'severity' => $severity,
            'peersubmission' => $peer,
            'detail' => json_encode($detail),
        ];
    }
}
