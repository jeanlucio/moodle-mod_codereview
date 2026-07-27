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
 * Triggered when a student submits or replaces a repository.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repo_submitted extends base {
    /**
     * Builds the event from a stored submission record.
     *
     * @param context_module $context The activity context.
     * @param stdClass $submission The submission record.
     * @return self
     */
    public static function create_from_submission(context_module $context, stdClass $submission): self {
        /** @var self $event */
        $event = self::create([
            'context' => $context,
            'objectid' => $submission->id,
            'relateduserid' => $submission->userid,
            'other' => [
                'commitsha' => $submission->commitsha,
            ],
        ]);

        return $event;
    }

    /**
     * Initialises the event properties.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'codereview_submissions';
    }

    /**
     * Returns the localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventrepo_submitted', 'mod_codereview');
    }

    /**
     * Returns a description of the event for the logs.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' submitted a repository for the codereview " .
            "activity with course module id '{$this->contextinstanceid}'.";
    }
}
