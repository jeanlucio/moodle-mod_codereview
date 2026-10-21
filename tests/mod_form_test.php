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

/**
 * Unit tests for mod_codereview_mod_form's completion rule.
 *
 * @package    mod_codereview
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_codereview;

/**
 * Tests for the completion rule of mod_codereview_mod_form.
 *
 * @covers \mod_codereview_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /**
     * The completion rule element carries the form's suffix.
     *
     * The core shows the form on the "Default activity completion" page with a suffix and removes any
     * custom rule whose name lacks it, which would erase the rule from the defaults of every new activity.
     *
     * @return void
     */
    public function test_the_completion_rule_element_carries_the_form_suffix(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/mod/codereview/mod_form.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('codereview', $instance->id);
        $PAGE->set_course($course);
        $form = new \mod_codereview_mod_form((object) [
            'instance' => $instance->id, 'id' => $cm->id, 'course' => $course->id,
        ], 0, $cm, $course);
        $quickform = (new \ReflectionProperty(\mod_codereview_mod_form::class, '_form'))->getValue($form);

        $form->set_suffix('_codereview');
        $names = $form->add_completion_rules();

        $this->assertSame(['completionsubmit_codereview'], $names);
        $this->assertTrue($quickform->elementExists('completionsubmit_codereview'));
        $this->assertFalse($quickform->elementExists('completionsubmit'));
        $this->assertTrue($form->completion_rule_enabled(['completionsubmit_codereview' => 1]));
        $this->assertFalse($form->completion_rule_enabled(['completionsubmit' => 1]));
    }
}
