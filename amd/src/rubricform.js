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
 * Fill the rubric grading form on the grading page with an applied AI assessment.
 *
 * The adhoc task stores the assessment in the grading instance. When feedback is
 * injected into the editor without a page reload, the rubric widget would still
 * show the old state, so the selected levels and remarks are updated in place.
 *
 * @module     assignfeedback_aif/rubricform
 * @copyright  2026 Jack
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {string} ELEMENT_NAME The advanced grading form element name used by mod_assign. */
const ELEMENT_NAME = 'advancedgrading';

/**
 * Select a level radio and mirror the visual "checked" state the rubric YUI module maintains.
 *
 * @param {HTMLInputElement} radio The level radio input.
 */
const selectLevel = (radio) => {
    radio.checked = true;
    const level = radio.closest('.level');
    if (level && level.parentElement) {
        level.parentElement.querySelectorAll('.level').forEach((sibling) => {
            sibling.classList.remove('checked');
            sibling.setAttribute('aria-checked', 'false');
        });
        level.classList.add('checked');
        level.setAttribute('aria-checked', 'true');
    }
    radio.dispatchEvent(new Event('change', {bubbles: true}));
};

/**
 * Apply an assessment to the rubric form in the current document.
 *
 * @param {object} rubric The rubric structure returned by check_feedback_status.
 * @param {boolean} rubric.applied Whether the adhoc task applied an assessment.
 * @param {Array} rubric.criteria Entries with criterionid, levelid and remark.
 * @returns {number} Number of criteria whose level was selected.
 */
export const applyAssessment = (rubric) => {
    if (!rubric || !rubric.applied || !Array.isArray(rubric.criteria)) {
        return 0;
    }

    let applied = 0;
    rubric.criteria.forEach(({criterionid, levelid, remark}) => {
        const base = `${ELEMENT_NAME}[criteria][${criterionid}]`;

        if (levelid > 0) {
            const radio = document.querySelector(
                `input[type="radio"][name="${base}[levelid]"][value="${levelid}"]`
            );
            if (radio) {
                selectLevel(radio);
                applied++;
            }
        }

        const remarkField = document.querySelector(`textarea[name="${base}[remark]"]`);
        if (remarkField) {
            remarkField.value = remark ?? '';
            remarkField.dispatchEvent(new Event('change', {bubbles: true}));
        }
    });

    return applied;
};
