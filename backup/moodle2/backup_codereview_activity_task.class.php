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
 * Backup task for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/codereview/backup/moodle2/backup_codereview_stepslib.php');

/**
 * Assembles the backup steps for the activity.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_codereview_activity_task extends backup_activity_task {
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
        $this->add_step(new backup_codereview_activity_structure_step('codereview_structure', 'codereview.xml'));
    }

    /**
     * Rewrites links to the activity so a restore points at the new copy.
     *
     * @param string $content The content to encode.
     * @return string
     */
    public static function encode_content_links($content): string {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $content = preg_replace(
            '/(' . $base . '\/mod\/codereview\/index.php\?id\=)([0-9]+)/',
            '$@CODEREVIEWINDEX*$2@$',
            $content
        );

        return preg_replace(
            '/(' . $base . '\/mod\/codereview\/view.php\?id\=)([0-9]+)/',
            '$@CODEREVIEWVIEWBYID*$2@$',
            $content
        );
    }
}
