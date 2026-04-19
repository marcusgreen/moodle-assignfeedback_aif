<?php
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
 * Backup subplugin class for assignfeedback_aif.
 *
 * @package   assignfeedback_aif
 * @copyright 2026 ISB Bayern
 * @author    Dr. Peter Mayer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Provides the information required to backup the assignfeedback_aif subplugin.
 *
 * The plugin stores data in two tables:
 * - assignfeedback_aif (per-assignment config: prompt, autogenerate)
 * - assignfeedback_aif_feedback (per-submission AI-generated feedback)
 *
 * mod_assign only exposes a grade-level backup hook for feedback subplugins,
 * so the per-assignment config is emitted alongside each grade and de-duplicated
 * on restore. Each feedback element includes the old grade id so the restore
 * can resolve the mapping via get_mappingid('grade', ...).
 *
 * @package   assignfeedback_aif
 * @copyright 2026 ISB Bayern
 * @author    Dr. Peter Mayer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_assignfeedback_aif_subplugin extends backup_subplugin {
    /**
     * Returns the subplugin information to attach to the grade element.
     *
     * @return backup_subplugin_element
     */
    protected function define_grade_subplugin_structure() {

        // Create XML elements.
        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());

        // Per-assignment config (emitted per grade; restore deduplicates).
        $config = new backup_nested_element('feedback_aif_config', null, [
            'prompt',
            'autogenerate',
            'timecreated',
        ]);

        // Per-submission feedback record. Includes the old grade id so the
        // restore handler can resolve the new grade id via the grade mapping.
        $feedback = new backup_nested_element('feedback_aif', null, [
            'grade',
            'feedback',
            'feedbackformat',
            'timemodified',
            'timecreated',
            'skippedfiles',
        ]);

        // Connect XML elements into the tree.
        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($config);
        $subpluginwrapper->add_child($feedback);

        // Config source: look up by the grade's assignment.
        $config->set_source_sql(
            'SELECT aif.prompt, aif.autogenerate, aif.timecreated
               FROM {assignfeedback_aif} aif
               JOIN {assign_grades} g ON g.assignment = aif.assignment
              WHERE g.id = :gradeid',
            ['gradeid' => backup::VAR_PARENTID]
        );

        // Feedback source: join via the grade's user latest submission.
        // The "grade" column captures the source grade id so the restore handler
        // can remap it to the new grade id via the grade mapping.
        $feedback->set_source_sql(
            'SELECT :parentgradeid AS grade, aiff.feedback, aiff.feedbackformat,
                    aiff.timemodified, aiff.timecreated, aiff.skippedfiles
               FROM {assignfeedback_aif_feedback} aiff
               JOIN {assignfeedback_aif} aif ON aif.id = aiff.aif
               JOIN {assign_submission} s ON s.id = aiff.submission
               JOIN {assign_grades} g ON g.assignment = aif.assignment AND g.userid = s.userid
              WHERE g.id = :gradeid AND s.latest = 1',
            [
                'gradeid' => backup::VAR_PARENTID,
                'parentgradeid' => backup::VAR_PARENTID,
            ]
        );

        // Annotate files stored in the editor filearea (itemid = grade id).
        $feedback->annotate_files(
            'assignfeedback_aif',
            'assignfeedback_aif_feedback',
            'grade'
        );

        return $subplugin;
    }
}
