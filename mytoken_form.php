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
 * Personal GitHub token form.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Stores or removes the user's own GitHub token.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_codereview_mytoken_form extends moodleform {
    /**
     * Defines the form elements.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        // The element starts empty on every render. A stored token is never sent back
        // to the browser, so there is nothing for a shoulder surfer or a saved page to
        // capture; the page states only whether one exists.
        $mform->addElement('passwordunmask', 'token', get_string('personaltoken', 'mod_codereview'));
        $mform->setType('token', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('token', 'personaltoken', 'mod_codereview');

        if (!empty($this->_customdata['hastoken'])) {
            $mform->addElement('advcheckbox', 'removetoken', get_string('personaltokenremove', 'mod_codereview'));
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
