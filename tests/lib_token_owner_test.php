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
use stdClass;

/**
 * Tests for who the activity's personal credentials belong to, as the settings form saves it.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::codereview_update_instance
 * @covers     ::codereview_add_instance
 */
final class lib_token_owner_test extends advanced_testcase {
    /**
     * Loads the plugin's function library.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/codereview/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Builds the data the settings form submits for an existing activity.
     *
     * @param stdClass $instance The activity row.
     * @param int $cmid The course module id.
     * @param array $changes What the form adds on top of the stored values.
     * @return stdClass
     */
    private function form_data(stdClass $instance, int $cmid, array $changes): stdClass {
        $data = (object) array_merge((array) $instance, ['instance' => $instance->id, 'coursemodule' => $cmid], $changes);
        // The pointer is not a form field, so the submitted data never carries it.
        unset($data->tokenuserid);

        return $data;
    }

    /**
     * Creates an activity owned by a teacher, plus a second teacher in the same course.
     *
     * @return array [instance, course module id, owner, co-teacher]
     */
    private function activity_with_two_teachers(): array {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $module = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);
        $DB->set_field('codereview', 'tokenuserid', $owner->id, ['id' => $module->id]);
        $instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);

        return [$instance, (int) $module->cmid, $owner, $other];
    }

    /**
     * A colleague who edits the activity, to move a date for instance, does not take the
     * owner's credentials away just by saving: the checkbox they never ticked is not a request.
     *
     * @return void
     */
    public function test_a_colleague_saving_the_form_keeps_the_owner(): void {
        global $DB;
        [$instance, $cmid, $owner, $other] = $this->activity_with_two_teachers();
        $this->setUser($other);

        codereview_update_instance($this->form_data($instance, $cmid, ['tokenusemine' => 0]));

        $this->assertSame((int) $owner->id, (int) $DB->get_field('codereview', 'tokenuserid', ['id' => $instance->id]));
    }

    /**
     * A form that does not offer the option at all, because the editor may not use personal
     * credentials, leaves the owner as it was.
     *
     * @return void
     */
    public function test_a_form_without_the_option_keeps_the_owner(): void {
        global $DB;
        [$instance, $cmid, , $other] = $this->activity_with_two_teachers();
        $this->setUser($other);

        codereview_update_instance($this->form_data($instance, $cmid, []));

        $this->assertNotSame(0, (int) $DB->get_field('codereview', 'tokenuserid', ['id' => $instance->id]));
    }

    /**
     * The owner can give the credentials up by unticking the box.
     *
     * @return void
     */
    public function test_the_owner_can_untick_it(): void {
        global $DB;
        [$instance, $cmid, $owner] = $this->activity_with_two_teachers();
        $this->setUser($owner);

        codereview_update_instance($this->form_data($instance, $cmid, ['tokenusemine' => 0]));

        $this->assertSame(0, (int) $DB->get_field('codereview', 'tokenuserid', ['id' => $instance->id]));
    }

    /**
     * Ticking the box is how a colleague takes the credentials over.
     *
     * @return void
     */
    public function test_a_colleague_can_take_it_over_by_ticking(): void {
        global $DB;
        [$instance, $cmid, , $other] = $this->activity_with_two_teachers();
        $this->setUser($other);

        codereview_update_instance($this->form_data($instance, $cmid, ['tokenusemine' => 1]));

        $this->assertSame((int) $other->id, (int) $DB->get_field('codereview', 'tokenuserid', ['id' => $instance->id]));
    }
}
