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

/**
 * Chooses the words the teacher reads for the state of an AI review.
 *
 * A submission is created with the AI status "skipped", meaning only that nothing has
 * asked for a review yet. Printed as it is, that reads like "this activity has no AI",
 * which is wrong while the checks are still running and wrong again once the review
 * is merely queued. The real reason is a function of the weight, the checks and whether
 * a provider can answer, so it is worked out here and kept free of any database access.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status_labels {
    /**
     * Returns the label for the AI review of a submission.
     *
     * @param int $weightai The weight the activity gives to the AI review.
     * @param string $cistatus The submission's automated check status.
     * @param string $aistatus The submission's AI review status.
     * @param bool $provideravailable Whether a provider would answer a request now.
     * @return string
     */
    public static function ai(int $weightai, string $cistatus, string $aistatus, bool $provideravailable): string {
        if ($aistatus !== submission_service::AI_SKIPPED) {
            return get_string('ai' . $aistatus, 'mod_codereview');
        }

        if ($weightai <= 0) {
            return get_string('aidisabled', 'mod_codereview');
        }

        if (in_array($cistatus, [submission_service::CI_PENDING, submission_service::CI_CHECKING], true)) {
            return get_string('aiwaitingci', 'mod_codereview');
        }

        if ($cistatus === submission_service::CI_ERROR) {
            return get_string('aicierror', 'mod_codereview');
        }

        return $provideravailable ? get_string('aipending', 'mod_codereview') : get_string('ainoprovider', 'mod_codereview');
    }
}
