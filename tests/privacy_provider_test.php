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

namespace mod_codereview;

use advanced_testcase;
use context_module;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use mod_codereview\local\integrity_checker;
use mod_codereview\local\submission_service;
use mod_codereview\privacy\provider;
use stdClass;

/**
 * Tests for the privacy implementation.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\privacy\provider
 */
final class privacy_provider_test extends advanced_testcase {
    /** @var stdClass The activity instance. */
    private stdClass $instance;

    /** @var context_module The activity context. */
    private context_module $context;

    /** @var stdClass The student whose data is exported. */
    private stdClass $ana;

    /** @var stdClass A classmate, referenced by an authorship signal. */
    private stdClass $bruno;

    /** @var int Ana's submission. */
    private int $anasubmission;

    /** @var int Bruno's submission. */
    private int $brunosubmission;

    /**
     * Creates two students with submissions and a signal linking them.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);

        $this->instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);
        $this->context = context_module::instance($module->cmid);

        $this->ana = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bruno = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->anasubmission = $this->submission($this->ana, 'ana');
        $this->brunosubmission = $this->submission($this->bruno, 'bruno');

        $DB->insert_record('codereview_flags', (object) [
            'submission' => $this->anasubmission,
            'flagtype' => integrity_checker::FLAG_IDENTICALCOMMIT,
            'severity' => 'high',
            'peersubmission' => $this->brunosubmission,
            'detail' => json_encode(['commitsha' => 'abc']),
            'timecreated' => time(),
        ]);
    }

    /**
     * Creates a submission with a check-run and an AI suggestion.
     *
     * @param stdClass $user The submitting student.
     * @param string $owner The repository owner.
     * @return int The submission id.
     */
    private function submission(stdClass $user, string $owner): int {
        global $DB;

        $id = $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $this->instance->id,
            'userid' => $user->id,
            'repourl' => 'https://github.com/' . $owner . '/assignment',
            'repoowner' => $owner,
            'reponame' => 'assignment',
            'commitsha' => str_pad($owner, 40, '0'),
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_COMPLETED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('codereview_checkruns', (object) [
            'submission' => $id,
            'externalid' => $id,
            'checkname' => 'tests',
            'conclusion' => 'success',
            'counted' => 1,
            'timecreated' => time(),
        ]);

        $DB->insert_record('codereview_airesults', (object) [
            'submission' => $id,
            'provider' => 'stub',
            'model' => 'stub',
            'suggestedgrade' => 80,
            'feedback' => 'Feedback for ' . $owner,
            'feedbackformat' => FORMAT_PLAIN,
            'status' => 'completed',
            'timecreated' => time(),
        ]);

        $DB->insert_record('codereview_blobs', (object) [
            'codereview' => $this->instance->id,
            'submission' => $id,
            'path' => 'main.py',
            'blobsha' => sha1($owner),
            'filesize' => 10,
            'timecreated' => time(),
        ]);

        return $id;
    }

    /**
     * The submitting student's context is found.
     *
     * @return void
     */
    public function test_contexts_for_userid(): void {
        // Cast because the database driver hands ids back as strings, and the
        // assertion is strict about the type.
        $contexts = array_map('intval', provider::get_contexts_for_userid((int) $this->ana->id)->get_contextids());

        $this->assertContains((int) $this->context->id, $contexts);
    }

    /**
     * Both students are listed as holding data in the context.
     *
     * @return void
     */
    public function test_users_in_context(): void {
        $userlist = new \core_privacy\local\request\userlist($this->context, 'mod_codereview');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();

        $this->assertContains((int) $this->ana->id, $userids);
        $this->assertContains((int) $this->bruno->id, $userids);
    }

    /**
     * The export carries the student's own submission and its derived data.
     *
     * @return void
     */
    public function test_export_includes_own_data(): void {
        $this->export_for($this->ana);

        $data = writer::with_context($this->context)->get_data([]);

        $this->assertSame('https://github.com/ana/assignment', $data->repourl);
        $this->assertCount(1, $data->checkruns);
        $this->assertSame('Feedback for ana', $data->airesults[0]['feedback']);
    }

