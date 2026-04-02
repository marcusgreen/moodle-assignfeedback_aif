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

/** @var {number} Stored assignment ID for feedback fetching. */
let storedAssignmentId = 0;

/** @var {number} Stored user ID for feedback fetching. */
let storedUserId = 0;

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
    storedAssignmentId = assignmentId;
    storedUserId = userId;
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
        await showConfirmationWithAnalysis(button, assignmentId, userId);
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
 * Fetch submission analysis and show a confirmation dialog with file details.
 *
 * Calls the get_submission_analysis webservice to list which files can be
 * processed and which will be skipped, then presents the information in
 * the confirmation dialog so the teacher can make an informed decision.
 *
 * @param {HTMLElement} button The regenerate button element.
 * @param {number} assignmentId The assignment instance id.
 * @param {number} userId The user id.
 */
const showConfirmationWithAnalysis = async(button, assignmentId, userId) => {
    const confirmTitle = await getString('generatefeedbackai', 'assignfeedback_aif');

    let confirmMessage = '';
    try {
        const analysis = await Ajax.call([{
            methodname: 'assignfeedback_aif_get_submission_analysis',
            args: {assignmentid: assignmentId, userid: userId},
        }])[0];

        confirmMessage = await buildAnalysisMessage(analysis);
    } catch {
        // Fall back to generic message if analysis fails.
        confirmMessage = await getString('confirmgeneratefeedback', 'assignfeedback_aif');
    }

    Notification.confirm(
        confirmTitle,
        confirmMessage,
        await getString('yes', 'core'),
        await getString('no', 'core'),
        async() => {
            await doRegenerate(button, assignmentId, userId);
        }
    );
};

/**
 * Build a human-readable confirmation message from the submission analysis.
 *
 * @param {object} analysis The analysis result from the webservice.
 * @returns {string} HTML message for the confirmation dialog.
 */
const buildAnalysisMessage = async(analysis) => {
    let message = await getString('confirmgeneratefeedback', 'assignfeedback_aif');
    message += '<div class="mt-3">';

    if (analysis.hasonlinetext) {
        const label = await getString('analysisonlinetext', 'assignfeedback_aif');
        message += '<div class="text-success"><i class="fa fa-check mr-1"></i>' + label + '</div>';
    }

    if (analysis.processablefiles.length > 0) {
        const label = await getString('analysisprocessablefiles', 'assignfeedback_aif');
        message += '<div class="mt-2 font-weight-bold">' + label + '</div><ul class="mb-1">';
        for (const f of analysis.processablefiles) {
            message += '<li class="text-success"><i class="fa fa-check mr-1"></i>' + f.filename + '</li>';
        }
        message += '</ul>';
    }

    if (analysis.skippedfiles.length > 0) {
        const label = await getString('analysisskippedfiles', 'assignfeedback_aif');
        message += '<div class="mt-2 font-weight-bold text-warning">' + label + '</div><ul class="mb-1">';
        for (const f of analysis.skippedfiles) {
            message += '<li class="text-warning"><i class="fa fa-exclamation-triangle mr-1"></i>'
                + f.filename + ' <small class="text-muted">(' + f.reason + ')</small></li>';
        }
        message += '</ul>';
    }

    if (!analysis.hasonlinetext && analysis.processablefiles.length === 0 && analysis.skippedfiles.length === 0) {
        const label = await getString('analysisnosubmission', 'assignfeedback_aif');
        message += '<div class="text-warning"><i class="fa fa-exclamation-triangle mr-1"></i>' + label + '</div>';
    }

    message += '</div>';
    return message;
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
            // Show error state: red bar and prominent error message.
            bar.classList.add('bg-danger');
            bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            bar.style.width = '100%';
            if (data.message) {
                messageEl.classList.remove('text-muted');
                messageEl.classList.add('text-danger', 'font-weight-bold');
                // Render newlines as line breaks for multi-error messages.
                messageEl.innerHTML = '';
                const lines = data.message.split('\n');
                lines.forEach((line, i) => {
                    messageEl.appendChild(document.createTextNode(line));
                    if (i < lines.length - 1) {
                        messageEl.appendChild(document.createElement('br'));
                    }
                });
            }
            // Restore the button so the teacher can retry.
            const btn = document.querySelector('[data-action="regenerate-aif"]');
            if (btn) {
                btn.style.display = '';
                btn.disabled = false;
            }
            return;
        }

        if (parseFloat(data.progress) >= 100) {
            // Generation complete — fetch the feedback and inject into editor.
            bar.classList.add('bg-success');
            bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            await injectFeedbackIntoEditor(container);
            return;
        }

        // Continue polling.
        setTimeout(() => pollProgress(container, progressRecordId), POLL_INTERVAL);
    } catch (error) {
        // On network error, keep trying.
        setTimeout(() => pollProgress(container, progressRecordId), POLL_INTERVAL);
    }
};

/**
 * Fetch generated feedback and inject it into the TinyMCE editor.
 *
 * Instead of reloading the page (which would lose unsaved grade data),
 * fetches the feedback via webservice and sets it in the editor directly.
 *
 * @param {HTMLElement} container The progress bar container element.
 */
const injectFeedbackIntoEditor = async(container) => {
    try {
        const result = await Ajax.call([{
            methodname: 'assignfeedback_aif_check_feedback_status',
            args: {
                assignmentid: storedAssignmentId,
                userid: storedUserId,
            },
        }])[0];

        if (result.feedbackexists && result.feedbackhtml) {
            // Find the TinyMCE editor for the AIF feedback field.
            const editorElement = document.querySelector('[data-fieldtype="editor"]');
            const editorWrapper = editorElement ? editorElement.closest('.fitem') : null;
            const textarea = document.getElementById('id_assignfeedbackaif_editor');

            if (textarea) {
                // Set the textarea value (raw HTML).
                textarea.value = result.feedbackhtml;
                textarea.dispatchEvent(new Event('change', {bubbles: true}));

                // If TinyMCE is active, update its content too.
                // TinyMCE 6 in Moodle uses a data-fieldtype attribute on the wrapper.
                const tinyFrame = editorElement?.querySelector('iframe');
                if (tinyFrame && tinyFrame.contentDocument) {
                    tinyFrame.contentDocument.body.innerHTML = result.feedbackhtml;
                }
            }

            // Remove progress bar and restore the editor.
            container.remove();
            if (editorWrapper) {
                editorWrapper.style.display = '';
            }

            // Restore the button.
            const button = document.querySelector('[data-action="regenerate-aif"]');
            if (button) {
                button.style.display = '';
                button.disabled = false;
                button.textContent = await getString('generatefeedbackai', 'assignfeedback_aif');
            }

            addToast(await getString('feedbackgenerationcomplete', 'assignfeedback_aif'), {type: 'success'});
        } else {
            // Feedback not available yet — fall back to reload.
            setTimeout(() => window.location.reload(), 1500);
        }
    } catch {
        // On error, fall back to reload.
        setTimeout(() => window.location.reload(), 1500);
    }
};
