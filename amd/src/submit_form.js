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
 * Sends the student's submission without losing the page.
 *
 * The form still posts normally when this module does not load, so the activity
 * keeps working without JavaScript; everything here is an enhancement on top.
 *
 * @module     mod_codereview/submit_form
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';

/** @type {RegExp} The repository URLs the server will accept. */
const REPO_PATTERN = /^https:\/\/github\.com\/[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+(\.git)?\/?$/;

/** @type {RegExp} A full commit hash. */
const SHA_PATTERN = /^[0-9a-fA-F]{40}$/;

/**
 * Shows a message above the form.
 *
 * @param {HTMLElement} container The submit region.
 * @param {string} message The text to show.
 */
const showError = (container, message) => {
    const target = container.querySelector('[data-region="codereview-submit-errors"]');

    if (target) {
        target.innerHTML = '';
        const alert = document.createElement('div');
        alert.className = 'cr-notice cr-notice-warning';
        alert.textContent = message;
        target.appendChild(alert);
    }
};

/**
 * Checks the two fields against the same shapes the server enforces.
 *
 * This only saves a round trip: the server validates independently, because
 * anything checked solely in the browser is not checked at all.
 *
 * @param {string} repourl The repository URL.
 * @param {string} commitsha The commit hash.
 * @returns {Promise<string>} An error message, or an empty string when both look right.
 */
const validate = async(repourl, commitsha) => {
    if (!REPO_PATTERN.test(repourl.trim())) {
        return getString('errorinvalidrepourl', 'mod_codereview');
    }

    if (!SHA_PATTERN.test(commitsha.trim())) {
        return getString('errorinvalidcommitsha', 'mod_codereview');
    }

    return '';
};

/**
 * Sends the submission and reloads so the status panel reflects it.
 *
 * @param {HTMLFormElement} form The submission form.
 * @param {HTMLElement} container The submit region.
 */
const submit = async(form, container) => {
    const repourl = form.querySelector('[name="repourl"]').value;
    const commitsha = form.querySelector('[name="commitsha"]').value;
    const button = form.querySelector('[type="submit"]');

    const error = await validate(repourl, commitsha);

    if (error) {
        showError(container, error);

        return;
    }

    button.disabled = true;

    try {
        await Ajax.call([{
            methodname: 'mod_codereview_submit_repo',
            args: {
                cmid: parseInt(container.dataset.cmid, 10),
                repourl: repourl.trim(),
                commitsha: commitsha.trim(),
            },
        }])[0];

        window.location.reload();
    } catch (exception) {
        button.disabled = false;

        // Every refusal the server raises here is a case it anticipated and worded
        // for the student: a private repository, an unknown commit, a passed cut-off.
        showError(container, exception.message);
    }
};

export const init = () => {
    const container = document.querySelector('[data-region="codereview-submit"]');

    if (!container) {
        return;
    }

    const form = container.querySelector('form');

    if (!form) {
        return;
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        submit(form, container);
    });
};
