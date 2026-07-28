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
use context_module;
use stdClass;

/**
 * Tests for submitting, grading and erasing as a group.
 *
 * The properties covered here have no protection in the schema. One row per group
 * cannot be a unique index while individual submissions all share a group id of
 * zero, and nothing in the database says that a grade approved on one row belongs
 * to several people — both are held up by code alone.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\group_resolver
 * @covers     \mod_codereview\local\submission_service
 * @covers     \mod_codereview\local\grade_approval_service
 */
final class group_submission_test extends advanced_testcase {
    /** @var stdClass The course everything lives in. */
    private stdClass $course;

    /** @var stdClass The team-submission instance. */
    private stdClass $instance;

    /** @var context_module The activity context. */
    private context_module $context;

    /** @var stdClass The group under test. */
    private stdClass $group;

    /** @var stdClass A member who submits. */
    private stdClass $alice;

    /** @var stdClass A member who does not. */
    private stdClass $bob;

    /**
     * Creates a team-submission activity with a two-member group.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('codereview', [
            'course' => $this->course->id,
            'teamsubmission' => 1,
            'weighttests' => 100,
            'weightai' => 0,
        ]);

        $this->instance = $DB->get_record('codereview', ['id' => $cm->id], '*', MUST_EXIST);
        $this->context = context_module::instance($cm->cmid);

        $this->group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->alice = $this->enrol_into_group($this->group->id);
        $this->bob = $this->enrol_into_group($this->group->id);
    }

    /**
     * Enrols a new student and puts them in a group.
     *
     * @param int $groupid The group to join, or 0 to stay ungrouped.
     * @return stdClass The user record.
     */
    private function enrol_into_group(int $groupid): stdClass {
        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        if ($groupid > 0) {
            $this->getDataGenerator()->create_group_member(['groupid' => $groupid, 'userid' => $user->id]);
        }

        return $user;
    }

    /**
     * Inserts a submission owned by a group.
     *
     * The service's own submit() reaches GitHub, so the row is written directly:
     * what is under test here is how the rest of the plugin reads and grades it.
     *
     * @param int $groupid The owning group.
     * @param int $userid The member who submitted.
     * @return stdClass The submission record.
     */
    private function submission_for(int $groupid, int $userid): stdClass {
        global $DB;

        $id = $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $this->instance->id,
            'userid' => $userid,
            'groupid' => $groupid,
            'repourl' => 'https://github.com/alice/assignment',
            'repoowner' => 'alice',
            'reponame' => 'assignment',
            'commitsha' => str_pad((string) $groupid, 40, 'a'),
            'authorlogin' => 'alice',
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return $DB->get_record('codereview_submissions', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * A member who did not submit still sees the group's submission.
     *
     * @return void
     */
    public function test_every_member_resolves_to_the_same_submission(): void {
        $submitted = $this->submission_for((int) $this->group->id, (int) $this->alice->id);

        $foralice = submission_service::find_for_user($this->instance, (int) $this->alice->id);
        $forbob = submission_service::find_for_user($this->instance, (int) $this->bob->id);

        $this->assertEquals($submitted->id, $foralice->id);
        $this->assertEquals($submitted->id, $forbob->id);
    }

    /**
     * Another group's submission is not visible as one's own.
     *
     * @return void
     */
    public function test_a_second_group_gets_its_own_submission(): void {
        $this->submission_for((int) $this->group->id, (int) $this->alice->id);

        $othergroup = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $carol = $this->enrol_into_group((int) $othergroup->id);

        $this->assertFalse(submission_service::find_for_user($this->instance, (int) $carol->id));

        $theirs = $this->submission_for((int) $othergroup->id, (int) $carol->id);
        $found = submission_service::find_for_user($this->instance, (int) $carol->id);

        $this->assertEquals($theirs->id, $found->id);
    }

    /**
     * A student in no group, and one in two, are both blocked, for reasons that are
     * reported apart because the fixes are opposite.
     *
     * @return void
     */
    public function test_blocked_reasons(): void {
        $resolver = new group_resolver($this->instance);

        $this->assertSame('', $resolver->blocked_reason((int) $this->alice->id));

        $ungrouped = $this->enrol_into_group(0);
        $this->assertSame('nogroupwarning', $resolver->blocked_reason((int) $ungrouped->id));

        $second = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $second->id,
            'userid' => $this->alice->id,
        ]);

        $fresh = new group_resolver($this->instance);
        $this->assertSame('multiplegroupswarning', $fresh->blocked_reason((int) $this->alice->id));
    }

