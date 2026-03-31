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
 * AMD module for regenerating AI feedback with stored progress tracking.
 *
 * After the teacher confirms regeneration, queues a background task and
 * displays a progress bar that polls the stored_progress API for updates.
 * Automatically reloads the page when feedback generation completes.
 *
 * @module     assignfeedback_aif/regenerate
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {add as addToast} from 'core/toast';
import {get_string as getString} from 'core/str';
import Templates from 'core/templates';
import Pending from 'core/pending';

/** @var {number} Polling interval in milliseconds. */
const POLL_INTERVAL = 5000;

/**
 * Initialize the regenerate button functionality.
 *
 * If runningProgressId is provided (non-zero), immediately shows the progress
 * bar and resumes polling — this handles page reloads during generation.
 *
 * @param {number} assignmentId The assignment instance id.
 * @param {number} userId The user id.
 * @param {number} runningProgressId Stored progress record ID of a running task, or 0.
 */
export const init = (assignmentId, userId, runningProgressId = 0) => {
    const button = document.querySelector('[data-action="regenerate-aif"]');
    if (!button) {
        return;
    }

    // If a task is already running, resume the progress bar immediately.
    if (runningProgressId > 0) {
        resumeProgressBar(button, runningProgressId);
        return;
    }

    if (button.dataset.listenerAttached) {
        return;
    }
    button.dataset.listenerAttached = 'true';

    button.addEventListener('click', async(e) => {
        e.preventDefault();

        // Show confirmation dialog before sending data to AI.
        const confirmMessage = await getString('confirmgeneratefeedback', 'assignfeedback_aif');
        const confirmTitle = await getString('generatefeedbackai', 'assignfeedback_aif');
        Notification.confirm(
            confirmTitle,
            confirmMessage,
            await getString('yes', 'core'),
            await getString('no', 'core'),
            async() => {
                await doRegenerate(button, assignmentId, userId);
            }
        );
    });
};

/**
 * Resume the progress bar on page load when a task is already running.
 *
 * @param {HTMLElement} button The regenerate button element.
 * @param {number} progressRecordId The stored_progress DB record ID.
 */
const resumeProgressBar = async(button, progressRecordId) => {
    const message = await getString('feedbackgenerating', 'assignfeedback_aif');
    await showProgressBar(button, progressRecordId, message);
};

/**
 * Perform the actual regeneration after confirmation.
 *
 * @param {HTMLElement} button The regenerate button element.
 * @param {number} assignmentId The assignment instance id.
 * @param {number} userId The user id.
 */
const doRegenerate = async(button, assignmentId, userId) => {
    const pendingPromise = new Pending('assignfeedback_aif/regenerate');

    // Disable button and show loading state.
    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = await getString('regenerating', 'assignfeedback_aif');

    try {
        const result = await Ajax.call([{
            methodname: 'assignfeedback_aif_regenerate_feedback',
            args: {
                assignmentid: assignmentId,
                userid: userId,
            },
        }])[0];

        if (result.success) {
            addToast(result.message, {type: 'success'});
            await showProgressBar(button, result.progressrecordid, result.message);
        } else {
            addToast(result.message, {type: 'danger'});
            button.disabled = false;
            button.textContent = originalText;
        }
    } catch (error) {
        Notification.exception(error);
        button.disabled = false;
        button.textContent = originalText;
    } finally {
        pendingPromise.resolve();
    }
};

/**
 * Replace the editor area with a progress bar and start polling for updates.
 *
 * Hides the feedback editor and button, renders the progress bar template,
 * then polls the stored_progress API. Reloads the page on completion.
 *
 * @param {HTMLElement} button The regenerate button element.
 * @param {number} progressRecordId The stored_progress DB record ID.
 * @param {string} initialMessage The initial status message.
 */
const showProgressBar = async(button, progressRecordId, initialMessage) => {
    // Hide the editor wrapper.
    const editorElement = document.querySelector('[data-fieldtype="editor"]');
    if (editorElement) {
        const editorWrapper = editorElement.closest('.fitem');
        if (editorWrapper) {
            editorWrapper.style.display = 'none';
        }
    }

    // Hide the button.
    button.style.display = 'none';

    // Render the progress bar template.
    const target = editorElement ? editorElement.closest('.fitem') : button;
    const {html, js} = await Templates.renderForPromise('assignfeedback_aif/feedback_generating', {
        message: initialMessage,
    });
    const container = document.createElement('div');
    container.innerHTML = html;
    target.parentNode.insertBefore(container, target);
    Templates.runTemplateJS(js);

    // Start polling if we have a progress record.
    if (progressRecordId > 0) {
        pollProgress(container, progressRecordId);
    }
};

/**
 * Poll the stored_progress API for updates and update the progress bar.
 *
 * @param {HTMLElement} container The progress bar container element.
 * @param {number} progressRecordId The stored_progress DB record ID.
 */
const pollProgress = async(container, progressRecordId) => {
    const bar = container.querySelector('[data-aif="progress-bar"]');
    const messageEl = container.querySelector('[data-aif="progress-message"]');

    if (!bar || !messageEl) {
        return;
    }

    try {
        const results = await Ajax.call([{
            methodname: 'core_output_poll_stored_progress',
            args: {ids: [progressRecordId]},
        }])[0];

        if (!results || results.length === 0) {
            // Record not found yet, keep polling.
            setTimeout(() => pollProgress(container, progressRecordId), POLL_INTERVAL);
            return;
        }

        const data = results[0];

        // Update the progress bar width and message.
        bar.style.width = parseFloat(data.progress).toFixed(1) + '%';
        if (data.message) {
            messageEl.textContent = data.message;
        }

        if (data.error) {
            // Show error state.
            bar.classList.add('bg-danger');
            bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            bar.style.width = '100%';
            return;
        }

        if (parseFloat(data.progress) >= 100) {
            // Generation complete — show success and reload.
            bar.classList.add('bg-success');
            bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            setTimeout(() => window.location.reload(), 1500);
            return;
        }

        // Continue polling.
        setTimeout(() => pollProgress(container, progressRecordId), POLL_INTERVAL);
    } catch (error) {
        // On network error, keep trying.
        setTimeout(() => pollProgress(container, progressRecordId), POLL_INTERVAL);
    }
};
