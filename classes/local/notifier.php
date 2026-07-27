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

namespace mod_codereview\local;

use context_module;
use core\message\message;
use core_user;
use moodle_url;
use stdClass;

/**
 * Sends the activity's notifications.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /**
     * Tells the student and the graders that no automated check ever appeared.
     *
     * A submission that simply sat at "waiting" forever would leave both sides
     * guessing, so the timeout is reported rather than silently absorbed.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission that timed out.
     * @return void
     */
    public static function notify_no_ci_detected(stdClass $instance, stdClass $submission): void {
        $cm = get_coursemodule_from_instance('codereview', $instance->id, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $context = context_module::instance($cm->id);
        $url = new moodle_url('/mod/codereview/view.php', ['id' => $cm->id]);
        $activityname = format_string($instance->name);

        $recipients = [(int) $submission->userid];
        foreach (get_users_by_capability($context, 'mod/codereview:grade', 'u.id') as $grader) {
            $recipients[] = (int) $grader->id;
        }

        foreach (array_unique($recipients) as $userid) {
            $user = core_user::get_user($userid);
            if (!$user) {
                continue;
            }

            $body = get_string('messagenocidetected', 'mod_codereview', (object) [
                'activity' => $activityname,
                'commit' => $submission->commitsha,
            ]);

            $message = new message();
            $message->component = 'mod_codereview';
            $message->name = 'nocidetected';
            $message->userfrom = core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = get_string('messagenocidetectedsubject', 'mod_codereview', $activityname);
            $message->fullmessage = $body;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = $activityname;
            $message->courseid = (int) $instance->course;

            message_send($message);
        }
    }
}