    /**
     * Nothing is blocked when the activity is not a team submission.
     *
     * @return void
     */
    public function test_an_individual_instance_blocks_nobody(): void {
        global $DB;

        $cm = $this->getDataGenerator()->create_module('codereview', ['course' => $this->course->id]);
        $individual = $DB->get_record('codereview', ['id' => $cm->id], '*', MUST_EXIST);

        $resolver = new group_resolver($individual);
        $ungrouped = $this->enrol_into_group(0);

        $this->assertSame('', $resolver->blocked_reason((int) $ungrouped->id));
        $this->assertSame(0, $resolver->group_for((int) $this->alice->id));
    }

    /**
     * Only the groups of the chosen grouping form teams.
     *
     * @return void
     */
    public function test_a_grouping_narrows_which_groups_count(): void {
        global $DB;

        $grouping = $this->getDataGenerator()->create_grouping(['courseid' => $this->course->id]);
        $ingrouping = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_grouping_group([
            'groupingid' => $grouping->id,
            'groupid' => $ingrouping->id,
        ]);

        $DB->set_field('codereview', 'teamsubmissiongroupingid', $grouping->id, ['id' => $this->instance->id]);
        $this->instance = $DB->get_record('codereview', ['id' => $this->instance->id], '*', MUST_EXIST);

        $resolver = new group_resolver($this->instance);

        // Alice's group is outside the grouping, so it does not count as a team.
        $this->assertSame('nogroupwarning', $resolver->blocked_reason((int) $this->alice->id));

        $this->getDataGenerator()->create_group_member([
            'groupid' => $ingrouping->id,
            'userid' => $this->alice->id,
        ]);

        $fresh = new group_resolver($this->instance);
        $this->assertSame((int) $ingrouping->id, $fresh->group_for((int) $this->alice->id));
    }

