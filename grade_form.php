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
 * Final grade form on the review screen.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Lets the teacher set the grade that reaches the gradebook.
 *
 * The grade field is prefilled with the suggestion but never read-only: the
 * suggestion is an input to a decision, not the decision.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_codereview_grade_form extends moodleform {
    /**
     * Defines the form elements.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $grademax = (int) ($this->_customdata['grademax'] ?? 100);

        $mform->addElement('header', 'decisionheader', get_string('finalgrade', 'mod_codereview'));

        $mform->addElement('text', 'finalgrade', get_string('finalgradeof', 'mod_codereview', $grademax), [
            'size' => 6,
        ]);
        $mform->setType('finalgrade', PARAM_FLOAT);
        $mform->addRule('finalgrade', null, 'required', null, 'client');

        $mform->addElement('textarea', 'feedbackcomment', get_string('feedbackcomment', 'mod_codereview'), [
            'rows' => 8,
            'cols' => 60,
        ]);
        $mform->setType('feedbackcomment', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('approvegrade', 'mod_codereview'));
    }

    /**
     * Keeps the grade inside the activity scale.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return array Validation errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $grademax = (int) ($this->_customdata['grademax'] ?? 100);

        if (!is_numeric($data['finalgrade'] ?? null)) {
            $errors['finalgrade'] = get_string('errorgradeoutofrange', 'mod_codereview', $grademax);

            return $errors;
        }

        $grade = (float) $data['finalgrade'];
        if ($grade < 0 || $grade > $grademax) {
            $errors['finalgrade'] = get_string('errorgradeoutofrange', 'mod_codereview', $grademax);
        }

        return $errors;
    }
}
