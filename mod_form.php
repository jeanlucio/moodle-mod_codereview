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
 * Instance settings form for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_codereview\local\github_token;

/**
 * Instance settings form.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_codereview_mod_form extends moodleform_mod {
    /**
     * Defines the form elements.
     *
     * @return void
     */
    public function definition(): void {
        global $USER, $DB;

        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'assessmentheader', get_string('modulename', 'mod_codereview'));

        $mform->addElement('text', 'weighttests', get_string('weighttests', 'mod_codereview'), ['size' => 4]);
        $mform->setType('weighttests', PARAM_INT);
        $mform->setDefault('weighttests', 50);
        $mform->addHelpButton('weighttests', 'weighttests', 'mod_codereview');

        $mform->addElement('text', 'weightai', get_string('weightai', 'mod_codereview'), ['size' => 4]);
        $mform->setType('weightai', PARAM_INT);
        $mform->setDefault('weightai', 50);
        $mform->addHelpButton('weightai', 'weightai', 'mod_codereview');

        $mform->addElement('textarea', 'rubric', get_string('rubric', 'mod_codereview'), [
            'rows' => 8,
            'cols' => 60,
        ]);
        $mform->setType('rubric', PARAM_TEXT);
        $mform->addHelpButton('rubric', 'rubric', 'mod_codereview');
        $mform->hideIf('rubric', 'weightai', 'eq', 0);

        $mform->addElement('text', 'citimeout', get_string('citimeout', 'mod_codereview'), ['size' => 4]);
        $mform->setType('citimeout', PARAM_INT);
        $mform->setDefault('citimeout', 30);
        $mform->addHelpButton('citimeout', 'citimeout', 'mod_codereview');

        $mform->addElement('text', 'templaterepourl', get_string('templaterepourl', 'mod_codereview'), [
            'size' => 60,
        ]);
        $mform->setType('templaterepourl', PARAM_URL);
        $mform->addHelpButton('templaterepourl', 'templaterepourl', 'mod_codereview');

        $mform->addElement('advcheckbox', 'integritychecks', get_string('integritychecks', 'mod_codereview'));
        $mform->setDefault('integritychecks', 1);
        $mform->addHelpButton('integritychecks', 'integritychecks', 'mod_codereview');

        $mform->addElement('date_time_selector', 'duedate', get_string('duedate', 'mod_codereview'), [
            'optional' => true,
        ]);
        $mform->addHelpButton('duedate', 'duedate', 'mod_codereview');

        $mform->addElement('date_time_selector', 'cutoffdate', get_string('cutoffdate', 'mod_codereview'), [
            'optional' => true,
        ]);
        $mform->addHelpButton('cutoffdate', 'cutoffdate', 'mod_codereview');

        $this->add_token_elements();

        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Adds the personal token elements, which only ever show a name, never a secret.
     *
     * @return void
     */
    protected function add_token_elements(): void {
        global $USER, $DB;

        $mform = $this->_form;

        if (!github_token::personal_tokens_allowed((int) $USER->id)) {
            return;
        }

        $current = 0;
        if (!empty($this->_instance)) {
            $current = (int) $DB->get_field('codereview', 'tokenuserid', ['id' => $this->_instance]);
        }

        if ($current > 0 && $current !== (int) $USER->id) {
            $owner = $DB->get_record('user', ['id' => $current], '*', IGNORE_MISSING);
            if ($owner) {
                $mform->addElement(
                    'static',
                    'tokeninuse',
                    '',
                    get_string('tokeninuse', 'mod_codereview', fullname($owner))
                );
            }
        }

        $mform->addElement('advcheckbox', 'tokenusemine', get_string('tokenusemine', 'mod_codereview'));
        $mform->setDefault('tokenusemine', $current === (int) $USER->id ? 1 : 0);

        if (!github_token::has_personal_token((int) $USER->id)) {
            $mform->addElement('static', 'tokenmissing', '', get_string('personaltokennotset', 'mod_codereview'));
        }
    }

    /**
     * Adds the activity's own completion rules.
     *
     * @return string[] The element names the rules live under.
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;

        $mform->addElement(
            'advcheckbox',
            'completionsubmit',
            '',
            get_string('completionsubmit', 'mod_codereview')
        );

        $group = [
            $mform->createElement(
                'advcheckbox',
                'completionchecksenabled',
                '',
                get_string('completionchecks', 'mod_codereview')
            ),
            $mform->createElement('text', 'completionchecks', '', ['size' => 3]),
        ];
        $mform->setType('completionchecks', PARAM_INT);
        $mform->addGroup($group, 'completionchecksgroup', '', ' ', false);
        $mform->hideIf('completionchecks', 'completionchecksenabled', 'notchecked');

        return ['completionsubmit', 'completionchecksgroup'];
    }

    /**
     * Reports whether any of the activity's own rules is switched on.
     *
     * @param array $data The submitted data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        $checks = !empty($data['completionchecksenabled']) && (int) ($data['completionchecks'] ?? 0) > 0;

        return !empty($data['completionsubmit']) || $checks;
    }

    /**
     * Derives the checkbox state from the stored value before the form is shown.
     *
     * @param array $defaultvalues The values about to be loaded, modified in place.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues): void {
        parent::data_preprocessing($defaultvalues);

        $defaultvalues['completionchecksenabled'] = !empty($defaultvalues['completionchecks']) ? 1 : 0;

        if (empty($defaultvalues['completionchecks'])) {
            $defaultvalues['completionchecks'] = 1;
        }
    }

    /**
     * Validates the submitted settings.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return array Validation errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $tests = (int) ($data['weighttests'] ?? 0);
        $ai = (int) ($data['weightai'] ?? 0);

        if ($tests < 0 || $tests > 100) {
            $errors['weighttests'] = get_string('weightsmustsum', 'mod_codereview');
        }
        if ($ai < 0 || $ai > 100) {
            $errors['weightai'] = get_string('weightsmustsum', 'mod_codereview');
        }
        if (!isset($errors['weighttests']) && !isset($errors['weightai']) && $tests + $ai !== 100) {
            $errors['weighttests'] = get_string('weightsmustsum', 'mod_codereview');
        }

        if ((int) ($data['citimeout'] ?? 0) < 1) {
            $errors['citimeout'] = get_string('citimeout', 'mod_codereview');
        }

        $cutoff = (int) ($data['cutoffdate'] ?? 0);
        $due = (int) ($data['duedate'] ?? 0);
        if ($cutoff > 0 && $due > 0 && $cutoff < $due) {
            $errors['cutoffdate'] = get_string('cutoffdate_help', 'mod_codereview');
        }

        if (!empty($data['templaterepourl'])) {
            try {
                \mod_codereview\local\repo_url_parser::parse((string) $data['templaterepourl']);
            } catch (moodle_exception $e) {
                $errors['templaterepourl'] = $e->getMessage();
            }
        }

        return $errors;
    }
}
