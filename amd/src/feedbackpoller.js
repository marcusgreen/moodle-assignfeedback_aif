// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AMD module that polls for AI feedback completion and reloads the page.
 *
 * Shows a spinner while AI feedback is being generated in the background.
 * Polls the check_feedback_status web service every few seconds and
 * triggers a page reload once feedback is available.
 *
 * @module     assignfeedback_aif/feedbackpoller
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import {add as addToast} from 'core/toast';
import {get_string as getString} from 'core/str';

/** @var {number} POLL_INTERVAL_MS Polling interval in milliseconds. */
const POLL_INTERVAL_MS = 5000;

/** @var {number} MAX_POLLS Maximum number of poll attempts before giving up. */
const MAX_POLLS = 120;

/**
 * Initialize the feedback poller.
 *
 * @param {number} assignmentId The assignment instance id.
 * @param {number} userId The user id to poll feedback for.
 */
export const init = (assignmentId, userId) => {
    let pollCount = 0;
    let timerId = null;

    /**
     * Poll for feedback existence.
     */
    const poll = async() => {
        pollCount++;
        if (pollCount > MAX_POLLS) {
            Log.debug('assignfeedback_aif/feedbackpoller: max polls reached, stopping.');
            stopPolling();
            return;
        }

        try {
            const result = await Ajax.call([{
                methodname: 'assignfeedback_aif_check_feedback_status',
                args: {
                    assignmentid: assignmentId,
                    userid: userId,
                },
            }])[0];

            if (result.feedbackexists) {
                stopPolling();
                window.location.reload();
            }
        } catch (error) {
            Log.debug('assignfeedback_aif/feedbackpoller: poll error, stopping.');
            Log.debug(error);
            stopPolling();
        }
    };

    /**
     * Stop the polling timer.
     */
    const stopPolling = () => {
        if (timerId !== null) {
            clearInterval(timerId);
            timerId = null;
        }
    };

    timerId = setInterval(poll, POLL_INTERVAL_MS);
};

/**
 * Initialize progress polling using the stored_progress API.
 *
 * Used on the summary/overview page to show task progress and update
 * the progress bar in real time. Reloads the page when complete.
 * On error, a retry button is shown so the user can re-queue the task.
 *
 * @param {number} assignmentId The assignment instance id.
 * @param {number} userId The user id.
 * @param {number} progressRecordId The stored_progress DB record ID.
 */
export const initWithProgress = (assignmentId, userId, progressRecordId) => {
    const container = document.querySelector('[data-aif="generating"]');
    if (!container) {
        return;
    }

    const bar = container.querySelector('[data-aif="progress-bar"]');
    const messageEl = container.querySelector('[data-aif="progress-message"]');

    /**
     * Poll the stored_progress API for updates.
     */
    const pollProgress = async() => {
        try {
            const results = await Ajax.call([{
                methodname: 'core_output_poll_stored_progress',
                args: {ids: [progressRecordId]},
            }])[0];

            if (!results || results.length === 0) {
                setTimeout(pollProgress, POLL_INTERVAL_MS);
                return;
            }

            const data = results[0];

            if (bar) {
                bar.style.width = parseFloat(data.progress).toFixed(1) + '%';
            }
            if (messageEl && data.message) {
                messageEl.textContent = data.message;
            }

            if (data.error) {
                if (bar) {
                    bar.classList.add('bg-danger');
                    bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
                    bar.style.width = '100%';
                }
                addRetryButton(container, assignmentId, userId);
                return;
            }

            if (parseFloat(data.progress) >= 100) {
                if (bar) {
                    bar.classList.add('bg-success');
                    bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
                }
                setTimeout(() => window.location.reload(), 1500);
                return;
            }

            setTimeout(pollProgress, POLL_INTERVAL_MS);
        } catch (error) {
            Log.debug('assignfeedback_aif/feedbackpoller: progress poll error.');
            Log.debug(error);
            setTimeout(pollProgress, POLL_INTERVAL_MS);
        }
    };

    pollProgress();
};

/**
 * Add a retry button to a container when feedback generation fails.
 *
 * Creates a button element and appends it to the given container.
 * The button uses data attributes so the delegated click handler
 * from initRetryAll() can process the retry.
 *
 * @param {HTMLElement} container The container to append the retry button to.
 * @param {number} assignmentId The assignment instance id.
 * @param {number} userId The user id.
 */
const addRetryButton = async(container, assignmentId, userId) => {
    const label = await getString('retrygeneration', 'assignfeedback_aif');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-secondary btn-sm mt-2';
    btn.textContent = label;
    btn.dataset.action = 'retry-aif';
    btn.dataset.assignmentid = assignmentId;
    btn.dataset.userid = userId;
    container.appendChild(btn);
    initRetryAll();
};

/**
 * Initialize retry functionality for all retry buttons on the page.
 *
 * Uses event delegation on the document body so it works for both
 * server-rendered retry buttons and dynamically added ones (e.g. when
 * the progress bar detects an error during polling).
 *
 * On click, calls the retry_feedback webservice to re-queue the failed
 * task, then reloads the page so the progress bar is shown again.
 */
export const initRetryAll = () => {
    if (document.body.dataset.aifRetryAttached) {
        return;
    }
    document.body.dataset.aifRetryAttached = 'true';

    document.body.addEventListener('click', async(e) => {
        const button = e.target.closest('[data-action="retry-aif"]');
        if (!button || button.disabled) {
            return;
        }
        e.preventDefault();
        button.disabled = true;

        const assignmentId = parseInt(button.dataset.assignmentid);
        const userId = parseInt(button.dataset.userid);

        try {
            await Ajax.call([{
                methodname: 'assignfeedback_aif_retry_feedback',
                args: {assignmentid: assignmentId, userid: userId},
            }])[0];
            window.location.reload();
        } catch (error) {
            Log.debug('assignfeedback_aif/feedbackpoller: retry failed.');
            Log.debug(error);
            addToast(error.message || String(error), {type: 'danger'});
            button.disabled = false;
        }
    });
};
