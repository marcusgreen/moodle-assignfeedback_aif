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

namespace assignfeedback_aif\task;

/**
 * Scheduled task dispatcher for AI feedback generation.
 *
 * This task finds submissions without AI feedback and enqueues
 * adhoc tasks for each assignment. The actual feedback generation
 * happens exclusively in the adhoc worker task.
 *
 * @package    assignfeedback_aif
 * @copyright  2025 Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_feedback extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskprocessfeedback', 'assignfeedback_aif');
    }

    /**
     * Execute the scheduled task.
     *
     * Finds all submitted assignments with AIF enabled that have no feedback yet,
     * and enqueues an adhoc task for each.
     */
    public function execute(): void {
        global $DB;

        $sql = "SELECT sub.id AS subid,
                       aif.id AS aifid,
                       a.id AS aid,
                       sub.userid
                  FROM {assign} a
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                  JOIN {context} cx ON cx.instanceid = cm.id AND cx.contextlevel = :contextlevel
                  JOIN {assignfeedback_aif} aif ON aif.assignment = a.id
                  JOIN {assign_submission} sub ON sub.assignment = a.id
                 WHERE sub.status = :status
                   AND sub.latest = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM {assignfeedback_aif_feedback} aiff
                        WHERE aiff.aif = aif.id AND aiff.submission = sub.id
                   )";
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'status' => 'submitted',
        ];
        $records = $DB->get_records_sql($sql, $params);

        if (empty($records)) {
            mtrace('No unprocessed submissions found.');
            return;
        }

        // Group by assignment to enqueue one adhoc task per assignment.
        $byassignment = [];
        foreach ($records as $record) {
            $byassignment[$record->aid][] = $record->userid;
        }

        foreach ($byassignment as $assignmentid => $userids) {
            $task = new process_feedback_adhoc();
            $task->set_custom_data([
                'assignment' => $assignmentid,
                'users' => $userids,
                'action' => 'generate',
                'triggeredby' => 'auto',
            ]);
            \core\task\manager::queue_adhoc_task($task, true);
            mtrace('Enqueued adhoc task for assignment ' . $assignmentid . ' with ' . count($userids) . ' user(s).');
        }
    }
}
