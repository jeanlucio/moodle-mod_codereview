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
 * Keeps the student's submission status current while checks are running.
 *
 * @module     mod_codereview/status_poll
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';

/** @type {string[]} Statuses that are still going to change on their own. */
const PENDING = ['pending', 'checking'];

/** @type {number} First gap between polls, in milliseconds. */
const FIRST_DELAY = 10000;

/** @type {number} Longest gap between polls, in milliseconds. */
const MAX_DELAY = 60000;

/** @type {number|null} The scheduled poll, so it can be cancelled. */
let timer = null;

/** @type {number} The current gap between polls. */
let delay = FIRST_DELAY;

/**
 * Reads the current status and redraws the panel.
 *
 * @param {HTMLElement} region The status region.
 * @returns {Promise<string>} The status that came back.
 */
const refresh = async(region) => {
    const cmid = parseInt(region.dataset.cmid, 10);

    const status = await Ajax.call([{
        methodname: 'mod_codereview_get_submission_status',
        args: {cmid: cmid},
    }])[0];

    const [cilabel, ailabel] = await Promise.all([
        getString('ci' + status.cistatus, 'mod_codereview'),
        getString('ai' + status.aistatus, 'mod_codereview'),
    ]);

    const context = {
        cmid: cmid,
        hassubmission: status.hassubmission,
        repourl: region.dataset.repourl,
        commitsha: region.dataset.commitsha,
        cistatus: status.cistatus,
        cistatuslabel: cilabel,
        aistatus: status.aistatus,
        aistatuslabel: ailabel,
        gradestatus: status.gradestatus,
        islate: status.islate,
        errormessage: status.errormessage,
        canrecheck: !PENDING.includes(status.cistatus) || status.checkruns.length > 0,
        hascheckruns: status.checkruns.length > 0,
        checkruns: status.checkruns.map((run) => ({
            name: run.name,
            conclusion: run.conclusion,
            passed: run.conclusion === 'success',
            detailsurl: run.detailsurl,
        })),
    };

    const {html, js} = await Templates.renderForPromise('mod_codereview/student_status', context);
    Templates.replaceNode(region, html, js);

    return status.cistatus;
};

/**
 * Schedules the next poll, backing off as the wait grows.
 *
 * Polling costs a request against the site's GitHub quota on the server side, so
 * the gap widens rather than staying at a fixed short interval.
 *
 * @param {string} cistatus The status that came back last.
 */
const scheduleNext = (cistatus) => {
    if (!PENDING.includes(cistatus)) {
        return;
    }

    delay = Math.min(delay * 1.5, MAX_DELAY);
    timer = window.setTimeout(poll, delay);
};

/**
 * Runs one poll cycle.
 */
const poll = async() => {
    const region = document.querySelector('[data-region="codereview-status"]');

    if (!region) {
        return;
    }

    try {
        scheduleNext(await refresh(region));
    } catch (error) {
        // A failed poll says nothing about the submission, so it stops the loop
        // quietly rather than interrupting the student with a dialog.
        window.clearTimeout(timer);
    }
};

/**
 * Asks the server to read the checks again.
 *
 * @param {HTMLElement} button The button that was pressed.
 * @param {HTMLElement} region The status region.
 */
const recheck = async(button, region) => {
    button.disabled = true;

    try {
        await Ajax.call([{
            methodname: 'mod_codereview_recheck_ci',
            args: {cmid: parseInt(region.dataset.cmid, 10), userid: 0},
        }])[0];

        delay = FIRST_DELAY;
        window.clearTimeout(timer);
        timer = window.setTimeout(poll, 3000);
    } catch (error) {
        button.disabled = false;

        // The service refuses this deliberately in known cases, so the message it
        // carries is written to be read. Notification.exception() would show a stack
        // trace meant for a genuine bug.
        const title = await getString('recheckci', 'mod_codereview');
        Notification.alert(title, error.message);
    }
};

export const init = () => {
    const container = document.querySelector('[data-region="codereview-student"]');

    if (!container) {
        return;
    }

    container.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action="recheck"]');
        const region = document.querySelector('[data-region="codereview-status"]');

        if (button && region) {
            event.preventDefault();
            recheck(button, region);
        }
    });

    const region = document.querySelector('[data-region="codereview-status"]');

    if (region && PENDING.includes(region.dataset.cistatus)) {
        timer = window.setTimeout(poll, FIRST_DELAY);
    }
};
