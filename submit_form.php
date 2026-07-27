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
 * Student repository submission form.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_codereview\local\repo_url_parser;

/**
 * Lets a student submit a repository URL and a commit SHA.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_codereview_submit_form extends moodleform {
    /**
     * Defines the form elements.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('text', 'repourl', get_string('repourl', 'mod_codereview'), ['size' => 60]);
        $mform->setType('repourl', PARAM_RAW_TRIMMED);
        $mform->addRule('repourl', null, 'required', null, 'client');
        $mform->addHelpButton('repourl', 'repourl', 'mod_codereview');

        $mform->addElement('text', 'commitsha', get_string('commitsha', 'mod_codereview'), ['size' => 44]);
        $mform->setType('commitsha', PARAM_RAW_TRIMMED);
        $mform->addRule('commitsha', null, 'required', null, 'client');
        $mform->addHelpButton('commitsha', 'commitsha', 'mod_codereview');

        $this->add_action_buttons(false, get_string('submitrepo', 'mod_codereview'));
    }

    /**
     * Validates the submitted values before any external request is made.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return array Validation errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        try {
            repo_url_parser::parse((string) ($data['repourl'] ?? ''));
        } catch (moodle_exception $e) {
            $errors['repourl'] = $e->getMessage();
        }

        try {
            repo_url_parser::parse_sha((string) ($data['commitsha'] ?? ''));
        } catch (moodle_exception $e) {
            $errors['commitsha'] = $e->getMessage();
        }

        return $errors;
    }
}
