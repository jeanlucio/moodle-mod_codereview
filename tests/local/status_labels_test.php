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

use advanced_testcase;

/**
 * Tests for the AI review wording.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\status_labels
 */
final class status_labels_test extends advanced_testcase {
    /**
     * Once a review has been started its own status is what is reported.
     *
     * @return void
     */
    public function test_a_started_review_reports_its_own_status(): void {
        $this->assertSame(
            get_string('aicompleted', 'mod_codereview'),
            status_labels::ai(50, submission_service::CI_COMPLETED, submission_service::AI_COMPLETED, false)
        );
    }

    /**
     * Weight zero is the only case where the activity really has no AI review.
     *
     * @return void
     */
    public function test_zero_weight_means_the_activity_has_no_ai_review(): void {
        $this->assertSame(
            get_string('aidisabled', 'mod_codereview'),
            status_labels::ai(0, submission_service::CI_COMPLETED, submission_service::AI_SKIPPED, true)
        );
    }

    /**
     * While the checks run, "skipped" only means the review has not been asked for yet.
     *
     * @return void
     */
    public function test_the_review_waits_for_the_checks(): void {
        foreach ([submission_service::CI_PENDING, submission_service::CI_CHECKING] as $cistatus) {
            $this->assertSame(
                get_string('aiwaitingci', 'mod_codereview'),
                status_labels::ai(50, $cistatus, submission_service::AI_SKIPPED, true)
            );
        }
    }

    /**
     * When the checks could not be read the review was never started, which is not the
     * same as having no provider.
     *
     * @return void
     */
    public function test_an_unreadable_check_result_blocks_the_review(): void {
        $this->assertSame(
            get_string('aicierror', 'mod_codereview'),
            status_labels::ai(50, submission_service::CI_ERROR, submission_service::AI_SKIPPED, true)
        );
    }

    /**
     * With the checks settled, the only things left to say are "about to start" or
     * "nothing can answer".
     *
     * @return void
     */
    public function test_settled_checks_leave_provider_availability_as_the_reason(): void {
        $this->assertSame(
            get_string('aipending', 'mod_codereview'),
            status_labels::ai(50, submission_service::CI_COMPLETED, submission_service::AI_SKIPPED, true)
        );
        $this->assertSame(
            get_string('ainoprovider', 'mod_codereview'),
            status_labels::ai(50, submission_service::CI_NOCIDETECTED, submission_service::AI_SKIPPED, false)
        );
    }
}
