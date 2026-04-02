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
use assignfeedback_aif\task\process_feedback_adhoc;
use core\context\module as context_module;
use core\output\stored_progress_bar;
use core\task\manager;

/**
 * External function to retry AI feedback generation after a failure.
 *
 * Allows both teachers (with mod/assign:grade) and students (own submission,
 * autogenerate enabled, error state present) to re-queue a failed feedback
 * generation task.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retry_feedback extends external_api {
    /**
     * Describes the parameters for retry_feedback.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'The assignment instance id'),
            'userid' => new external_value(PARAM_INT, 'The user id to retry feedback for'),
        ]);
    }

    /**
     * Retry AI feedback generation for a submission that previously failed.
     *
     * Deletes the existing error feedback record, queues a new adhoc task,
     * and returns a stored progress record ID for client-side polling.
     *
     * @param int $assignmentid The assignment instance id.
     * @param int $userid The user id.
     * @return array Result with success status and progress tracking data.
     */
    public static function execute(int $assignmentid, int $userid): array {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
            'userid' => $userid,
        ]);

        // Get the assignment and validate context.
        $assignment = $DB->get_record('assign', ['id' => $params['assignmentid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assignment->id, $assignment->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Permission check: teacher with grade capability OR own submission with autogenerate.
        $isteacher = has_capability('mod/assign:grade', $context);
        $isownsubmission = ((int) $USER->id === $params['userid']);

        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => $params['assignmentid']]);
        if (!$aifconfig) {
            return ['success' => false, 'progressrecordid' => 0];
        }

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $params['assignmentid'],
            'userid' => $params['userid'],
            'latest' => 1,
        ]);
        if (!$submission) {
            return ['success' => false, 'progressrecordid' => 0];
        }

        if (!$isteacher) {
            if (!$isownsubmission) {
                throw new \required_capability_exception($context, 'mod/assign:grade', 'nopermissions', '');
            }
            // Students can only retry when autogenerate is enabled.
            if (empty($aifconfig->autogenerate)) {
                throw new \required_capability_exception($context, 'mod/assign:grade', 'nopermissions', '');
            }
            // Students can only retry when there is an error state.
            $feedbackrecord = $DB->get_record('assignfeedback_aif_feedback', [
                'aif' => $aifconfig->id,
                'submission' => $submission->id,
            ]);
            if (!$feedbackrecord || !self::has_error_marker($feedbackrecord)) {
                throw new \required_capability_exception($context, 'mod/assign:grade', 'nopermissions', '');
            }
        }

        // Delete existing error feedback so the UI reflects the retry.
        $DB->delete_records('assignfeedback_aif_feedback', [
            'aif' => $aifconfig->id,
            'submission' => $submission->id,
        ]);

        // Queue the ad-hoc task with a unique marker for retrieval.
        $triggeredby = $isteacher ? 'manual' : 'auto';
        $task = new process_feedback_adhoc();
        $uniqadhoctaskid = uniqid();
        $task->set_custom_data([
            'assignment' => $params['assignmentid'],
            'users' => [$params['userid']],
            'action' => 'generate',
            'triggeredby' => $triggeredby,
            'uniqadhoctaskid' => $uniqadhoctaskid,
        ]);
        $task->set_userid($isteacher ? $USER->id : $params['userid']);
        manager::queue_adhoc_task($task, true);

        // Find the queued task to get its ID for stored progress.
        $currenttasks = manager::get_adhoc_tasks(process_feedback_adhoc::class);
        $adhoctask = null;
        foreach ($currenttasks as $t) {
            $data = $t->get_custom_data();
            if (isset($data->uniqadhoctaskid) && $data->uniqadhoctaskid === $uniqadhoctaskid) {
                $adhoctask = $t;
                break;
            }
        }

        $progressrecordid = 0;
        if ($adhoctask) {
            $adhoctask->initialise_stored_progress();

            $idnumber = stored_progress_bar::convert_to_idnumber(
                process_feedback_adhoc::class . '_' . $adhoctask->get_id()
            );
            $record = $DB->get_record('stored_progress', ['idnumber' => $idnumber]);
            if ($record) {
                $progressrecordid = (int) $record->id;
                $record->message = get_string('waitingforadhoctaskstart', 'assignfeedback_aif');
                $DB->update_record('stored_progress', $record);
            }
        }

        return [
            'success' => true,
            'progressrecordid' => $progressrecordid,
        ];
    }

    /**
     * Check whether a feedback record contains an error marker in skippedfiles.
     *
     * @param \stdClass $record The feedback record.
     * @return bool True if the record has an error marker.
     */
    private static function has_error_marker(\stdClass $record): bool {
        if (empty($record->skippedfiles)) {
            return false;
        }
        $skipped = json_decode($record->skippedfiles, true);
        if (!is_array($skipped)) {
            return false;
        }
        foreach ($skipped as $entry) {
            if (is_array($entry) && isset($entry['_error'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Describes the return value for retry_feedback.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the task was queued successfully'),
            'progressrecordid' => new external_value(PARAM_INT, 'Stored progress DB record ID for polling'),
        ]);
    }
}