    /**
     * An authorship signal is a statement about two submissions. The student's own
     * subject access request must not become a way to learn who the other one is.
     *
     * @return void
     */
    public function test_export_does_not_reveal_the_peer(): void {
        $this->export_for($this->ana);

        $data = writer::with_context($this->context)->get_data([]);
        $serialised = json_encode($data);

        $this->assertCount(1, $data->authorshipsignals);
        $this->assertSame(integrity_checker::FLAG_IDENTICALCOMMIT, $data->authorshipsignals[0]['flagtype']);

        $this->assertStringNotContainsString('bruno', strtolower($serialised));
        $this->assertStringNotContainsString((string) $this->brunosubmission, $serialised);
        $this->assertArrayNotHasKey('peersubmission', $data->authorshipsignals[0]);
    }

    /**
     * The stored token is reported as present but never written out: it is a live
     * credential, and an export archive is not the place for one.
     *
     * @return void
     */
    public function test_token_preference_is_redacted(): void {
        set_user_preference(local\github_token::PREFERENCE, 'secret-token-value', $this->ana);

        provider::export_user_preferences((int) $this->ana->id);

        $preferences = writer::with_context(\context_system::instance())->get_user_preferences('mod_codereview');

        $this->assertSame(
            get_string('privacy:redacted', 'mod_codereview'),
            $preferences->{local\github_token::PREFERENCE}->value
        );
        $this->assertStringNotContainsString(
            'secret-token-value',
            json_encode($preferences)
        );
    }

    /**
     * Deleting one user leaves the other alone.
     *
     * @return void
     */
    public function test_delete_for_user_leaves_the_peer(): void {
        global $DB;

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($this->ana->id),
            'mod_codereview',
            [$this->context->id]
        ));

        $this->assertFalse($DB->record_exists('codereview_submissions', ['id' => $this->anasubmission]));
        $this->assertTrue($DB->record_exists('codereview_submissions', ['id' => $this->brunosubmission]));
        $this->assertSame(0, $DB->count_records('codereview_checkruns', ['submission' => $this->anasubmission]));
        $this->assertSame(1, $DB->count_records('codereview_checkruns', ['submission' => $this->brunosubmission]));
    }

    /**
     * A signal pointing at a deleted submission is removed too, rather than left
     * naming a repository that no longer exists here.
     *
     * @return void
     */
    public function test_delete_removes_signals_pointing_at_the_user(): void {
        global $DB;

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($this->bruno->id),
            'mod_codereview',
            [$this->context->id]
        ));

        $this->assertSame(0, $DB->count_records('codereview_flags', ['peersubmission' => $this->brunosubmission]));
    }

    /**
     * Deleting a set of users removes exactly those.
     *
     * @return void
     */
    public function test_delete_for_users(): void {
        global $DB;

        provider::delete_data_for_users(new approved_userlist(
            $this->context,
            'mod_codereview',
            [(int) $this->ana->id]
        ));

        $this->assertFalse($DB->record_exists('codereview_submissions', ['id' => $this->anasubmission]));
        $this->assertTrue($DB->record_exists('codereview_submissions', ['id' => $this->brunosubmission]));
    }

    /**
     * Purging the context clears everyone.
     *
     * @return void
     */
    public function test_delete_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $DB->count_records('codereview_submissions', ['codereview' => $this->instance->id]));
        $this->assertSame(0, $DB->count_records('codereview_blobs', ['codereview' => $this->instance->id]));
    }

    /**
     * Runs the export for a user.
     *
     * @param stdClass $user The user to export.
     * @return void
     */
    private function export_for(stdClass $user): void {
        writer::reset();

        provider::export_user_data(new approved_contextlist(
            \core_user::get_user($user->id),
            'mod_codereview',
            [$this->context->id]
        ));
    }
}
