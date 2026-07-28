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
 * Restore task for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/codereview/backup/moodle2/restore_codereview_stepslib.php');

/**
 * Assembles the restore steps for the activity.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_codereview_activity_task extends restore_activity_task {
    /**
     * Defines task settings. The activity has none of its own.
     *
     * @return void
     */
    protected function define_my_settings(): void {
    }

    /**
     * Adds the structure step.
     *
     * @return void
     */
    protected function define_my_steps(): void {
        $this->add_step(new restore_codereview_activity_structure_step('codereview_structure', 'codereview.xml'));
    }

    /**
     * Declares the file areas the activity owns.
     *
     * @return array
     */
    public static function define_decode_contents(): array {
        return [new restore_decode_content('codereview', ['intro'], 'codereview')];
    }

    /**
     * Declares the link patterns the backup encoded.
     *
     * @return array
     */
    public static function define_decode_rules(): array {
        return [
            new restore_decode_rule('CODEREVIEWVIEWBYID', '/mod/codereview/view.php?id=$1', 'course_module'),
            new restore_decode_rule('CODEREVIEWINDEX', '/mod/codereview/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Declares the log entries the activity restores.
     *
     * @return array
     */
    public static function define_restore_log_rules(): array {
        return [];
    }
}
