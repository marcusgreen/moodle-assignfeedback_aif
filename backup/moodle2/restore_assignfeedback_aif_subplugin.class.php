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
 * Restore subplugin class for the assignfeedback_aif plugin.
 *
 * @package   assignfeedback_aif
 * @copyright 2026 ISB Bayern
 * @author    Dr. Peter Mayer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore subplugin class for assignfeedback_aif.
 *
 * Restores the per-assignment configuration, per-submission feedback records
 * and editor files for the AI Feedback plugin.
 *
 * @package   assignfeedback_aif
 * @copyright 2026 ISB Bayern
 * @author    Dr. Peter Mayer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_assignfeedback_aif_subplugin extends restore_subplugin {

    /**
     * Returns the paths handled by the subplugin at grade level.
     *
     * @return array
     */
    protected function define_grade_subplugin_structure() {
        $paths = [];

        $paths[] = new restore_path_element(
            $this->get_namefor('config'),
            $this->get_pathfor('/feedback_aif_config')
        );
        $paths[] = new restore_path_element(
            $this->get_namefor('feedback'),
            $this->get_pathfor('/feedback_aif')
        );
        $paths[] = new restore_path_element(
            $this->get_namefor('files'),
            $this->get_pathfor('/feedback_aif_files')
        );

        return $paths;
    }

    /**
     * Process a feedback_aif_config element.
     *
     * The config row is emitted once per grade during backup; this method
     * de-duplicates the inserts so only one row per assignment is created.
     *
     * @param array|object $data The element data.
     */
    public function process_assignfeedback_aif_config($data) {
        global $DB;

        $data = (object) $data;
        $assignmentid = $this->get_new_parentid('assign');
        if (empty($assignmentid)) {
            return;
        }

        // Only insert the config once per assignment.
        if ($DB->record_exists('assignfeedback_aif', ['assignment' => $assignmentid])) {
            return;
        }

        $record = (object) [
            'assignment' => $assignmentid,
            'prompt' => $data->prompt ?? null,
            'autogenerate' => $data->autogenerate ?? 0,
            'timecreated' => $data->timecreated ?? 0,
        ];
        $DB->insert_record('assignfeedback_aif', $record);
    }

    /**
     * Process a feedback_aif element (per-submission feedback).
     *
     * @param array|object $data The element data.
     */
    public function process_assignfeedback_aif_feedback($data) {
        global $DB;

        $data = (object) $data;
        $assignmentid = $this->get_new_parentid('assign');
        $newgradeid = $this->get_new_parentid('grade');
        if (empty($assignmentid) || empty($newgradeid)) {
            return;
        }

        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if (!$aif) {
            return;
        }

        $grade = $DB->get_record('assign_grades', ['id' => $newgradeid]);
        if (!$grade) {
            return;
        }

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $grade->assignment,
            'userid' => $grade->userid,
            'latest' => 1,
        ]);
        if (!$submission) {
            return;
        }

        $record = (object) [
            'aif' => $aif->id,
            'submission' => $submission->id,
            'feedback' => $data->feedback ?? null,
            'feedbackformat' => $data->feedbackformat ?? FORMAT_HTML,
            'timemodified' => $data->timemodified ?? 0,
            'timecreated' => $data->timecreated ?? 0,
            'skippedfiles' => $data->skippedfiles ?? null,
        ];
        $DB->insert_record('assignfeedback_aif_feedback', $record);
    }

    /**
     * Process a feedback_aif_files element and restore associated files.
     *
     * @param array|object $data The element data.
     */
    public function process_assignfeedback_aif_files($data) {
        $data = (object) $data;
        $oldgradeid = $data->gradeid;

        // The grade mapping is set by the core assign restore when a grade node is processed.
        $this->add_related_files(
            'assignfeedback_aif',
            'assignfeedback_aif_feedback',
            'grade',
            null,
            $oldgradeid
        );
    }
}
