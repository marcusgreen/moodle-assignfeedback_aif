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

namespace assignfeedback_aif\local;

use core\output\stored_progress_bar;
use assignfeedback_aif\task\process_feedback_adhoc;

/**
 * Utility methods for AI feedback status and data retrieval.
 *
 * Extracted from locallib.php to allow reuse across external functions,
 * hook callbacks, and the main plugin class without requiring the full
 * assign_feedback_plugin context.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_utils {
    /**
     * Get AI feedback record for a submission.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return \stdClass|false The feedback record or false if not found.
     */
    public static function get_feedbackaif(int $assignmentid, int $userid): \stdClass|false {
        global $DB;
        $sql = "SELECT aiff.*
                  FROM {assign} a
                  JOIN {assignfeedback_aif} aif ON aif.assignment = a.id
                  JOIN {assignfeedback_aif_feedback} aiff ON aiff.aif = aif.id
                  JOIN {assign_submission} sub ON sub.assignment = a.id AND aiff.submission = sub.id
                 WHERE a.id = :assignment AND sub.userid = :userid AND sub.latest = 1";
        $params = ['assignment' => $assignmentid, 'userid' => $userid];
        return $DB->get_record_sql($sql, $params);
    }

    /**
     * Check whether AI feedback generation is pending for a submission.
     *
     * Feedback is considered pending when autogenerate is enabled for this
     * assignment and a submitted submission exists but no feedback record yet.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return bool True if feedback generation is expected but not yet complete.
     */
    public static function is_feedback_pending(int $assignmentid, int $userid): bool {
        global $DB;

        // Check autogenerate is enabled.
        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if (!$aifconfig || empty($aifconfig->autogenerate)) {
            return false;
        }

        // Check a submitted submission exists.
        return $DB->record_exists('assign_submission', [
            'assignment' => $assignmentid,
            'userid' => $userid,
            'status' => 'submitted',
            'latest' => 1,
        ]);
    }

    /**
     * Check if there is a running adhoc task with stored progress for this assignment and user.
     *
     * Searches for queued process_feedback_adhoc tasks that match the assignment and user,
     * then looks up their stored_progress record.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user ID.
     * @return int The stored_progress record ID, or 0 if no running task.
     */
    public static function get_running_progress_id(int $assignmentid, int $userid): int {
        global $DB;

        $taskclass = process_feedback_adhoc::class;

        // Find queued adhoc tasks for this class.
        $tasks = \core\task\manager::get_adhoc_tasks($taskclass);
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            if (
                isset($data->assignment) && (int) $data->assignment === $assignmentid
                && isset($data->users) && in_array($userid, (array) $data->users)
            ) {
                // Found a matching task — look up its stored_progress record.
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
     * Extract error message from a feedback record's skippedfiles JSON.
     *
     * Error feedback records are stored with a special '_error' key in the
     * skippedfiles JSON when feedback generation fails. This allows the error
     * to persist and be visible even after the adhoc task has been cleaned up.
     *
     * @param \stdClass $record The feedback record.
     * @return string|null The error message, or null if no error.
     */
    public static function get_error_from_feedback(\stdClass $record): ?string {
        if (empty($record->skippedfiles)) {
            return null;
        }
        $skipped = json_decode($record->skippedfiles, true);
        if (!empty($skipped) && is_array($skipped)) {
            foreach ($skipped as $entry) {
                if (is_array($entry) && isset($entry['_error'])) {
                    return get_string('feedbackgenerationerror', 'assignfeedback_aif', $entry['_error']);
                }
            }
        }
        return null;
    }
}
