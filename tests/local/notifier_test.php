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
 * Tests for the activity's notifications.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\notifier
 */
final class notifier_test extends advanced_testcase {
    /**
     * A missing CI is reported to the teachers and not to the student, who no longer sees
     * what the checks found.
     *
     * @return void
     */
    public function test_no_ci_detected_goes_to_the_graders_only(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);
        $submission = (object) [
            'codereview' => $instance->id,
            'userid' => $student->id,
            'commitsha' => str_repeat('a', 40),
        ];

        $sink = $this->redirectMessages();
        notifier::notify_no_ci_detected($instance, $submission);
        $messages = $sink->get_messages();
        $sink->close();

        $recipients = array_map(static fn(stdClass $m): int => (int) $m->useridto, $messages);

        $this->assertContains((int) $teacher->id, $recipients);
        $this->assertNotContains((int) $student->id, $recipients);
    }
}
