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
 * The adhoc task stores the assessment in the grading instance and keeps the grade
 * "In review". When feedback is injected into the editor without a page reload, the
 * rubric widget and the marking workflow field would still show the old state, so
 * the selected levels, the remarks and the workflow state are updated in place.
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
 * Clear the level selection of a criterion, including the widget's visual state.
 *
 * @param {NodeList} radios The level radios of the criterion.
 */
const clearLevels = (radios) => {
    radios.forEach((radio) => {
        radio.checked = false;
        const level = radio.closest('.level');
        if (level) {
            level.classList.remove('checked');
            level.setAttribute('aria-checked', 'false');
        }
    });
};

/**
 * Select the marking workflow state the task left the grade in.
 *
 * Without this, saving the form would post the state from page load (e.g. "Not marked")
 * over the "In review" the task has set. The select is missing when marking workflow is
 * off or when core froze it, and the option is missing when the grader may not set this
 * state; in both cases the field is left alone.
 *
 * @param {string} state The workflow state, e.g. "inreview".
 * @returns {boolean} Whether the state is selected now.
 */
const selectWorkflowState = (state) => {
    if (!state) {
        return false;
    }
    const select = document.querySelector('select[name="workflowstate"]');
    if (!select || select.disabled || !select.querySelector(`option[value="${state}"]`)) {
        return false;
    }
    if (select.value !== state) {
        select.value = state;
        select.dispatchEvent(new Event('change', {bubbles: true}));
    }
    return true;
};

/**
 * Apply an assessment to the rubric form in the current document.
 *
 * @param {object} rubric The rubric structure returned by check_feedback_status.
 * @param {boolean} rubric.applied Whether the adhoc task applied an assessment.
 * @param {Array} rubric.criteria Entries with criterionid, levelid and remark.
 * @param {string} [rubric.workflowstate] Marking workflow state of the grade, if any.
 * @returns {number} Number of criteria whose level was selected.
 */
export const applyAssessment = (rubric) => {
    if (!rubric || !rubric.applied || !Array.isArray(rubric.criteria)) {
        return 0;
    }

    let applied = 0;
    rubric.criteria.forEach(({criterionid, levelid, remark}) => {
        const base = `${ELEMENT_NAME}[criteria][${criterionid}]`;
        const radios = document.querySelectorAll(`input[type="radio"][name="${base}[levelid]"]`);

        if (levelid > 0) {
            const radio = Array.from(radios).find((candidate) => candidate.value === String(levelid));
            if (radio) {
                selectLevel(radio);
                applied++;
            }
        } else {
            // The stored filling has no level for this criterion: mirror that as well, so the
            // form does not keep a selection the task did not make.
            clearLevels(radios);
        }

        const remarkField = document.querySelector(`textarea[name="${base}[remark]"]`);
        if (remarkField) {
            remarkField.value = remark ?? '';
            remarkField.dispatchEvent(new Event('change', {bubbles: true}));
        }
    });

    selectWorkflowState(rubric.workflowstate);

    return applied;
};
