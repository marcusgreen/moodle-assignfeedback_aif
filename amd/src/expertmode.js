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
 * textarea. The {{prompt}} placeholder is pre-replaced with the teacher's
 * current prompt text or a placeholder instruction. If the assignment does
 * not use rubrics, the {{rubric_section}} placeholder is replaced with an empty string.
 *
 * @module     assignfeedback_aif/expertmode
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import Notification from 'core/notification';
import Ajax from 'core/ajax';

/**
 * Initialize the expert mode button.
 *
 * Loads the admin-configured prompt template via AJAX when the button is clicked.
 */
export const init = () => {
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

        let template;
        try {
            const result = await Ajax.call([{
                methodname: 'assignfeedback_aif_get_expert_template',
                args: {},
            }])[0];
            template = result.template;
        } catch (error) {
            Notification.exception(error);
            return;
        }

        const confirmMessage = await getString('expertmodeconfirm', 'assignfeedback_aif');
        const placeholder = await getString('expertmodepromptplaceholder', 'assignfeedback_aif');
        Notification.confirm(
            await getString('useexpertmodetemplate', 'assignfeedback_aif'),
            confirmMessage,
            await getString('yes', 'core'),
            await getString('no', 'core'),
            () => {
                insertTemplate(promptTextarea, template, placeholder);
            }
        );
    });
};

/**
 * Insert the template into the textarea with placeholder replacements.
 *
 * Replaces {{prompt}} with the teacher's current prompt text or a placeholder.
 * Resolves {{rubric_section}} client-side: kept as-is when rubrics are active, cleared otherwise.
 *
 * @param {HTMLTextAreaElement} textarea The prompt textarea element.
 * @param {string} template The template text to insert.
 * @param {string} placeholder The placeholder text for an empty prompt.
 */
const insertTemplate = (textarea, template, placeholder) => {
    let result = template;

    // Replace {{prompt}} with current prompt or placeholder instruction.
    const currentPrompt = textarea.value.trim();
    if (currentPrompt && !currentPrompt.includes('{{submission}}')) {
        result = result.replace('{{prompt}}', currentPrompt);
    } else {
        result = result.replace('{{prompt}}', placeholder);
    }

    // Clear {{rubric_section}} if assignment does not use rubric grading.
    const gradingMethodSelect = document.getElementById('id_advancedgradingmethod_submissions');
    const hasRubric = gradingMethodSelect && gradingMethodSelect.value === 'rubric';
    if (!hasRubric) {
        result = result.replace(/\{\{rubric_section\}\}\n?\n?/g, '');
    }

    textarea.value = result;
    textarea.dispatchEvent(new Event('input', {bubbles: true}));
    textarea.dispatchEvent(new Event('change', {bubbles: true}));
    textarea.focus();
};
