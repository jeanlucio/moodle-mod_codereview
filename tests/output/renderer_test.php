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

namespace mod_codereview\output;

use advanced_testcase;
use context_module;
use mod_codereview\local\integrity_checker;
use mod_codereview\local\review_service;
use mod_codereview\local\submission_service;
use stdClass;

/**
 * Tests the presentation layer, both as data and as rendered output.
 *
 * The rendering half exists because nothing else exercises the templates. A
 * missing language string, a mistyped helper or a broken template is invisible to
 * PHPCS, to the PHPDoc checker and to every service-level test, and would first
 * appear on a real page in front of a real class.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\output\review_page
 * @covers     \mod_codereview\output\student_status
 * @covers     \mod_codereview\output\renderer
 */
final class renderer_test extends advanced_testcase {
    /** @var stdClass The activity instance. */
    private stdClass $instance;

    /** @var stdClass The course module. */
    private stdClass $cm;

    /** @var stdClass The student. */
    private stdClass $student;

    /** @var stdClass The submission under review. */
    private stdClass $submission;

    /**
     * Creates an activity with a submission carrying every kind of derived data.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->cm = $this->getDataGenerator()->create_module('codereview', ['course' => $course->id]);
        $this->instance = $DB->get_record('codereview', ['id' => $this->cm->id], '*', MUST_EXIST);
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $id = $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $this->instance->id,
            'userid' => $this->student->id,
            'repourl' => 'https://github.com/octocat/hello-world',
            'repoowner' => 'octocat',
            'reponame' => 'hello-world',
            'commitsha' => str_repeat('a', 40),
            'commitauthordate' => time() - HOURSECS,
            'authorlogin' => 'octocat',
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_COMPLETED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'islate' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('codereview_checkruns', (object) [
            'submission' => $id,
            'externalid' => 1,
            'checkname' => 'pytest',
            'conclusion' => 'success',
            'appslug' => 'github-actions',
            'counted' => 1,
            'detailsurl' => 'https://github.com/octocat/hello-world/runs/1',
            'timecreated' => time(),
        ]);

        $DB->insert_record('codereview_airesults', (object) [
            'submission' => $id,
            'provider' => 'stub',
            'model' => 'stub-model',
            'suggestedgrade' => 85,
            'feedback' => 'Clear structure.',
            'feedbackformat' => FORMAT_PLAIN,
            'status' => 'completed',
            'timecreated' => time(),
        ]);

        $this->submission = $DB->get_record('codereview_submissions', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Adds one flag of every kind the checker can raise.
     *
     * @return void
     */
    private function add_every_flag(): void {
        global $DB;

        $types = [
            integrity_checker::FLAG_FORKOFPEER,
            integrity_checker::FLAG_IDENTICALCOMMIT,
            integrity_checker::FLAG_SHAREDHISTORY,
            integrity_checker::FLAG_CONTENTOVERLAP,
            integrity_checker::FLAG_FOREIGNAUTHOR,
            integrity_checker::FLAG_IMPORTEDHISTORY,
            integrity_checker::FLAG_DUPLICATEREPO,
        ];

        foreach ($types as $type) {
            $DB->insert_record('codereview_flags', (object) [
                'submission' => $this->submission->id,
                'flagtype' => $type,
                'severity' => 'high',
                'peersubmission' => null,
                'detail' => json_encode(['shared' => 3, 'total' => 4, 'authorlogin' => 'someone']),
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * The review context carries the decisions the template branches on.
     *
     * @return void
     */
    public function test_review_page_exports_its_decisions(): void {
        global $PAGE;

        $data = review_service::get_review_data($this->instance, $this->submission);
        $context = (new review_page($data, (int) $this->cm->cmid))
            ->export_for_template($PAGE->get_renderer('mod_codereview'));

        $this->assertTrue($context['hascheckruns']);
        $this->assertTrue($context['hasairesult']);
        $this->assertTrue($context['aisucceeded']);
        $this->assertTrue($context['hassuggestion']);
        $this->assertFalse($context['hasflags']);
        $this->assertSame('85.00', $context['aigradeformatted']);
        $this->assertNotSame('', $context['cistatuslabel']);
    }

    /**
     * The imported history flag tells the teacher how far before the repository the commit is.
     *
     * @return void
     */
    public function test_imported_history_flag_states_the_gap(): void {
        global $DB, $PAGE;

        $DB->insert_record('codereview_flags', (object) [
            'submission' => $this->submission->id,
            'flagtype' => integrity_checker::FLAG_IMPORTEDHISTORY,
            'severity' => 'info',
            'peersubmission' => null,
            'detail' => json_encode(['gap' => 3 * DAYSECS]),
            'timecreated' => time(),
        ]);

        $data = review_service::get_review_data($this->instance, $this->submission);
        $context = (new review_page($data, (int) $this->cm->cmid))
            ->export_for_template($PAGE->get_renderer('mod_codereview'));

        $this->assertCount(1, $context['flags']);
        $this->assertStringContainsString(format_time(3 * DAYSECS), $context['flags'][0]['description']);
    }

    /**
     * A failed AI review is not shown as a suggestion.
     *
     * @return void
     */
    public function test_review_page_marks_a_failed_ai_review(): void {
        global $DB, $PAGE;

        $DB->delete_records('codereview_airesults', ['submission' => $this->submission->id]);
        $DB->insert_record('codereview_airesults', (object) [
            'submission' => $this->submission->id,
            'provider' => '',
            'model' => '',
            'suggestedgrade' => null,
            'feedback' => null,
            'feedbackformat' => FORMAT_PLAIN,
            'status' => 'error',
            'errormessage' => 'Provider unreachable',
            'timecreated' => time(),
        ]);

        $data = review_service::get_review_data($this->instance, $this->submission);
        $context = (new review_page($data, (int) $this->cm->cmid))
            ->export_for_template($PAGE->get_renderer('mod_codereview'));

        $this->assertTrue($context['hasairesult']);
        $this->assertFalse($context['aisucceeded']);
        $this->assertSame('', $context['aigradeformatted']);
    }

    /**
     * The error stored on the submission is shown to the teacher, and not twice when the AI
     * panel already says the same thing.
     *
     * @return void
     */
    public function test_review_page_shows_the_submission_error_once(): void {
        global $DB, $PAGE;

        $DB->set_field('codereview_submissions', 'errormessage', 'GitHub is unreachable', ['id' => $this->submission->id]);
        $submission = $DB->get_record('codereview_submissions', ['id' => $this->submission->id], '*', MUST_EXIST);

        $data = review_service::get_review_data($this->instance, $submission);
        $context = (new review_page($data, (int) $this->cm->cmid))
            ->export_for_template($PAGE->get_renderer('mod_codereview'));

        $this->assertTrue($context['showsubmissionerror']);
        $this->assertSame((int) $this->student->id, $context['userid']);

        $DB->set_field('codereview_airesults', 'status', 'error', ['submission' => $submission->id]);
        $DB->set_field('codereview_airesults', 'errormessage', 'GitHub is unreachable', ['submission' => $submission->id]);

        $data = review_service::get_review_data($this->instance, $submission);
        $context = (new review_page($data, (int) $this->cm->cmid))
            ->export_for_template($PAGE->get_renderer('mod_codereview'));

        $this->assertFalse($context['showsubmissionerror']);
    }

    /**
     * Before the checks settle, "skipped" is explained as waiting, not as "no AI here".
     *
     * @return void
     */
    public function test_review_page_explains_a_review_that_has_not_started(): void {
        global $DB, $PAGE;

        $DB->set_field('codereview_submissions', 'aistatus', submission_service::AI_SKIPPED, ['id' => $this->submission->id]);
        $DB->set_field('codereview_submissions', 'cistatus', submission_service::CI_PENDING, ['id' => $this->submission->id]);
        $submission = $DB->get_record('codereview_submissions', ['id' => $this->submission->id], '*', MUST_EXIST);

        $data = review_service::get_review_data($this->instance, $submission);
        $context = (new review_page($data, (int) $this->cm->cmid))
            ->export_for_template($PAGE->get_renderer('mod_codereview'));

        $this->assertSame(get_string('aiwaitingci', 'mod_codereview'), $context['aistatuslabel']);
    }

    /**
     * Every flag type renders with a sentence, which is what catches a signal whose
     * wording was never added to the language file.
     *
     * @return void
     */
    public function test_every_flag_type_has_wording(): void {
        global $PAGE;

        $this->add_every_flag();

        $data = review_service::get_review_data($this->instance, $this->submission);
        $context = (new review_page($data, (int) $this->cm->cmid))
            ->export_for_template($PAGE->get_renderer('mod_codereview'));

        $this->assertCount(7, $context['flags']);

        foreach ($context['flags'] as $flag) {
            $this->assertNotSame('', $flag['description'], $flag['flagtype'] . ' renders with no wording');
            $this->assertStringNotContainsString('[[', $flag['description']);
            $this->assertNotSame('', $flag['severitylabel']);
        }
    }

    /**
     * The review template renders, with every panel populated.
     *
     * @return void
     */
    public function test_review_page_renders(): void {
        global $PAGE;

        $this->add_every_flag();
        $PAGE->set_context(context_module::instance($this->cm->cmid));

        $renderer = $PAGE->get_renderer('mod_codereview');
        $data = review_service::get_review_data($this->instance, $this->submission);

        $html = $renderer->render_review_page(new review_page($data, (int) $this->cm->cmid));

        $this->assertStringContainsString('pytest', $html);
        $this->assertStringContainsString('Clear structure.', $html);
        $this->assertStringContainsString('85.00', $html);

        // A missing string renders as [[identifier]], which no other gate notices.
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * The student sees where the work was sent and when, and nothing of what the checks or
     * the AI made of it: that is for the teacher to read.
     *
     * @return void
     */
    public function test_student_status_shows_the_delivery_and_nothing_of_the_results(): void {
        global $PAGE;

        $PAGE->set_context(context_module::instance($this->cm->cmid));
        $renderer = $PAGE->get_renderer('mod_codereview');

        $html = $renderer->render_student_status(new student_status($this->submission, (int) $this->cm->cmid));

        $this->assertStringContainsString($this->submission->repourl, $html);
        $this->assertStringContainsString($this->submission->commitsha, $html);
        $this->assertStringContainsString(get_string('islate', 'mod_codereview'), $html);
        $this->assertStringContainsString(get_string('studentgradenotice', 'mod_codereview'), $html);
        $this->assertStringNotContainsString('pytest', $html);
        $this->assertStringNotContainsString(get_string('cicompleted', 'mod_codereview'), $html);
        $this->assertStringNotContainsString(get_string('aicompleted', 'mod_codereview'), $html);
        $this->assertStringNotContainsString('Clear structure.', $html);
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Once the grade is approved the "your grade comes after review" notice is gone.
     *
     * @return void
     */
    public function test_student_status_drops_the_notice_once_graded(): void {
        global $DB, $PAGE;

        $DB->set_field('codereview_submissions', 'gradestatus', submission_service::GRADE_GRADED, [
            'id' => $this->submission->id,
        ]);
        $graded = $DB->get_record('codereview_submissions', ['id' => $this->submission->id], '*', MUST_EXIST);

        $PAGE->set_context(context_module::instance($this->cm->cmid));
        $html = $PAGE->get_renderer('mod_codereview')
            ->render_student_status(new student_status($graded, (int) $this->cm->cmid));

        $this->assertStringNotContainsString(get_string('studentgradenotice', 'mod_codereview'), $html);
    }

    /**
     * The status template also renders when the student has not submitted, which is
     * the first thing anyone opening the activity sees.
     *
     * @return void
     */
    public function test_student_status_renders_without_a_submission(): void {
        global $PAGE;

        $PAGE->set_context(context_module::instance($this->cm->cmid));
        $renderer = $PAGE->get_renderer('mod_codereview');

        $html = $renderer->render_student_status(new student_status(null, (int) $this->cm->cmid));

        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Every status value the database can hold has a label, so a submission cannot
     * reach a state the interface has no words for.
     *
     * @return void
     */
    public function test_every_status_has_a_label(): void {
        $cistatuses = ['pending', 'checking', 'completed', 'nocidetected', 'error'];
        $aistatuses = ['skipped', 'pending', 'completed', 'error'];

        foreach ($cistatuses as $status) {
            $this->assertNotSame('', get_string('ci' . $status, 'mod_codereview'));
        }

        foreach ($aistatuses as $status) {
            $this->assertNotSame('', get_string('ai' . $status, 'mod_codereview'));
        }
    }
}
