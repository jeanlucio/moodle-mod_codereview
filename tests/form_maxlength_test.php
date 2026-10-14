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
 * Tests for the length limits of the forms.
 *
 * @package    mod_codereview
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_codereview;

/**
 * Repository URLs are stored in 255-character columns, so the forms must refuse a longer one.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview_mod_form
 * @covers     \mod_codereview_submit_form
 */
final class form_maxlength_test extends \advanced_testcase {
    /**
     * The limits of the maxlength rules registered on a form field.
     *
     * @param \moodleform $form The form.
     * @param string $field Field name.
     * @return int[] The rule limits, empty when the field has none.
     */
    private function maxlength_rules(\moodleform $form, string $field): array {
        $quickform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
        $rules = (new \ReflectionProperty(\HTML_QuickForm::class, '_rules'))->getValue($quickform);
        $limits = [];
        foreach ($rules[$field] ?? [] as $rule) {
            if ($rule['type'] === 'maxlength') {
                $limits[] = (int) $rule['format'];
            }
        }
        return $limits;
    }

    /**
     * The template repository URL of the activity form fits its column.
     */
    public function test_the_template_repository_url_has_a_maxlength_rule_that_fits_the_column(): void {
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

        $limits = $this->maxlength_rules($form, 'templaterepourl');

        $this->assertNotEmpty($limits, 'The template URL field has no maxlength rule.');
        $this->assertLessThanOrEqual(255, min($limits));
    }

    /**
     * The repository URL of the student submission form fits its column.
     */
    public function test_the_submitted_repository_url_has_a_maxlength_rule_that_fits_the_column(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/codereview/submit_form.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);
        $form = new \mod_codereview_submit_form(null, ['instance' => $instance]);

        $limits = $this->maxlength_rules($form, 'repourl');

        $this->assertNotEmpty($limits, 'The repository URL field has no maxlength rule.');
        $this->assertLessThanOrEqual(255, min($limits));
    }
}
