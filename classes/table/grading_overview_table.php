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

use context_module;
use html_writer;
use moodle_url;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * The class overview a teacher lands on.
 *
 * Paged and sorted in SQL rather than assembled in memory: a cohort of two
 * hundred is exactly the case the activity exists to make manageable, so the
 * screen that lists it must not be the part that stops scaling.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grading_overview_table extends table_sql {
    /** @var stdClass The codereview instance row. */
    protected stdClass $instance;

    /** @var context_module The activity context. */
    protected context_module $context;

    /** @var int The course module id, for building links. */
    protected int $cmid;

    /** @var array Flag counts of the current page, keyed by submission id. */
    protected array $flagcounts = [];

    /** @var array Countable and passing check totals of the current page, keyed by submission id. */
    protected array $checkstats = [];

    /** @var array Latest AI grade of the current page, keyed by submission id. */
    protected array $aigrades = [];

    /**
     * Constructor.
     *
     * @param stdClass $instance The codereview instance row.
     * @param context_module $context The activity context.
     * @param int $cmid The course module id.
     * @param int $groupid The group to restrict to, or 0 for all.
     */
    public function __construct(stdClass $instance, context_module $context, int $cmid, int $groupid = 0) {
        parent::__construct('mod_codereview_overview_' . $cmid);

        $this->instance = $instance;
        $this->context = $context;
        $this->cmid = $cmid;

        $this->define_columns(['fullname', 'cistatus', 'aistatus', 'flags', 'suggested', 'finalgrade', 'actions']);
        $this->define_headers([
            get_string('fullname'),
            get_string('checkruns', 'mod_codereview'),
            get_string('aireview', 'mod_codereview'),
            get_string('integritypanel', 'mod_codereview'),
            get_string('suggestedgrade', 'mod_codereview'),
            get_string('finalgrade', 'mod_codereview'),
            get_string('actions'),
        ]);

        $this->no_sorting('flags');
        $this->no_sorting('suggested');
        $this->no_sorting('actions');
        $this->collapsible(false);
        // The default has to name a real column: "fullname" is assembled for display
        // and would reach the database as an ORDER BY on a column that does not exist.
        // The header still offers its own first-name and last-name sort links.
        $this->sortable(true, 'lastname', SORT_ASC);

        $this->build_sql($groupid);
    }

    /**
     * Sets the query, restricted to the users the viewer is allowed to see.
     *
     * @param int $groupid The group to restrict to, or 0 for all.
     * @return void
     */
    protected function build_sql(int $groupid): void {
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false);

        // Whether the name fields arrive with a leading comma depends on how get_sql()
        // was called, so the separator is normalised rather than assumed: concatenating
        // them straight on runs the last own column into the first name column.
        $namefields = ltrim(trim($userfields->selects), ',');

        $fields = 's.id, s.userid, s.cistatus, s.aistatus, s.gradestatus, s.islate, s.commitsha,
                   s.repourl, s.timecreated, g.finalgrade, ' . $namefields;

        $from = '{codereview_submissions} s
                 JOIN {user} u ON u.id = s.userid
            LEFT JOIN {codereview_grades} g ON g.submission = s.id';

        $where = 's.codereview = :codereview';
        $params = ['codereview' => $this->instance->id] + $userfields->params;

        if ($groupid > 0) {
            $from .= ' JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid = :groupid';
            $params['groupid'] = $groupid;
        }

        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
    }

    /**
     * Renders the student name, with a late marker when relevant.
     *
     * @param stdClass $row The row being rendered.
     * @return string
     */
    public function col_fullname($row): string {
        $name = fullname($row);

        if (!empty($row->islate)) {
            $name .= ' ' . html_writer::span(
                get_string('islate', 'mod_codereview'),
                'badge bg-warning text-dark cr-badge-late'
            );
        }

        return $name;
    }

    /**
     * Renders the automated check status.
     *
     * @param stdClass $row The row being rendered.
     * @return string
     */
    public function col_cistatus($row): string {
        return $this->status_badge('ci', (string) $row->cistatus);
    }

    /**
     * Renders the AI review status.
     *
     * @param stdClass $row The row being rendered.
     * @return string
     */
    public function col_aistatus($row): string {
        return $this->status_badge('ai', (string) $row->aistatus);
    }

    /**
     * Fetches the page, then loads everything the extra columns need in bulk.
     *
     * Querying per row would put three statements behind every student on screen,
     * which is the pattern this table exists to avoid.
     *
     * @param int $pagesize Rows per page.
     * @param bool $useinitialsbar Whether to apply the initials bar.
     * @return void
     */
    public function query_db($pagesize, $useinitialsbar = true): void {
        global $DB;

        parent::query_db($pagesize, $useinitialsbar);

        $ids = array_keys($this->rawdata ?? []);
        if (!$ids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);

        $this->flagcounts = $DB->get_records_sql_menu(
            "SELECT submission, COUNT(1)
               FROM {codereview_flags}
              WHERE submission $insql
           GROUP BY submission",
            $params
        );

        $countable = \mod_codereview\local\grade_calculator::COUNTABLE;
        [$consql, $conparams] = $DB->get_in_or_equal($countable, SQL_PARAMS_NAMED, 'con');

        $this->checkstats = $DB->get_records_sql(
            "SELECT submission,
                    COUNT(1) AS countable,
                    SUM(CASE WHEN conclusion = :success THEN 1 ELSE 0 END) AS passed
               FROM {codereview_checkruns}
              WHERE submission $insql AND counted = 1 AND conclusion $consql
           GROUP BY submission",
            $params + $conparams + ['success' => 'success']
        );

        // Ordered oldest first so the last row written per submission is the newest,
        // which avoids a per-group subquery that not every database plans well.
        $this->aigrades = [];
        $results = $DB->get_records_select(
            'codereview_airesults',
            "submission $insql AND status = :status AND suggestedgrade IS NOT NULL",
            $params + ['status' => 'completed'],
            'timecreated ASC'
        );
        foreach ($results as $result) {
            $this->aigrades[(int) $result->submission] = (float) $result->suggestedgrade;
        }
    }

    /**
     * Renders how many authorship signals the submission carries.
     *
     * @param stdClass $row The row being rendered.
     * @return string
     */
    public function col_flags($row): string {
        $count = (int) ($this->flagcounts[$row->id] ?? 0);

        if ($count === 0) {
            return html_writer::span('—', 'text-body');
        }

        return html_writer::span(
            $count,
            'badge bg-warning text-dark cr-badge-flags',
            ['title' => get_string('integritypanel', 'mod_codereview')]
        );
    }

    /**
     * Renders the suggested grade, or a dash when there is nothing to suggest.
     *
     * @param stdClass $row The row being rendered.
     * @return string
     */
    public function col_suggested($row): string {
        $stats = $this->checkstats[$row->id] ?? null;
        $aigrade = $row->aistatus === 'completed' ? ($this->aigrades[(int) $row->id] ?? null) : null;

        $suggested = \mod_codereview\local\grade_calculator::combine(
            (int) $this->instance->grade,
            (int) $this->instance->weighttests,
            (int) $this->instance->weightai,
            $stats ? (int) $stats->passed : 0,
            $stats ? (int) $stats->countable : 0,
            $aigrade
        );

        return $suggested === null ? '—' : format_float($suggested, 2);
    }

    /**
     * Renders the approved grade, or a dash when it has not been approved.
     *
     * @param stdClass $row The row being rendered.
     * @return string
     */
    public function col_finalgrade($row): string {
        if ($row->finalgrade === null || $row->gradestatus !== 'graded') {
            return '—';
        }

        return format_float((float) $row->finalgrade, 2);
    }

    /**
     * Renders the link into the individual review screen.
     *
     * @param stdClass $row The row being rendered.
     * @return string
     */
    public function col_actions($row): string {
        return html_writer::link(
            new moodle_url('/mod/codereview/review.php', ['id' => $row->id]),
            get_string('review', 'mod_codereview'),
            ['class' => 'btn btn-secondary btn-sm']
        );
    }

    /**
     * Builds a status badge that never relies on colour alone.
     *
     * @param string $kind Either ci or ai.
     * @param string $status The stored status value.
     * @return string
     */
    protected function status_badge(string $kind, string $status): string {
        $map = [
            'pending' => ['secondary', 't/hide'],
            'checking' => ['info', 'i/loading_small'],
            'completed' => ['success', 'i/checked'],
            'nocidetected' => ['warning', 'i/warning'],
            'skipped' => ['secondary', 't/hide'],
            'error' => ['danger', 'i/warning'],
        ];

        [$colour] = $map[$status] ?? ['secondary', 't/hide'];
        $label = get_string($kind . $status, 'mod_codereview');

        return html_writer::span($label, 'badge bg-' . $colour . ' cr-badge-' . $status);
    }
}
