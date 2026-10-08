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
use context_course;
use context_system;

/**
 * Tests for who may use a personal GitHub token.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\github_token
 */
final class github_token_test extends advanced_testcase {
    /**
     * Turns the site switch on, which every case but the first one needs.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablepersonaltokens', 1, 'mod_codereview');
    }

    /**
     * The site switch wins over any capability.
     *
     * @return void
     */
    public function test_nobody_may_when_the_site_switch_is_off(): void {
        set_config('enablepersonaltokens', 0, 'mod_codereview');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->assertFalse(github_token::personal_tokens_allowed((int) $teacher->id));
        $this->assertFalse(github_token::personal_tokens_allowed((int) get_admin()->id));
    }

    /**
     * A teacher gets the role from the course enrolment, which never reaches the system
     * context, so the capability has to be looked for where the role actually is.
     *
     * @return void
     */
    public function test_a_course_teacher_may_without_any_system_role(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->assertFalse(has_capability(
            'mod/codereview:usepersonaltoken',
            context_system::instance(),
            $teacher
        ), 'the premise: the system context does not grant it');
        $this->assertTrue(github_token::personal_tokens_allowed((int) $teacher->id));
        $this->assertTrue(github_token::personal_tokens_allowed(
            (int) $teacher->id,
            context_course::instance($course->id)
        ));
    }

    /**
     * Holding the role in one course says nothing about another course.
     *
     * @return void
     */
    public function test_a_given_context_is_checked_on_its_own(): void {
        $teaching = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($teaching, 'editingteacher');

        $this->assertTrue(github_token::personal_tokens_allowed(
            (int) $teacher->id,
            context_course::instance($teaching->id)
        ));
        $this->assertFalse(github_token::personal_tokens_allowed(
            (int) $teacher->id,
            context_course::instance($other->id)
        ));
    }

    /**
     * Students and people with no role anywhere are not offered the option.
     *
     * @return void
     */
    public function test_students_and_people_without_a_course_may_not(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $nobody = $this->getDataGenerator()->create_user();

        $this->assertFalse(github_token::personal_tokens_allowed((int) $student->id));
        $this->assertFalse(github_token::personal_tokens_allowed(
            (int) $student->id,
            context_course::instance($course->id)
        ));
        $this->assertFalse(github_token::personal_tokens_allowed((int) $nobody->id));
    }

    /**
     * A role assigned at the system level still counts, with or without a context.
     *
     * @return void
     */
    public function test_a_system_level_role_still_counts(): void {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/codereview:usepersonaltoken', CAP_ALLOW, $roleid, context_system::instance()->id);
        role_assign($roleid, $user->id, context_system::instance()->id);
        $course = $this->getDataGenerator()->create_course();

        $this->assertTrue(github_token::personal_tokens_allowed((int) $user->id));
        $this->assertTrue(github_token::personal_tokens_allowed(
            (int) $user->id,
            context_course::instance($course->id)
        ));
    }

    /**
     * The activity uses the owner's token when the owner is a course teacher, which is
     * the case the system-context check used to lose silently, falling to the site token.
     *
     * @return void
     */
    public function test_resolve_uses_the_token_of_a_course_teacher(): void {
        set_config('sitetoken', 'site-token', 'mod_codereview');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        github_token::set_personal_token((int) $teacher->id, 'teacher-token');
        $instance = (object) ['course' => $course->id, 'tokenuserid' => $teacher->id];

        $this->assertSame('teacher-token', github_token::resolve($instance));
    }

    /**
     * A student cannot make an activity use a token, whatever the pointer says.
     *
     * @return void
     */
    public function test_resolve_ignores_the_token_of_someone_without_the_capability(): void {
        set_config('sitetoken', 'site-token', 'mod_codereview');
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        github_token::set_personal_token((int) $student->id, 'student-token');
        $instance = (object) ['course' => $course->id, 'tokenuserid' => $student->id];

        $this->assertSame('site-token', github_token::resolve($instance));
    }
}
