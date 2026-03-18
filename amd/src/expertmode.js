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
 * Expert mode template button for AI Assisted Feedback.
 *
 * When clicked, inserts the admin-configured prompt template into the prompt
 * textarea. If the textarea already has content, a confirmation dialog is shown.
 *
 * @module     assignfeedback_aif/expertmode
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string} from 'core/str';
import Notification from 'core/notification';

/**
 * Initialize the expert mode button.
 *
 * @param {string} template The admin-configured prompt template.
 */
export const init = (template) => {
    const button = document.getElementById('id_assignfeedback_aif_expertmodebtn');
    const promptTextarea = document.getElementById('id_assignfeedback_aif_prompt');

    if (!button || !promptTextarea) {
        return;
    }

    if (button.dataset.listenerAttached) {
        return;
    }
    button.dataset.listenerAttached = 'true';

    button.addEventListener('click', async(e) => {
        e.preventDefault();

        const currentValue = promptTextarea.value.trim();

        if (currentValue) {
            const confirmMessage = await get_string('expertmodeconfirm', 'assignfeedback_aif');
            Notification.confirm(
                await get_string('useexpertmodetemplate', 'assignfeedback_aif'),
                confirmMessage,
                await get_string('yes', 'core'),
                await get_string('no', 'core'),
                () => {
                    insertTemplate(promptTextarea, template);
                }
            );
        } else {
            insertTemplate(promptTextarea, template);
        }
    });
};

/**
 * Insert the template into the textarea.
 *
 * @param {HTMLTextAreaElement} textarea The prompt textarea element.
 * @param {string} template The template text to insert.
 */
const insertTemplate = (textarea, template) => {
    textarea.value = template;
    textarea.dispatchEvent(new Event('input', {bubbles: true}));
    textarea.dispatchEvent(new Event('change', {bubbles: true}));
    textarea.focus();
};
