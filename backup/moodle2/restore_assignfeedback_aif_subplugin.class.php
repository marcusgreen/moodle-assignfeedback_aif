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
 * Restore subplugin class for assignfeedback_aif.
 *
 * @package   assignfeedback_aif
 * @copyright 2026 ISB Bayern
 * @author    Dr. Peter Mayer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
    /** @var array Diagnostic trace for CI debugging; reset per test. */
    public static array $trace = [];

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
        self::$trace[] = ['handler' => 'config', 'data' => (array) $data];
        $assignmentid = $this->get_new_parentid('assign');
        if (empty($assignmentid)) {
            self::$trace[] = ['handler' => 'config', 'exit' => 'no assignmentid'];
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
        self::$trace[] = ['handler' => 'feedback', 'data' => (array) $data];
        $assignmentid = $this->get_new_parentid('assign');
        if (empty($assignmentid)) {
            self::$trace[] = ['handler' => 'feedback', 'exit' => 'no assignmentid'];
            return;
        }

        // Map the source grade id to the new grade id via the core grade mapping.
        $oldgradeid = (int) ($data->oldgradeid ?? 0);
        $newgradeid = $this->get_mappingid('grade', $oldgradeid);
        if (empty($newgradeid)) {
            self::$trace[] = ['handler' => 'feedback', 'exit' => 'no grade mapping', 'oldgradeid' => $oldgradeid];
            return;
        }

        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if (!$aif) {
            // The separate config element was not processed (for example when
            // the backup was produced before the config element existed, or
            // when that path failed to match). Create the row from the
            // config data embedded in the feedback element.
            $aif = (object) [
                'assignment' => $assignmentid,
                'prompt' => $data->configprompt ?? null,
                'autogenerate' => $data->configautogenerate ?? 0,
                'timecreated' => $data->configtimecreated ?? 0,
            ];
            $aif->id = $DB->insert_record('assignfeedback_aif', $aif);
        }

        $grade = $DB->get_record('assign_grades', ['id' => $newgradeid]);
        if (!$grade) {
            self::$trace[] = ['handler' => 'feedback', 'exit' => 'no grade row', 'newgradeid' => $newgradeid];
            return;
        }

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $grade->assignment,
            'userid' => $grade->userid,
            'latest' => 1,
        ]);
        if (!$submission) {
            self::$trace[] = ['handler' => 'feedback', 'exit' => 'no submission', 'grade' => (array) $grade];
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
        $newid = $DB->insert_record('assignfeedback_aif_feedback', $record);
        self::$trace[] = ['handler' => 'feedback', 'inserted' => $newid, 'record' => (array) $record];

        // Restore any editor files for this grade's feedback area.
        $this->add_related_files(
            'assignfeedback_aif',
            'assignfeedback_aif_feedback',
            'grade',
            null,
            $oldgradeid
        );
    }
}
