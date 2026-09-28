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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core\context\module as context_module;
use core\output\stored_progress_bar;
use assignfeedback_aif\aif;
use assignfeedback_aif\task\process_feedback_adhoc;

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
        require_capability('assignfeedback/aif:viewstatus', $context);

        if ($params['userid'] > 0) {
            // Per-user mode: check if feedback exists for a specific user.
            global $USER;
            $canview = ((int) $params['userid'] === (int) $USER->id)
                || has_capability('mod/assign:grade', $context);
            if (!$canview) {
                throw new \required_capability_exception($context, 'mod/assign:grade', 'nopermissions', '');
            }

            $sql = "SELECT aiff.id, aiff.feedback, aiff.feedbackformat, aiff.timecreated, aiff.timemodified
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
            $rubric = ['applied' => false, 'criteria' => []];
            if ($exists && has_capability('mod/assign:grade', $context)) {
                $format = $record->feedbackformat ?? FORMAT_HTML;
                $feedbackhtml = format_text($record->feedback, $format, ['context' => $context]);
                $rubric = self::load_rubric_assessment(
                    $assignment,
                    $cm,
                    $context,
                    $params['userid'],
                    max((int) $record->timemodified, (int) $record->timecreated)
                );
            }

            // Look up stored_progress for a running adhoc task so the client can
            // switch from simple existence polling to real progress polling.
            $progressrecordid = 0;
            if (!$exists) {
                $progressrecordid = self::find_progress_record(
                    $params['assignmentid'],
                    $params['userid']
                );
            }

            return [
                'feedbackexists' => $exists,
                'feedbackhtml' => $feedbackhtml,
                'progressrecordid' => $progressrecordid,
                'rubric' => $rubric,
            ];
        }

        // Assignment-wide mode: check if any adhoc tasks are still pending.
        require_capability('mod/assign:grade', $context);

        $taskclass = \assignfeedback_aif\task\process_feedback_adhoc::class;
        $tasks = \core\task\manager::get_adhoc_tasks($taskclass);
        $pendingorrunning = false;
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            if (isset($data->assignment) && (int) $data->assignment === (int) $params['assignmentid']) {
                $pendingorrunning = true;
                break;
            }
        }

        // The feedbackexists=true item means "done" (no more pending tasks).
        return [
            'feedbackexists' => !$pendingorrunning,
            'feedbackhtml' => '',
            'progressrecordid' => 0,
            'rubric' => ['applied' => false, 'criteria' => []],
        ];
    }

    /**
     * Load the rubric filling the adhoc task applied together with the current feedback.
     *
     * Reads the grading instance rather than the raw AI output so the client shows
     * exactly what was stored after criterion and level matching. The filling is only
     * reported when rubric application is enabled site-wide and for this assignment,
     * and when the instance was written at or after the feedback text. An older
     * instance belongs to a previous run (or to the teacher) and is left alone.
     *
     * @param \stdClass $assignment The assign record.
     * @param \stdClass $cm The course module record.
     * @param context_module $context The module context.
     * @param int $userid The graded student.
     * @param int $since Timestamp of the feedback text; earlier instances are ignored.
     * @return array 'applied' flag and a list of criterionid / levelid / remark entries.
     */
    private static function load_rubric_assessment(
        \stdClass $assignment,
        \stdClass $cm,
        context_module $context,
        int $userid,
        int $since
    ): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $empty = ['applied' => false, 'criteria' => []];
        if (!aif::is_rubric_application_enabled()) {
            return $empty;
        }
        if (!$DB->get_field('assignfeedback_aif', 'applyrubricgrades', ['assignment' => $assignment->id])) {
            return $empty;
        }

        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        if ($gradingmanager->get_active_method() !== aif::GRADING_METHOD_RUBRIC) {
            return $empty;
        }
        $controller = $gradingmanager->get_controller(aif::GRADING_METHOD_RUBRIC);
        if (!$controller->is_form_available()) {
            return $empty;
        }

        $course = get_course($assignment->course);
        $assign = new \assign($context, $cm, $course);
        $grade = $assign->get_user_grade($userid, false);
        if (!$grade) {
            return $empty;
        }
        // Core ignores the rater id here (see the MDL-31237 placeholder in gradingform_controller),
        // so any grader polling the page sees the instance the adhoc task wrote for this grade item.
        $instance = $controller->get_current_instance($USER->id, $grade->id);
        if (!$instance || (int) $instance->get_data('timemodified') < $since) {
            return $empty;
        }

        $criteria = [];
        $filling = $instance->get_rubric_filling();
        foreach ($filling['criteria'] ?? [] as $criterionid => $entry) {
            $criteria[] = [
                'criterionid' => (int) $criterionid,
                'levelid' => (int) ($entry['levelid'] ?? 0),
                'remark' => (string) ($entry['remark'] ?? ''),
            ];
        }
        return ['applied' => true, 'criteria' => $criteria];
    }

    /**
     * Find the stored_progress record for a running adhoc task matching the given assignment and user.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user ID.
     * @return int The stored_progress record ID, or 0 if none found.
     */
    private static function find_progress_record(int $assignmentid, int $userid): int {
        global $DB;

        $taskclass = process_feedback_adhoc::class;
        $tasks = \core\task\manager::get_adhoc_tasks($taskclass);
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            if (
                isset($data->assignment) && (int) $data->assignment === $assignmentid
                && isset($data->users) && in_array($userid, (array) $data->users)
            ) {
                $idnumber = stored_progress_bar::convert_to_idnumber(
                    $taskclass . '_' . $task->get_id()
                );
                $record = $DB->get_record('stored_progress', ['idnumber' => $idnumber]);
                if ($record && (float) ($record->percentcompleted ?? 0) < 100) {
                    return (int) $record->id;
                }
            }
        }

        return 0;
    }

    /**
     * Describes the return value for check_feedback_status.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'feedbackexists' => new external_value(PARAM_BOOL, 'Whether AI feedback exists for the submission'),
            'feedbackhtml' => new external_value(
                PARAM_RAW,
                'The formatted feedback HTML (only for per-user mode)',
                VALUE_DEFAULT,
                ''
            ),
            'progressrecordid' => new external_value(
                PARAM_INT,
                'Stored progress record ID for a running task (0 if none)',
                VALUE_DEFAULT,
                0
            ),
            'rubric' => new external_single_structure([
                'applied' => new external_value(
                    PARAM_BOOL,
                    'Whether the current feedback run applied a rubric assessment'
                ),
                'criteria' => new external_multiple_structure(
                    new external_single_structure([
                        'criterionid' => new external_value(PARAM_INT, 'Rubric criterion id'),
                        'levelid' => new external_value(PARAM_INT, 'Selected level id (0 if none)'),
                        'remark' => new external_value(PARAM_RAW, 'Remark stored for the criterion'),
                    ])
                ),
            ], 'Rubric filling applied by the adhoc task (only for per-user mode)'),
        ]);
    }
}
