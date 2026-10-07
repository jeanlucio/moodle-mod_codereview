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
 * Runs one of the manual actions and reloads the page to show the outcome.
 *
 * The service runs the work inside the request, so the page waits. The button says so
 * while it does, and a failure the service reports as an exception (another run still
 * going, no permission) is shown as the message it carries.
 *
 * @param {HTMLElement} button The button that was pressed.
 * @param {Object} call The web service call to make.
 * @param {string} titlekey The string key for the alert title.
 */
const runManually = async(button, call, titlekey) => {
    const label = button.textContent;

    button.disabled = true;
    button.textContent = await getString('runningnow', 'mod_codereview');

    try {
        await Ajax.call([call])[0];

        window.location.reload();
    } catch (error) {
        button.disabled = false;
        button.textContent = label;

        // The service throws a deliberate exception for anticipated refusals, so the
        // message it carries is meant to be read. Notification.exception() is for
        // genuine bugs and would show a stack trace instead.
        const title = await getString(titlekey, 'mod_codereview');
        Notification.alert(title, error.message);
    }
};

export const init = () => {
    const region = document.querySelector('[data-region="codereview-review"]');

    if (!region) {
        return;
    }

    region.addEventListener('click', (event) => {
        const rerun = event.target.closest('[data-action="rerun-ai"]');
        if (rerun) {
            event.preventDefault();
            runManually(rerun, {
                methodname: 'mod_codereview_rerun_ai_review',
                args: {submissionid: parseInt(rerun.dataset.submissionid, 10)},
            }, 'rerunaireview');

            return;
        }

        const recheck = event.target.closest('[data-action="recheck-ci"]');
        if (recheck) {
            event.preventDefault();
            runManually(recheck, {
                methodname: 'mod_codereview_recheck_ci',
                args: {
                    cmid: parseInt(region.dataset.cmid, 10),
                    userid: parseInt(recheck.dataset.userid, 10),
                },
            }, 'recheckci');
        }
    });
};
