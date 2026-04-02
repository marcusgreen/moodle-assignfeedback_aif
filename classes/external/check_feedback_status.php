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

namespace assignfeedback_aif\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core\context\module as context_module;

/**
 * External function to check whether AI feedback exists for a submission.
 *
 * Used by the feedbackpoller JS module to detect when background AI feedback
 * generation has completed and the page should be refreshed.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_feedback_status extends external_api {
    /**
     * Describes the parameters for check_feedback_status.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'The assignment instance id'),
            'userid' => new external_value(
                PARAM_INT,
                'The user id to check feedback for, 0 to check all pending tasks',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Check whether AI feedback exists or generation is still pending.
     *
     * When userid is given, checks whether feedback exists for that user's submission.
     * When userid is 0, checks whether any adhoc tasks are still pending for this assignment.
     *
     * @param int $assignmentid The assignment instance id.
     * @param int $userid The user id, or 0 to check assignment-wide pending status.
     * @return array Result with feedback existence flag.
     */
    public static function execute(int $assignmentid, int $userid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
            'userid' => $userid,
        ]);

        // Get the assignment and validate context.
        $assignment = $DB->get_record('assign', ['id' => $params['assignmentid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assignment->id, $assignment->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        if ($params['userid'] > 0) {
            // Per-user mode: check if feedback exists for a specific user.
            global $USER;
            $canview = ((int) $params['userid'] === (int) $USER->id)
                || has_capability('mod/assign:grade', $context);
            if (!$canview) {
                throw new \required_capability_exception($context, 'mod/assign:grade', 'nopermissions', '');
            }

            $sql = "SELECT aiff.id, aiff.feedback, aiff.feedbackformat
                      FROM {assignfeedback_aif_feedback} aiff
                      JOIN {assignfeedback_aif} aif ON aiff.aif = aif.id
                      JOIN {assign_submission} sub ON aiff.submission = sub.id
                     WHERE aif.assignment = :assignmentid
                       AND sub.userid = :userid
                       AND sub.latest = 1";
            $record = $DB->get_record_sql($sql, [
                'assignmentid' => $params['assignmentid'],
                'userid' => $params['userid'],
            ]);
            $exists = !empty($record);

            // Return the feedback HTML when it exists, so the grading page can
            // inject it into the editor without a full page reload.
            $feedbackhtml = '';
            if ($exists && has_capability('mod/assign:grade', $context)) {
                $format = $record->feedbackformat ?? FORMAT_HTML;
                $feedbackhtml = format_text($record->feedback, $format, ['context' => $context]);
            }

            return [
                'feedbackexists' => $exists,
                'feedbackhtml' => $feedbackhtml,
            ];
        }

        // Assignment-wide mode: check if any adhoc tasks are still pending.
        require_capability('mod/assign:grade', $context);

        $classname = '\\assignfeedback_aif\\task\\process_feedback_adhoc';
        $sql = "SELECT id FROM {task_adhoc} WHERE classname = :classname AND " .
               $DB->sql_like('customdata', ':pattern');
        $pending = $DB->record_exists_sql($sql, [
            'classname' => $classname,
            'pattern' => '%"assignment":' . (int) $params['assignmentid'] . '%',
        ]);

        // The feedbackexists=true item means "done" (no more pending tasks).
        return [
            'feedbackexists' => !$pending,
            'feedbackhtml' => '',
        ];
    }

    /**
     * Describes the return value for check_feedback_status.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'feedbackexists' => new external_value(PARAM_BOOL, 'Whether AI feedback exists for the submission'),
            'feedbackhtml' => new external_value(PARAM_RAW, 'The formatted feedback HTML (only for per-user mode)', VALUE_DEFAULT, ''),
        ]);
    }
}
