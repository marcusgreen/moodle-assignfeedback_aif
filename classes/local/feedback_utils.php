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
        global $CFG;

        if (empty($record->skippedfiles)) {
            return null;
        }
        $skipped = json_decode($record->skippedfiles, true);
        if (!empty($skipped) && is_array($skipped)) {
            foreach ($skipped as $entry) {
                if (is_array($entry) && isset($entry['_error'])) {
                    $msg = get_string('feedbackgenerationerror', 'assignfeedback_aif', $entry['_error']);
                    if (!empty($entry['_debuginfo']) && !empty($CFG->debugdisplay) && $CFG->debug >= DEBUG_DEVELOPER) {
                        $msg .= \html_writer::tag('pre', s($entry['_debuginfo']), ['class' => 'mt-2 small']);
                    }
                    return $msg;
                }
            }
        }
        return null;
    }

    /**
     * Save or update the assignment-level plugin settings (prompt, autogenerate).
     *
     * @param int $assignmentid The assignment instance ID.
     * @param string $prompt The AI prompt text.
     * @param int $autogenerate Whether to auto-generate feedback on submission (0 or 1).
     * @return bool True on success.
     */
    public static function save_settings(int $assignmentid, string $prompt, int $autogenerate): bool {
        global $DB;
        $feedback = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if ($feedback) {
            $feedback->prompt = $prompt;
            $feedback->autogenerate = $autogenerate;
            $DB->update_record('assignfeedback_aif', $feedback);
        } else {
            $clock = \core\di::get(\core\clock::class);
            $feedback = new \stdClass();
            $feedback->prompt = $prompt;
            $feedback->autogenerate = $autogenerate;
            $feedback->assignment = $assignmentid;
            $feedback->timecreated = $clock->now()->getTimestamp();
            $DB->insert_record('assignfeedback_aif', $feedback);
        }
        return true;
    }

    /**
     * Save or update a per-user feedback record.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user ID whose feedback is being saved.
     * @param string $feedback The feedback HTML text.
     * @param int $feedbackformat The text format (e.g. FORMAT_HTML).
     * @return bool True on success, false if no config record exists.
     */
    public static function save_feedback(
        int $assignmentid,
        int $userid,
        string $feedback,
        int $feedbackformat
    ): bool {
        global $DB;
        $clock = \core\di::get(\core\clock::class);
        $record = self::get_feedbackaif($assignmentid, $userid);

        if ($record) {
            $record->timemodified = $clock->now()->getTimestamp();
            $record->feedback = $feedback;
            $record->feedbackformat = $feedbackformat;
            $DB->update_record('assignfeedback_aif_feedback', $record);
        } else {
            $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
            if (!$aif) {
                debugging(
                    'assignfeedback_aif: No config record found for assignment, cannot save feedback.',
                    DEBUG_DEVELOPER
                );
                return false;
            }
            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assignmentid,
                'userid' => $userid,
                'latest' => 1,
            ]);
            $newrecord = new \stdClass();
            $newrecord->aif = $aif->id;
            $newrecord->submission = $submission ? $submission->id : null;
            $newrecord->feedback = $feedback;
            $newrecord->feedbackformat = $feedbackformat;
            $newrecord->timecreated = $clock->now()->getTimestamp();
            $DB->insert_record('assignfeedback_aif_feedback', $newrecord);
        }
        return true;
    }

    /**
     * Delete AI feedback records for specific users.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param array $users Array of user IDs to delete feedback for.
     */
    public static function delete_feedback_for_users(int $assignmentid, array $users): void {
        global $DB;
        $aifrecord = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if (!$aifrecord) {
            return;
        }
        foreach ($users as $userid) {
            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assignmentid,
                'userid' => $userid,
                'latest' => 1,
            ]);
            if ($submission) {
                $DB->delete_records('assignfeedback_aif_feedback', [
                    'aif' => $aifrecord->id,
                    'submission' => $submission->id,
                ]);
            }
        }
    }

    /**
     * Delete all plugin data for an assignment (cleanup on instance deletion).
     *
     * @param int $assignmentid The assignment instance ID.
     * @return bool True on success.
     */
    public static function delete_all_feedback(int $assignmentid): bool {
        global $DB;
        $records = $DB->get_records('assignfeedback_aif', ['assignment' => $assignmentid], '', 'id');
        foreach ($records as $record) {
            $DB->delete_records('assignfeedback_aif_feedback', ['aif' => $record->id]);
        }
        $DB->delete_records('assignfeedback_aif', ['assignment' => $assignmentid]);
        return true;
    }
}
