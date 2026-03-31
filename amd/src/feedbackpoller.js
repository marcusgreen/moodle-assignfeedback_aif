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
