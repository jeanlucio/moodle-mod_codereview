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

namespace mod_codereview\task;

use core\task\scheduled_task;
use mod_codereview\local\notifier;
use mod_codereview\local\submission_service;

/**
 * Finalises submissions whose polling never reached a conclusion.
 *
 * The adhoc poller normally closes a submission out on its own. This is the safety
 * net for the cases where it cannot: a task lost to a failed cron run, a queue
 * purge, or a site that was down across the whole polling window. Without it a
 * submission could sit at "waiting" indefinitely, which is exactly the outcome the
 * timeout exists to prevent.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_submissions extends scheduled_task {
    /**
     * Returns the localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskreconcilesubmissions', 'mod_codereview');
    }

    /**
     * Closes out every submission that is still waiting past its timeout.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $sql = "SELECT s.*, c.name AS instancename, c.course AS instancecourse, c.citimeout
                  FROM {codereview_submissions} s
                  JOIN {codereview} c ON c.id = s.codereview
                 WHERE s.cistatus IN (:pending, :checking)
                       AND s.timecreated + (c.citimeout * 60) < :now";

        $stuck = $DB->get_records_sql($sql, [
            'pending' => submission_service::CI_PENDING,
            'checking' => submission_service::CI_CHECKING,
            'now' => time(),
        ]);

        if (!$stuck) {
            return;
        }

        // Loading the instances in one go rather than per submission keeps this a
        // constant number of queries no matter how many submissions are stuck.
        $instanceids = array_unique(array_map(fn($s) => (int) $s->codereview, $stuck));
        [$insql, $params] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED);
        $instances = $DB->get_records_select('codereview', "id $insql", $params);

        foreach ($stuck as $submission) {
            $hasruns = $DB->record_exists('codereview_checkruns', ['submission' => $submission->id]);
            $status = $hasruns ? submission_service::CI_COMPLETED : submission_service::CI_NOCIDETECTED;

            $DB->set_field('codereview_submissions', 'cistatus', $status, ['id' => $submission->id]);
            $DB->set_field('codereview_submissions', 'timemodified', time(), ['id' => $submission->id]);

            if ($status === submission_service::CI_NOCIDETECTED && isset($instances[$submission->codereview])) {
                notifier::notify_no_ci_detected($instances[$submission->codereview], $submission);
            }
        }
    }
}
