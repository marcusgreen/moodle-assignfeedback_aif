/**
 * Repositions the ai_manager warning box before the feedback toggle.
 *
 * The warning box is rendered inside the feedback summary box by view_summary().
 * This module moves it before the summary box so it remains visible regardless
 * of the expand/collapse state of the AI feedback.
 *
 * @module     assignfeedback_aif/warningbox_position
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise the warningbox repositioning.
 *
 * @param {string} selector The CSS selector for the warningbox element.
 */
export const init = (selector) => {
    const warningEl = document.querySelector(selector);
    if (!warningEl) {
        return;
    }
    const summaryBox = warningEl.closest('.plugincontentsummary');
    if (!summaryBox) {
        return;
    }
    summaryBox.parentNode.insertBefore(warningEl, summaryBox);
};
