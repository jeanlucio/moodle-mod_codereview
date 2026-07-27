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

use advanced_testcase;
use stdClass;

/**
 * Tests for the authorship signals.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\integrity_checker
 * @covers     \mod_codereview\local\fingerprint_service
 */
final class integrity_checker_test extends advanced_testcase {
    /** @var stdClass The activity instance under test. */
    private stdClass $instance;

    /** @var stdClass The course the activity belongs to. */
    private stdClass $course;

    /**
     * Creates an instance with authorship verification enabled.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', [
            'course' => $this->course->id,
            'integritychecks' => 1,
        ]);

        $this->instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);
    }

    /**
     * Creates a submission row.
     *
     * @param string $owner The repository owner.
     * @param array $overrides Fields to override.
     * @return stdClass The stored submission.
     */
    private function submission(string $owner, array $overrides = []): stdClass {
        global $DB;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $submission = (object) array_merge([
            'codereview' => $this->instance->id,
            'userid' => $student->id,
            'repourl' => 'https://github.com/' . $owner . '/assignment',
            'repoowner' => $owner,
            'reponame' => 'assignment',
            'commitsha' => str_pad($owner, 40, '0'),
            'commitauthordate' => 0,
            'repocreatedat' => 0,
            'repopushedat' => 0,
            'isfork' => 0,
            'forkparent' => null,
            'authorlogin' => $owner,
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $overrides);

        $submission->id = $DB->insert_record('codereview_submissions', $submission);

        return $submission;
    }

    /**
     * Records blob hashes for a submission.
     *
     * @param int $submissionid The submission, or 0 for the template baseline.
     * @param array $shas Paths mapped to blob hashes.
     * @return void
     */
    private function blobs(int $submissionid, array $shas): void {
        global $DB;

        foreach ($shas as $path => $sha) {
            $DB->insert_record('codereview_blobs', (object) [
                'codereview' => $this->instance->id,
                'submission' => $submissionid,
                'path' => $path,
                'blobsha' => $sha,
                'filesize' => 100,
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * Records commit ancestry for a submission.
     *
     * @param int $submissionid The submission.
     * @param array $shas The commit hashes, newest first.
     * @return void
     */
    private function commits(int $submissionid, array $shas): void {
        global $DB;

        foreach (array_values($shas) as $position => $sha) {
            $DB->insert_record('codereview_commits', (object) [
                'submission' => $submissionid,
                'codereview' => $this->instance->id,
                'sha' => $sha,
                'position' => $position,
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * Returns the flag types raised for a submission.
     *
     * @param int $submissionid The submission.
     * @return string[]
     */
    private function flags(int $submissionid): array {
        global $DB;

        return $DB->get_fieldset_select('codereview_flags', 'flagtype', 'submission = ?', [$submissionid]);
    }

    /**
     * A clone pushed unchanged keeps the commit SHA, which is the sharpest signal.
     *
     * @return void
     */
    public function test_identical_commit_is_flagged(): void {
        $sha = str_repeat('a', 40);
        $first = $this->submission('ana', ['commitsha' => $sha]);
        $second = $this->submission('bruno', ['commitsha' => $sha]);

        (new integrity_checker())->check($this->instance, $second);

        $this->assertContains(integrity_checker::FLAG_IDENTICALCOMMIT, $this->flags($second->id));
    }

    /**
     * Forking a classmate's repository is stated outright by GitHub.
     *
     * @return void
     */
    public function test_fork_of_peer_is_flagged(): void {
        $first = $this->submission('ana');
        $second = $this->submission('bruno', ['isfork' => 1, 'forkparent' => 'ana/assignment']);

        (new integrity_checker())->check($this->instance, $second);

        $this->assertContains(integrity_checker::FLAG_FORKOFPEER, $this->flags($second->id));
    }

    /**
     * Two submissions naming the same repository.
     *
     * @return void
     */
    public function test_duplicate_repository_is_flagged(): void {
        $first = $this->submission('ana');
        $second = $this->submission('bruno', ['repourl' => 'https://github.com/ana/assignment']);

        (new integrity_checker())->check($this->instance, $second);

        $this->assertContains(integrity_checker::FLAG_DUPLICATEREPO, $this->flags($second->id));
    }

    /**
     * A rewritten tip does not hide a history that is otherwise shared.
     *
     * @return void
     */
    public function test_shared_history_is_flagged(): void {
        $first = $this->submission('ana');
        $second = $this->submission('bruno');

        $this->commits($first->id, ['aaa', 'bbb', 'ccc']);
        $this->commits($second->id, ['zzz', 'bbb', 'ccc']);

        (new integrity_checker())->check($this->instance, $second);

        $this->assertContains(integrity_checker::FLAG_SHAREDHISTORY, $this->flags($second->id));
    }

    /**
     * Commits signed by a classmate's account.
     *
     * @return void
     */
    public function test_foreign_author_matching_a_peer_is_flagged(): void {
        global $DB;

        $first = $this->submission('ana');
        $second = $this->submission('bruno', ['authorlogin' => 'ana']);

        (new integrity_checker())->check($this->instance, $second);

        $flag = $DB->get_record('codereview_flags', [
            'submission' => $second->id,
            'flagtype' => integrity_checker::FLAG_FOREIGNAUTHOR,
        ]);

        $this->assertNotEmpty($flag);
        $this->assertSame('high', $flag->severity);
    }

    /**
     * A commit dated before its repository existed came from somewhere else. The
     * repository timestamp is server generated, unlike the commit date.
     *
     * @return void
     */
    public function test_imported_history_is_flagged(): void {
        $submission = $this->submission('ana', [
            'repocreatedat' => time(),
            'commitauthordate' => time() - (30 * DAYSECS),
        ]);

        (new integrity_checker())->check($this->instance, $submission);

        $this->assertContains(integrity_checker::FLAG_IMPORTEDHISTORY, $this->flags($submission->id));
    }

    /**
     * Byte-identical files across two submissions.
     *
     * @return void
     */
    public function test_content_overlap_is_flagged(): void {
        $first = $this->submission('ana');
        $second = $this->submission('bruno');

        $shared = ['a.py' => 'sha1', 'b.py' => 'sha2', 'c.py' => 'sha3'];
        $this->blobs($first->id, $shared);
        $this->blobs($second->id, $shared);

        (new integrity_checker())->check($this->instance, $second);

        $this->assertContains(integrity_checker::FLAG_CONTENTOVERLAP, $this->flags($second->id));
    }

    /**
     * Files that came from the template repository are the same for everyone, so they
     * must never be reported as copying. Without this the whole cohort would be
     * flagged against each other on day one.
     *
     * @return void
     */
    public function test_template_files_do_not_trigger_overlap(): void {
        $first = $this->submission('ana');
        $second = $this->submission('bruno');

        $template = ['README.md' => 'shatpl1', 'setup.py' => 'shatpl2', '.gitignore' => 'shatpl3'];
        $this->blobs(fingerprint_service::BASELINE_SUBMISSION, $template);
        $this->blobs($first->id, $template);
        $this->blobs($second->id, $template);

        (new integrity_checker())->check($this->instance, $second);

        $this->assertNotContains(integrity_checker::FLAG_CONTENTOVERLAP, $this->flags($second->id));
    }

    /**
     * Two students who genuinely did their own work raise nothing at all. A checker
     * that flagged everyone would be worse than none.
     *
     * @return void
     */
    public function test_independent_work_raises_no_flags(): void {
        $first = $this->submission('ana');
        $second = $this->submission('bruno');

        $this->blobs($first->id, ['a.py' => 'anasha1', 'b.py' => 'anasha2']);
        $this->blobs($second->id, ['a.py' => 'brunosha1', 'b.py' => 'brunosha2']);
        $this->commits($first->id, ['ana1', 'ana2']);
        $this->commits($second->id, ['bruno1', 'bruno2']);

        (new integrity_checker())->check($this->instance, $second);

        $this->assertSame([], $this->flags($second->id));
    }

    /**
     * Turning the feature off leaves no signals behind, including ones raised before.
     *
     * @return void
     */
    public function test_disabled_checks_produce_nothing(): void {
        global $DB;

        $sha = str_repeat('a', 40);
        $first = $this->submission('ana', ['commitsha' => $sha]);
        $second = $this->submission('bruno', ['commitsha' => $sha]);

        $checker = new integrity_checker();
        $checker->check($this->instance, $second);
        $this->assertNotEmpty($this->flags($second->id));

        $this->instance->integritychecks = 0;
        $DB->update_record('codereview', $this->instance);
        $checker->check($this->instance, $second);

        $this->assertSame([], $this->flags($second->id));
    }

    /**
     * Recomputing replaces the previous signals instead of stacking duplicates,
     * because a resubmission or a peer change re-runs the whole check.
     *
     * @return void
     */
    public function test_recomputing_does_not_duplicate_flags(): void {
        $sha = str_repeat('a', 40);
        $first = $this->submission('ana', ['commitsha' => $sha]);
        $second = $this->submission('bruno', ['commitsha' => $sha]);

        $checker = new integrity_checker();
        $checker->check($this->instance, $second);
        $checker->check($this->instance, $second);

        $this->assertCount(1, $this->flags($second->id));
    }

    /**
     * No signal ever changes a grade or blocks a submission: they are evidence for a
     * teacher, not an enforcement mechanism.
     *
     * @return void
     */
    public function test_flags_never_alter_the_submission(): void {
        global $DB;

        $sha = str_repeat('a', 40);
        $first = $this->submission('ana', ['commitsha' => $sha]);
        $second = $this->submission('bruno', ['commitsha' => $sha]);

        (new integrity_checker())->check($this->instance, $second);

        $stored = $DB->get_record('codereview_submissions', ['id' => $second->id]);
        $this->assertSame(submission_service::GRADE_NOTGRADED, $stored->gradestatus);
        $this->assertSame(submission_service::CI_COMPLETED, $stored->cistatus);
        $this->assertSame(0, $DB->count_records('codereview_grades', ['submission' => $second->id]));
    }
}
