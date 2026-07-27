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
 * Test data generator for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates CodeReview activity instances for tests.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_codereview_generator extends testing_module_generator {
    /**
     * Creates an activity instance, filling in sensible defaults.
     *
     * @param array|stdClass|null $record The instance settings.
     * @param array|null $options Generator options passed to the parent.
     * @return stdClass The created instance.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;

        $defaults = [
            'grade' => 100,
            'weighttests' => 50,
            'weightai' => 50,
            'citimeout' => 30,
            'rubric' => '',
            'rubricformat' => FORMAT_PLAIN,
            'templaterepourl' => null,
            'integritychecks' => 1,
            'duedate' => 0,
            'cutoffdate' => 0,
            'completionchecks' => 0,
            'tokenuserid' => 0,
        ];

        foreach ($defaults as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }

        return parent::create_instance($record, (array) $options);
    }
}
