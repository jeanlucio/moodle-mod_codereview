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
use core\lock\lock_config;
use core\lock\lock_factory;
use core_php_time_limit;
use mod_codereview\task\run_integrity_check;
use moodle_exception;
use stdClass;

/**
 * Runs, on the spot, what a teacher asked to repeat by hand.
 *
 * The pipeline that follows a submission is asynchronous on purpose: the student's
 * request must not wait for GitHub or for a provider. A teacher pressing a button is
 * different. They are looking at the screen, they are the one who has to read the
 * failure, and a click that only queues work would do nothing at all while cron is
 * stopped. So these run inside the request and report what happened.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class manual_runner {
    /** @var int The longest a manual run may take, in seconds. */
    private const TIME_LIMIT = 300;

    /** @var lock_factory|null The factory, kept so one process cannot take the same lock twice. */
    private static ?lock_factory $factory = null;

    /**
     * Reads the automated checks again.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @return string The resulting cistatus.
     */
    public static function recheck_ci(stdClass $instance, stdClass $submission): string {
        return self::locked((int) $submission->id, static function () use ($instance, $submission): string {
            $status = checkrun_poller::for_instance($instance)->poll($instance, $submission);

            // The automatic chain would have queued the integrity check once the checks
            // settled. Doing it here keeps a rescued submission from missing that step.
            $unsettled = [submission_service::CI_PENDING, submission_service::CI_CHECKING, submission_service::CI_ERROR];
            if (!in_array($status, $unsettled, true)) {
                run_integrity_check::queue((int) $submission->id);
            }

            return $status;
        });
    }

    /**
     * Generates the AI review again.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @param context_module $context The activity context.
     * @return string The resulting aistatus.
     */
    public static function rerun_ai(stdClass $instance, stdClass $submission, context_module $context): string {
        return self::locked((int) $submission->id, static function () use ($instance, $submission, $context): string {
            return ai_reviewer::for_instance($instance)->review($instance, $submission, $context);
        });
    }

    /**
     * Returns the lock factory every manual run goes through.
     *
     * Locks are held per database session, so two requests exclude each other whichever
     * factory they use. Within one process a lock is only seen by the factory that took it,
     * which is why this is shared.
     *
     * @return lock_factory
     */
    public static function lock_factory(): lock_factory {
        return self::$factory ??= lock_config::get_lock_factory('mod_codereview');
    }

    /**
     * Runs the work while holding a lock on the submission.
     *
     * A second click while the first is still running would spend the provider budget twice
     * on the same submission, so it is refused instead of queued behind the first.
     *
     * @param int $submissionid The submission being worked on.
     * @param callable $work What to run; it returns the resulting status.
     * @return string The status $work returned.
     * @throws moodle_exception When another manual run for the submission is still going.
     */
    private static function locked(int $submissionid, callable $work): string {
        $lock = self::lock_factory()->get_lock('manual_' . $submissionid, 0);
        if (!$lock) {
            throw new moodle_exception('errorrunninginprogress', 'mod_codereview');
        }

        try {
            core_php_time_limit::raise(self::TIME_LIMIT);

            return $work();
        } finally {
            $lock->release();
        }
    }
}
