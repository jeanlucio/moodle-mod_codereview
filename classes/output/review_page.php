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

use renderable;
use renderer_base;
use templatable;

/**
 * The teacher review screen.
 *
 * Takes the payload the review service assembled and works out what the template
 * needs to decide with. Keeping that here rather than inside the renderer is what
 * lets it be asserted as an array instead of by reading rendered HTML.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_page implements renderable, templatable {
    /** @var array The payload from review_service::get_review_data(). */
    protected array $data;

    /** @var int The course module id, for the action buttons. */
    protected int $cmid;

    /**
     * Constructor.
     *
     * @param array $data The payload from review_service::get_review_data().
     * @param int $cmid The course module id.
     */
    public function __construct(array $data, int $cmid) {
        $this->data = $data;
        $this->cmid = $cmid;
    }

    /**
     * Builds the template context.
     *
     * @param renderer_base $output The renderer, unused.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $data = $this->data;
        $airesult = $data['airesult'] ?? null;

        $context = $data;
        $context['cmid'] = $this->cmid;
        $context['sesskey'] = sesskey();

        $context['hascheckruns'] = !empty($data['checkruns']);
        $context['hasairesult'] = $airesult !== null;
        $context['aisucceeded'] = ($airesult['status'] ?? '') === 'completed';
        $context['hasflags'] = !empty($data['flags']);
        $context['hassuggestion'] = ($data['suggestedgrade'] ?? null) !== null;

        $context['suggestedgradeformatted'] = $context['hassuggestion']
            ? format_float((float) $data['suggestedgrade'], 2)
            : '';

        $aigrade = $airesult['suggestedgrade'] ?? null;
        $context['aigradeformatted'] = $aigrade !== null ? format_float((float) $aigrade, 2) : '';

        $context['commitdateformatted'] = !empty($data['commitauthordate'])
            ? userdate($data['commitauthordate'])
            : '';
        $context['submitteddateformatted'] = userdate($data['timecreated']);

        $context['cistatuslabel'] = get_string('ci' . $data['cistatus'], 'mod_codereview');
        $context['aistatuslabel'] = get_string('ai' . $data['aistatus'], 'mod_codereview');

        foreach ($context['flags'] as $index => $flag) {
            $context['flags'][$index]['severitylabel'] = get_string('severity' . $flag['severity'], 'mod_codereview');
            $context['flags'][$index]['ishigh'] = $flag['severity'] === 'high';
        }

        return $context;
    }
}
