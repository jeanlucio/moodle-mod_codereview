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

use stdClass;

/**
 * Combines the automated checks and the AI review into a suggested grade.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_calculator {
    /** @var string[] Conclusions that count as a pass. */
    public const PASSING = ['success'];

    /** @var string[] Conclusions that are a real result, pass or fail. */
    public const COUNTABLE = ['success', 'failure', 'timed_out', 'cancelled', 'action_required'];

    /**
     * Returns the suggested grade for a submission, or null when there is nothing to suggest.
     *
     * @param stdClass $instance The codereview instance row.
     * @param stdClass $submission The submission row.
     * @return float|null
     */
    public static function calculate(stdClass $instance, stdClass $submission): ?float {
        global $DB;

        $runs = $DB->get_records('codereview_checkruns', ['submission' => $submission->id, 'counted' => 1]);

        $countable = 0;
        $passed = 0;
        foreach ($runs as $run) {
            $conclusion = (string) $run->conclusion;

            // The neutral and skipped conclusions are deliberately outside both the
            // numerator and the denominator: a check that opted out of judging the
            // commit is not a failure of the commit.
            if (!in_array($conclusion, self::COUNTABLE, true)) {
                continue;
            }

            $countable++;
            if (in_array($conclusion, self::PASSING, true)) {
                $passed++;
            }
        }

        $aigrade = null;
        if ($submission->aistatus === submission_service::AI_COMPLETED) {
            $latest = $DB->get_records(
                'codereview_airesults',
                ['submission' => $submission->id, 'status' => 'completed'],
                'timecreated DESC',
                'id, suggestedgrade',
                0,
                1
            );

            if ($latest) {
                $aigrade = (float) reset($latest)->suggestedgrade;
            }
        }

        return self::combine(
            (int) $instance->grade,
            (int) $instance->weighttests,
            (int) $instance->weightai,
            $passed,
            $countable,
            $aigrade
        );
    }

    /**
     * Combines the available components, rescaling the weights to whatever is present.
     *
     * Rescaling is what stops a missing component from being scored as zero. If the
     * teacher never set up a workflow there are no checks to pass, and grading that
     * absence as a failure would punish the student for the teacher's configuration;
     * the same holds when no AI provider answered.
     *
     * @param int $grademax The instance maximum grade.
     * @param int $weighttests The configured weight of the automated checks.
     * @param int $weightai The configured weight of the AI review.
     * @param int $passed Countable checks that passed.
     * @param int $countable Checks that produced a real result.
     * @param float|null $aigrade The AI suggestion on the instance scale, or null.
     * @return float|null Null when neither component is available.
     */
    public static function combine(
        int $grademax,
        int $weighttests,
        int $weightai,
        int $passed,
        int $countable,
        ?float $aigrade
    ): ?float {
        $activetests = $countable > 0 ? $weighttests : 0;
        $activeai = $aigrade !== null ? $weightai : 0;
        $active = $activetests + $activeai;

        if ($active <= 0 || $grademax <= 0) {
            return null;
        }

        $score = 0.0;
        if ($activetests > 0) {
            $score += $activetests * ($passed / $countable);
        }
        if ($activeai > 0) {
            $score += $activeai * ($aigrade / $grademax);
        }

        $grade = $grademax * ($score / $active);

        return round(max(0.0, min((float) $grademax, $grade)), 5);
    }
}
