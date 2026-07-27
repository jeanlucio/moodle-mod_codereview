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

/**
 * Actions on the teacher review screen.
 *
 * @module     mod_codereview/review_page
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

/**
 * Queues a fresh AI review for the submission.
 *
 * @param {HTMLElement} button The button that was pressed.
 */
const rerunAiReview = async(button) => {
    const submissionid = parseInt(button.dataset.submissionid, 10);

    button.disabled = true;

    try {
        await Ajax.call([{
            methodname: 'mod_codereview_rerun_ai_review',
            args: {submissionid: submissionid},
        }])[0];

        window.location.reload();
    } catch (error) {
        button.disabled = false;

        // The service throws a deliberate exception for anticipated refusals, so the
        // message it carries is meant to be read. Notification.exception() is for
        // genuine bugs and would show a stack trace instead.
        const title = await getString('rerunaireview', 'mod_codereview');
        Notification.alert(title, error.message);
    }
};

export const init = () => {
    const region = document.querySelector('[data-region="codereview-review"]');

    if (!region) {
        return;
    }

    region.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action="rerun-ai"]');

        if (button) {
            event.preventDefault();
            rerunAiReview(button);
        }
    });
};
