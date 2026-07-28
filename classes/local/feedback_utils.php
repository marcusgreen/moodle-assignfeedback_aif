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
     * Ensure the assignfeedback_aif config row exists for the given assignment.
     *
     * After activity duplication, mod_assign restores assign_plugin_config but
     * never calls a hook that lets us populate our custom table. This method
     * lazily creates the missing row from assign_plugin_config data.
     *
     * @param int $assignmentid The assignment instance ID.
     */
    public static function ensure_config_exists(int $assignmentid): void {
        global $DB;

        // Per-request guard: avoid repeating the existence check for the same
        // assignment, which would otherwise run once per student on the grading table.
        static $ensured = [];
        if (isset($ensured[$assignmentid])) {
            return;
        }

        if ($DB->record_exists('assignfeedback_aif', ['assignment' => $assignmentid])) {
            $ensured[$assignmentid] = true;
            return;
        }

        // Read from assign_plugin_config (restored by mod_assign core).
        $prompt = $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assignmentid,
            'plugin' => 'aif',
            'subtype' => 'assignfeedback',
            'name' => 'prompt',
        ]);
        $autogenerate = $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assignmentid,
            'plugin' => 'aif',
            'subtype' => 'assignfeedback',
            'name' => 'autogenerate',
        ]);
        $useintroattachments = $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assignmentid,
            'plugin' => 'aif',
            'subtype' => 'assignfeedback',
            'name' => 'useintroattachments',
        ]);

        if ($prompt !== false || $autogenerate !== false) {
            $clock = \core\di::get(\core\clock::class);
            $record = new \stdClass();
            $record->assignment = $assignmentid;
            $record->prompt = ($prompt !== false) ? $prompt : '';
            $record->autogenerate = ($autogenerate !== false) ? (int) $autogenerate : 0;
            $record->useintroattachments = ($useintroattachments !== false) ? (int) $useintroattachments : 1;
            $record->timecreated = $clock->now()->getTimestamp();
            $DB->insert_record('assignfeedback_aif', $record);
        }

        $ensured[$assignmentid] = true;
    }

    /**
     * Get AI feedback record for a submission.
     *
     * Performs crash recovery: if the record has status='pending' but no
     * corresponding adhoc task exists in the queue, the task has crashed
     * and the record is marked as error.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return \stdClass|false The feedback record or false if not found.
     */
    public static function get_feedbackaif(int $assignmentid, int $userid): \stdClass|false {
        global $DB;
        self::ensure_config_exists($assignmentid);
        $sql = "SELECT aiff.*
                  FROM {assign} a
                  JOIN {assignfeedback_aif} aif ON aif.assignment = a.id
                  JOIN {assignfeedback_aif_feedback} aiff ON aiff.aif = aif.id
                  JOIN {assign_submission} sub ON sub.assignment = a.id AND aiff.submission = sub.id
                 WHERE a.id = :assignment AND sub.userid = :userid AND sub.latest = 1";
        $params = ['assignment' => $assignmentid, 'userid' => $userid];

        $record = $DB->get_record_sql($sql, $params);

        if ($record) {
            self::recover_crashed_task($record, $assignmentid, $userid);
        }

        return $record;
    }

    /**
     * Detect and recover from a crashed adhoc task.
     *
     * When a feedback record has status='pending' but no matching adhoc task
     * exists in the queue, the task has crashed (e.g. fatal error, server
     * restart). The record is updated in place to status='error' so the user
     * sees a meaningful message instead of an infinite spinner.
     *
     * @param \stdClass $record The feedback record (modified in place).
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return void
     */
    private static function recover_crashed_task(\stdClass $record, int $assignmentid, int $userid): void {
        global $DB;

        if (empty($record->status) || $record->status !== 'pending') {
            return;
        }

        if (task_manager::is_task_queued_for_user($assignmentid, $userid)) {
            return;
        }

        // Task is gone — mark as error so the user can see what happened.
        $record->status = 'error';
        $record->errormessage = get_string('errortaskcrashed', 'assignfeedback_aif');
        $clock = \core\di::get(\core\clock::class);
        $record->timemodified = $clock->now()->getTimestamp();
        $DB->update_record('assignfeedback_aif_feedback', $record);
    }
    /**
     * Check whether AI feedback generation is pending for a submission.
     *
     * Feedback is considered pending when a record with status='pending' exists
     * for this assignment and user's latest submission. Also returns true when
     * autogenerate is enabled but no record exists yet (task not yet queued).
     *
     * Crash recovery is performed inside get_feedbackaif(), so a record that
     * still reports status='pending' here is guaranteed to have a live task.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return bool True if feedback generation is expected but not yet complete.
     */
    public static function is_feedback_pending(int $assignmentid, int $userid): bool {
        global $DB;
        self::ensure_config_exists($assignmentid);

        // Check if a pending record exists. get_feedbackaif() already performed
        // crash recovery, so status='pending' means the task is still queued.
        $record = self::get_feedbackaif($assignmentid, $userid);
        if ($record && !empty($record->status) && $record->status === 'pending') {
            return true;
        }

        // Fallback: no record yet, but autogenerate is enabled and submission exists.
        if (!$record) {
            $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
            if ($aifconfig && !empty($aifconfig->autogenerate)) {
                return $DB->record_exists('assign_submission', [
                    'assignment' => $assignmentid,
                    'userid' => $userid,
                    'status' => 'submitted',
                    'latest' => 1,
                ]);
            }
        }

        return false;
    }

    /**
     * Check if there is a running adhoc task with stored progress for this assignment and user.
     *
     * Delegates to task_manager which centralises all task lookup logic.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user ID.
     * @return int The stored_progress record ID, or 0 if no running task.
     */
    public static function get_running_progress_id(int $assignmentid, int $userid): int {
        return task_manager::get_progress_id_for_user($assignmentid, $userid);
    }

    /**
     * Extract error message from a feedback record.
     *
     * Returns the error message from the status/errormessage fields.
     * Falls back to legacy skippedfiles parsing for records not yet migrated.
     *
     * @param \stdClass $record The feedback record.
     * @return string|null The error message, or null if no error.
     */
    public static function get_error_from_feedback(\stdClass $record): ?string {
        global $CFG;

        // New status-based error detection.
        if (!empty($record->status) && $record->status === 'error') {
            $errormsg = $record->errormessage ?? '';
            return get_string('feedbackgenerationerror', 'assignfeedback_aif', $errormsg);
        }

        // Legacy fallback: parse _error from skippedfiles JSON.
        if (empty($record->skippedfiles)) {
            return null;
        }
        $skipped = json_decode($record->skippedfiles, true);
        if (!empty($skipped) && is_array($skipped)) {
            foreach ($skipped as $entry) {
                if (is_array($entry) && isset($entry['_error'])) {
                    $msg = get_string('feedbackgenerationerror', 'assignfeedback_aif', s($entry['_error']));
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
     * @param int $useintroattachments Whether to include intro attachments in the AI prompt (0 or 1).
     * @return bool True on success.
     */
    public static function save_settings(
        int $assignmentid,
        string $prompt,
        int $autogenerate,
        int $useintroattachments = 1
    ): bool {
        global $DB;
        $feedback = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if ($feedback) {
            $feedback->prompt = $prompt;
            $feedback->autogenerate = $autogenerate;
            $feedback->useintroattachments = $useintroattachments;
            $DB->update_record('assignfeedback_aif', $feedback);
        } else {
            $clock = \core\di::get(\core\clock::class);
            $feedback = new \stdClass();
            $feedback->prompt = $prompt;
            $feedback->autogenerate = $autogenerate;
            $feedback->useintroattachments = $useintroattachments;
            $feedback->assignment = $assignmentid;
            $feedback->timecreated = $clock->now()->getTimestamp();
            $DB->insert_record('assignfeedback_aif', $feedback);
        }
        return true;
    }

    /**
     * Save or update a per-user feedback record.
     *
     * Sets the record status to 'completed' and clears any previous error.
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
            $record->status = 'completed';
            $record->errormessage = null;
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
            $newrecord->status = 'completed';
            $newrecord->errormessage = null;
            $newrecord->timecreated = $clock->now()->getTimestamp();
            $newrecord->timemodified = $clock->now()->getTimestamp();
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
