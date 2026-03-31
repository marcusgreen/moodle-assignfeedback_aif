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
 * AMD module for regenerating AI feedback.
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

/**
 * Initialize the regenerate button functionality.
 *
 * @param {number} assignmentId The assignment instance id.
 * @param {number} userId The user id.
 */
export const init = (assignmentId, userId) => {
    const button = document.querySelector('[data-action="regenerate-aif"]');
    if (!button) {
        return;
    }

    if (button.dataset.listenerAttached) {
        return;
    }
    button.dataset.listenerAttached = 'true';

    button.addEventListener('click', async(e) => {
        e.preventDefault();

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
                clearEditorAndShowSpinner(button, assignmentId, userId);
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
    });
};

/**
 * Clear the feedback editor, hide the form elements and show a generating spinner.
 *
 * After successful regeneration queue, replaces the editor area with the
 * feedback_generating template and starts the feedbackpoller to auto-reload
 * once the new feedback is ready.
 *
 * @param {HTMLElement} button The regenerate button element.
 * @param {number} assignmentId The assignment instance id.
 * @param {number} userId The user id.
 */
const clearEditorAndShowSpinner = async(button, assignmentId, userId) => {
    // Find the editor wrapper — the form group containing the editor.
    const editorElement = document.querySelector('[data-fieldtype="editor"]');
    if (editorElement) {
        const editorWrapper = editorElement.closest('.fitem');
        if (editorWrapper) {
            editorWrapper.style.display = 'none';
        }
    }

    // Hide the regenerate button.
    button.style.display = 'none';

    // Render the spinner template after the hidden editor.
    const target = editorElement ? editorElement.closest('.fitem') : button;
    const {html, js} = await Templates.renderForPromise('assignfeedback_aif/feedback_generating', {});
    const container = document.createElement('div');
    container.innerHTML = html;
    target.parentNode.insertBefore(container, target);
    Templates.runTemplateJS(js);

    // Start polling for the new feedback.
    const poller = await import('assignfeedback_aif/feedbackpoller');
    poller.init(assignmentId, userId);
};
