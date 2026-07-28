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

use assignfeedback_aif\task\process_feedback_adhoc;
use core\output\stored_progress_bar;
use core\task\manager;

/**
 * Centralised manager for AI feedback adhoc task lifecycle.
 *
 * Handles task creation, queuing (with deduplication), lookup, and
 * stored_progress initialisation. All other classes should delegate
 * task-related operations to this manager instead of duplicating the
 * task creation and lookup patterns.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_manager {
    /**
     * Queue a feedback generation task for a single user.
     *
     * Performs manual deduplication based on assignment+userid only.
     * Moodle's built-in deduplication also considers the task runner
     * (set_userid), which would allow duplicate tasks when different
     * users (student vs teacher) trigger generation for the same submission.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user whose feedback should be generated.
     * @param int $taskuserid The user to run the task as (student for auto, teacher for manual).
     * @return void
     */
    public static function queue_generation(int $assignmentid, int $userid, int $taskuserid): void {
        // Manual deduplication: Moodle's built-in deduplication includes the
        // task userid (set_userid) in its comparison. This means a teacher
        // triggering regeneration would bypass deduplication when a student
        // task for the same assignment+user is already queued. We therefore
        // check for an existing task ourselves, based only on assignment+userid.
        if (self::find_task_for_user($assignmentid, $userid) !== null) {
            return;
        }

        $task = new process_feedback_adhoc();
        $task->set_custom_data([
            'assignment' => intval($assignmentid),
            'userid' => intval($userid),
            'action' => 'generate',
        ]);
        $task->set_userid($taskuserid);
        manager::queue_adhoc_task($task);
    }

    /**
     * Queue a feedback generation task and initialise its stored_progress.
     *
     * After queuing, finds the task and creates a stored_progress record
     * so the client can poll for real-time progress updates.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user whose feedback should be generated.
     * @param int $taskuserid The user to run the task as.
     * @return int The stored_progress record ID (0 if progress could not be initialised).
     */
    public static function queue_generation_with_progress(int $assignmentid, int $userid, int $taskuserid): int {
        global $DB;

        self::queue_generation($assignmentid, $userid, $taskuserid);

        $adhoctask = self::find_task_for_user($assignmentid, $userid);
        if (!$adhoctask) {
            return 0;
        }

        $adhoctask->initialise_stored_progress();

        $idnumber = stored_progress_bar::convert_to_idnumber(
            process_feedback_adhoc::class . '_' . $adhoctask->get_id()
        );
        $record = $DB->get_record('stored_progress', ['idnumber' => $idnumber]);
        if ($record) {
            // Set initial message directly in DB to avoid HTML output in AJAX context.
            $record->message = get_string('waitingforadhoctaskstart', 'assignfeedback_aif');
            $DB->update_record('stored_progress', $record);
            return (int) $record->id;
        }

        return 0;
    }

    /**
     * Find a queued adhoc task for a specific assignment and user.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user ID.
     * @return process_feedback_adhoc|null The matching task, or null if none found.
     */
    public static function find_task_for_user(int $assignmentid, int $userid): ?process_feedback_adhoc {
        $tasks = manager::get_adhoc_tasks(process_feedback_adhoc::class);
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            if (
                isset($data->assignment) && (int) $data->assignment === $assignmentid
                && isset($data->userid) && (int) $data->userid === $userid
            ) {
                return $task;
            }
        }
        return null;
    }

    /**
     * Check whether any adhoc tasks are queued for a given assignment.
     *
     * Uses a direct SQL query with LIKE on the serialised customdata column
     * to avoid loading all task objects into PHP memory. This is significantly
     * faster than iterating over all tasks when many adhoc tasks are queued.
     *
     * @param int $assignmentid The assignment instance ID.
     * @return bool True if at least one task is pending.
     */
    public static function has_pending_tasks(int $assignmentid): bool {
        global $DB;

        $classname = '\\' . process_feedback_adhoc::class;
        $pattern = '%"assignment":' . intval($assignmentid) . ',%';

        $sql = "SELECT 1
                  FROM {task_adhoc}
                 WHERE classname = :classname
                   AND " . $DB->sql_like('customdata', ':pattern');
        return $DB->record_exists_sql($sql, [
            'classname' => $classname,
            'pattern' => $pattern,
        ]);
    }

    /**
     * Get the stored_progress record ID for a running task matching the given assignment and user.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user ID.
     * @return int The stored_progress record ID, or 0 if none found.
     */
    public static function get_progress_id_for_user(int $assignmentid, int $userid): int {
        global $DB;

        $tasks = manager::get_adhoc_tasks(process_feedback_adhoc::class);
        // Reverse to find the most recently queued task first.
        $tasks = array_reverse($tasks);
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            if (
                isset($data->assignment) && (int) $data->assignment === $assignmentid
                && isset($data->userid) && (int) $data->userid === $userid
            ) {
                $idnumber = stored_progress_bar::convert_to_idnumber(
                    process_feedback_adhoc::class . '_' . $task->get_id()
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
     * Check whether a queued task exists for the given assignment and user.
     *
     * Used for crash-recovery: if a feedback record has status='pending' but
     * no task exists, the task has crashed.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user ID.
     * @return bool True if a matching task is still queued.
     */
    public static function is_task_queued_for_user(int $assignmentid, int $userid): bool {
        return self::find_task_for_user($assignmentid, $userid) !== null;
    }
}
