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
import {get_string as getString} from 'core/str';
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
                Notification.addNotification({
                    message: result.message,
                    type: 'success',
                });
            } else {
                Notification.addNotification({
                    message: result.message,
                    type: 'error',
                });
            }
        } catch (error) {
            Notification.exception(error);
        } finally {
            button.disabled = false;
            button.textContent = originalText;
            pendingPromise.resolve();
        }
    });
};
