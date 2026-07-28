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

namespace mod_codereview\table;

use advanced_testcase;
use context_module;
use mod_codereview\local\submission_service;
use moodle_url;
use stdClass;

/**
 * Tests the class overview table.
 *
 * The table is what a teacher lands on, and it is assembled entirely in PHP with no
 * template to lint. A wrong method name on the base class takes the whole page down
 * with a fatal, which is invisible to every other kind of test here: this one builds
 * the table and renders it, the same way the page does.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\table\grading_overview_table
 */
final class grading_overview_table_test extends advanced_testcase {
    /** @var stdClass The activity instance. */
    private stdClass $instance;

    /** @var stdClass The course module. */
    private stdClass $cm;

    /** @var stdClass The course. */
    private stdClass $course;

    /**
     * Creates an activity with two submissions in different states.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->cm = $this->getDataGenerator()->create_module('codereview', [
            'course' => $this->course->id,
            'weighttests' => 100,
            'weightai' => 0,
        ]);
        $this->instance = $DB->get_record('codereview', ['id' => $this->cm->id], '*', MUST_EXIST);

        $graded = $this->submission('ana', submission_service::GRADE_GRADED);
        $this->submission('bruno', submission_service::GRADE_NOTGRADED);

        $DB->insert_record('codereview_grades', (object) [
            'submission' => $graded,
            'graderid' => 2,
            'suggestedgrade' => 50,
            'finalgrade' => 88,
            'feedbackcomment' => '',
            'feedbackformat' => FORMAT_PLAIN,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('codereview_flags', (object) [
            'submission' => $graded,
            'flagtype' => 'importedhistory',
            'severity' => 'warning',
            'peersubmission' => null,
            'detail' => '{}',
            'timecreated' => time(),
        ]);
    }

    /**
     * Creates a submission with one passing and one failing check.
     *
     * @param string $owner The repository owner.
     * @param string $gradestatus The grading status.
     * @return int The submission id.
     */
    private function submission(string $owner, string $gradestatus): int {
        global $DB;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $id = $DB->insert_record('codereview_submissions', (object) [
            'codereview' => $this->instance->id,
            'userid' => $student->id,
            'repourl' => 'https://github.com/' . $owner . '/assignment',
            'repoowner' => $owner,
            'reponame' => 'assignment',
            'commitsha' => str_pad($owner, 40, '0'),
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => $gradestatus,
            'islate' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        foreach ([['tests', 'success'], ['lint', 'failure']] as $i => [$name, $conclusion]) {
            $DB->insert_record('codereview_checkruns', (object) [
                'submission' => $id,
                'externalid' => $id * 10 + $i,
                'checkname' => $name,
                'conclusion' => $conclusion,
                'counted' => 1,
                'timecreated' => time(),
            ]);
        }

        return $id;
    }

    /**
     * Renders a table and returns its output.
     *
     * flexible_table::out() manages output buffers of its own, so the level is
     * restored explicitly rather than relying on a matching ob_get_clean().
     *
     * @param grading_overview_table $table The table to render.
     * @return string
     */
    private function capture(grading_overview_table $table): string {
        $level = ob_get_level();
        ob_start();

        try {
            $table->out(30, true);
            $html = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $html;
    }

    /**
     * The table renders without taking the page down, and shows what the teacher
     * needs: both students, the suggestion, the approved grade and the flag count.
     *
     * @return void
     */
    public function test_table_renders(): void {
        $context = context_module::instance($this->cm->cmid);
        $table = new grading_overview_table($this->instance, $context, (int) $this->cm->cmid);
        $table->define_baseurl(new moodle_url('/mod/codereview/view.php', ['id' => $this->cm->cmid]));

        $html = $this->capture($table);

        $this->assertStringNotContainsString('[[', $html);

        // Both submissions are listed: the generator gives users arbitrary names, so
        // the row count is asserted through the per-row review link instead.
        $this->assertSame(2, substr_count($html, '/mod/codereview/review.php'));

        // One of two countable checks passed, so the suggestion is half of 100.
        $this->assertStringContainsString('50.00', $html);
        // The approved grade of the graded submission.
        $this->assertStringContainsString('88.00', $html);
        // The flag raised on it.
        $this->assertStringContainsString('cr-badge-flags', $html);
        // The late marker.
        $this->assertStringContainsString('cr-badge-late', $html);
        // A link into each review screen.
        $this->assertStringContainsString('/mod/codereview/review.php', $html);
    }

    /**
     * Restricting to a group narrows the rows rather than the whole activity.
     *
     * @return void
     */
    public function test_group_restriction_narrows_the_rows(): void {
        global $DB;

        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $userid = $DB->get_field_sql(
            'SELECT userid FROM {codereview_submissions} WHERE codereview = ? ORDER BY id ASC',
            [$this->instance->id],
            IGNORE_MULTIPLE
        );
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $userid]);

        $context = context_module::instance($this->cm->cmid);
        $table = new grading_overview_table($this->instance, $context, (int) $this->cm->cmid, (int) $group->id);
        $table->define_baseurl(new moodle_url('/mod/codereview/view.php', ['id' => $this->cm->cmid]));

        $html = $this->capture($table);

        $this->assertStringNotContainsString('[[', $html);
        $this->assertSame(1, substr_count($html, '/mod/codereview/review.php'));
    }
}