    /**
     * An approved grade reaches every member, not only whoever submitted.
     *
     * @return void
     */
    public function test_the_approved_grade_reaches_the_whole_group(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/codereview/lib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $submission = $this->submission_for((int) $this->group->id, (int) $this->alice->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        (new grade_approval_service())->approve(
            $this->instance,
            $this->context,
            $submission,
            (int) $teacher->id,
            80.0,
            'Good work'
        );

        $grades = codereview_get_user_grades($this->instance);

        $this->assertArrayHasKey((int) $this->alice->id, $grades);
        $this->assertArrayHasKey((int) $this->bob->id, $grades);
        $this->assertEquals(80.0, $grades[(int) $this->bob->id]->rawgrade);

        // And the gradebook itself, not just the plugin's view of it.
        $booked = grade_get_grades(
            $this->course->id,
            'mod',
            'codereview',
            $this->instance->id,
            [$this->alice->id, $this->bob->id]
        );
        $items = reset($booked->items);
        $this->assertEquals(80.0, $items->grades[$this->bob->id]->grade);
    }

    /**
     * Asking for one member's grade returns it even though the row names another.
     *
     * @return void
     */
    public function test_a_single_user_lookup_finds_a_teammates_row(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/codereview/lib.php');

        $submission = $this->submission_for((int) $this->group->id, (int) $this->alice->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        (new grade_approval_service())->approve(
            $this->instance,
            $this->context,
            $submission,
            (int) $teacher->id,
            70.0,
            ''
        );

        $grades = codereview_get_user_grades($this->instance, (int) $this->bob->id);

        $this->assertCount(1, $grades);
        $this->assertEquals(70.0, $grades[(int) $this->bob->id]->rawgrade);
    }

    /**
     * Members of the group are everyone in it at the time of asking.
     *
     * @return void
     */
    public function test_members_of_reads_the_group_not_the_submitter(): void {
        $resolver = new group_resolver($this->instance);

        $members = $resolver->members_of((int) $this->group->id, (int) $this->alice->id);
        sort($members);

        $expected = [(int) $this->alice->id, (int) $this->bob->id];
        sort($expected);

        $this->assertSame($expected, $members);
        $this->assertSame([(int) $this->alice->id], $resolver->members_of(0, (int) $this->alice->id));
    }

    /**
     * A teammate committing into the group's repository is the normal case, so it
     * raises nothing — while the same commit signed by another team's repository
     * owner still does.
     *
     * @return void
     */
    public function test_foreign_author_is_quiet_inside_a_team_but_not_across_teams(): void {
        global $DB;

        $submission = $this->submission_for((int) $this->group->id, (int) $this->alice->id);
        // The repository is Alice's; Bob wrote the commit, as teammates do.
        $DB->set_field('codereview_submissions', 'authorlogin', 'bob', ['id' => $submission->id]);
        $submission = $DB->get_record('codereview_submissions', ['id' => $submission->id], '*', MUST_EXIST);

        $checker = new integrity_checker();
        $checker->check($this->instance, $submission);

        $this->assertSame(0, $DB->count_records('codereview_flags', [
            'submission' => $submission->id,
            'flagtype' => integrity_checker::FLAG_FOREIGNAUTHOR,
        ]));

        // Another team whose repository belongs to the account that wrote the commit.
        $othergroup = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $carol = $this->enrol_into_group((int) $othergroup->id);
        $peer = $this->submission_for((int) $othergroup->id, (int) $carol->id);
        $DB->set_field('codereview_submissions', 'repoowner', 'bob', ['id' => $peer->id]);

        $checker->check($this->instance, $submission);

        $flag = $DB->get_record('codereview_flags', [
            'submission' => $submission->id,
            'flagtype' => integrity_checker::FLAG_FOREIGNAUTHOR,
        ]);

        $this->assertNotFalse($flag);
        $this->assertSame('high', $flag->severity);
    }

    /**
     * An individual instance keeps reporting an author who is not the repository
     * owner, which is the case the suppression above must not swallow.
     *
     * @return void
     */
    public function test_foreign_author_still_reports_on_an_individual_instance(): void {
        global $DB;

        $cm = $this->getDataGenerator()->create_module('codereview', ['course' => $this->course->id]);
        $individual = $DB->get_record('codereview', ['id' => $cm->id], '*', MUST_EXIST);

        $id = $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $individual->id,
            'userid' => $this->alice->id,
            'groupid' => 0,
            'repourl' => 'https://github.com/alice/solo',
            'repoowner' => 'alice',
            'reponame' => 'solo',
            'commitsha' => str_pad('f', 40, 'f'),
            'authorlogin' => 'someoneelse',
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $submission = $DB->get_record('codereview_submissions', ['id' => $id], '*', MUST_EXIST);
        (new integrity_checker())->check($individual, $submission);

        $flag = $DB->get_record('codereview_flags', [
            'submission' => $id,
            'flagtype' => integrity_checker::FLAG_FOREIGNAUTHOR,
        ]);

        $this->assertNotFalse($flag);
        $this->assertSame('info', $flag->severity);
    }

    /**
     * Erasing one member leaves the group's submission standing, because it holds
     * the work of the members who did not ask to be erased.
     *
     * @return void
     */
    public function test_deleting_one_member_keeps_the_groups_submission(): void {
        global $DB;

        $submission = $this->submission_for((int) $this->group->id, (int) $this->alice->id);

        $contextlist = new \core_privacy\local\request\approved_contextlist(
            $this->alice,
            'mod_codereview',
            [$this->context->id]
        );
        \mod_codereview\privacy\provider::delete_data_for_user($contextlist);

        $kept = $DB->get_record('codereview_submissions', ['id' => $submission->id]);

        $this->assertNotFalse($kept);
        // And it no longer names the person whose data was erased.
        $this->assertEquals((int) $this->bob->id, (int) $kept->userid);
    }

    /**
     * Once the last member is erased there is nobody left for the submission to
     * belong to, so it goes.
     *
     * @return void
     */
    public function test_deleting_every_member_removes_the_submission(): void {
        global $DB;

        $submission = $this->submission_for((int) $this->group->id, (int) $this->alice->id);

        $userlist = new \core_privacy\local\request\approved_userlist(
            $this->context,
            'mod_codereview',
            [$this->alice->id, $this->bob->id]
        );
        \mod_codereview\privacy\provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('codereview_submissions', ['id' => $submission->id]));
    }

    /**
     * Students who resolve to no single group are counted for the teacher, since
     * they produce no row on the screen that lists submissions.
     *
     * @return void
     */
    public function test_ungrouped_students_are_counted(): void {
        $this->assertSame(0, group_resolver::count_ungrouped($this->instance, $this->context));

        $this->enrol_into_group(0);
        $this->enrol_into_group(0);

        $this->assertSame(2, group_resolver::count_ungrouped($this->instance, $this->context));

        // Filtering to one group is already a question about people who are in it.
        $this->assertSame(
            0,
            group_resolver::count_ungrouped($this->instance, $this->context, (int) $this->group->id)
        );
    }
}
