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

namespace mod_codereview\event;

use context_module;
use core\event\base;
use stdClass;

/**
 * Triggered when a teacher approves a grade and releases it to the gradebook.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_approved extends base {
    /**
     * Builds the event from the graded submission.
     *
     * @param context_module $context The activity context.
     * @param stdClass $submission The submission record.
     * @param float $finalgrade The grade that was released.
     * @return self
     */
    public static function create_from_submission(
        context_module $context,
        stdClass $submission,
        float $finalgrade
    ): self {
        /** @var self $event */
        $event = self::create([
            'context' => $context,
            'objectid' => $submission->id,
            'relateduserid' => $submission->userid,
            'other' => ['finalgrade' => $finalgrade],
        ]);

        return $event;
    }

    /**
     * Initialises the event properties.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'codereview_submissions';
    }

    /**
     * Returns the localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventgrade_approved', 'mod_codereview');
    }

    /**
     * Returns a description of the event for the logs.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' approved a grade for the user with id " .
            "'{$this->relateduserid}' in the codereview activity with course module id " .
            "'{$this->contextinstanceid}'.";
    }
}
