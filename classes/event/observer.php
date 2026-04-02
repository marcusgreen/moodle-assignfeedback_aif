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

namespace assignfeedback_aif\event;

use assignfeedback_aif\task\process_feedback_adhoc;
use core\task\manager;

/**
 * Event observer for AI Assisted Feedback.
 *
 * @package    assignfeedback_aif
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Listen to assessable_submitted events and queue AI feedback generation if enabled.
     *
     * @param \mod_assign\event\assessable_submitted $event The event object.
     * @return void
     */
    public static function assessable_submitted(\mod_assign\event\assessable_submitted $event): void {
        self::queue_feedback_generation($event);
    }

    /**
     * Listen to submission_updated events and re-queue AI feedback for submitted assignments.
     *
     * Only triggers when the submission status is 'submitted' and autogenerate is enabled.
     * Handles resubmission scenarios where the student modifies text or files after initial submission.
     *
     * @param \mod_assign\event\submission_updated $event The event object.
     * @return void
     */
    public static function submission_updated(\mod_assign\event\submission_updated $event): void {
        // Only re-generate for already-submitted submissions.
        $submissionstatus = $event->other['submissionstatus'] ?? '';
        if ($submissionstatus !== 'submitted') {
            return;
        }

        self::queue_feedback_generation($event);
    }

    /**
     * Queue AI feedback generation for a submission if autogenerate is enabled.
     *
     * @param \core\event\base $event The event object.
     * @return void
     */
    private static function queue_feedback_generation(\core\event\base $event): void {
        global $DB;

        $assign = $event->get_assign();
        $assignmentid = $assign->get_instance()->id;
        // Use relateduserid when set (teacher submitting on behalf), otherwise userid (student self-submission).
        $userid = $event->relateduserid ?? $event->userid;
        $cm = $assign->get_course_module();

        // Check if autogenerate is enabled for this assignment.
        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if (!$aifconfig || empty($aifconfig->autogenerate)) {
            return;
        }

        // Queue the ad-hoc task.
        // Duplicate protection: queue_adhoc_task($task, true) prevents duplicates when both
        // the onlinetext and file submission plugins fire submission_updated for the same student.
        // This works because the custom_data is identical for both events.
        $task = new process_feedback_adhoc();
        $task->set_custom_data([
            'assignment' => $assignmentid,
            'users' => [$userid],
            'action' => 'generate',
            'triggeredby' => 'auto',
        ]);
        // Run as the submitting user so quota and availability checks are correct.
        $task->set_userid($userid);
        manager::queue_adhoc_task($task, true);

        // Ensure a grade record exists immediately so the student-facing
        // feedback section is rendered while feedback generation is pending.
        // Without this, the assign module skips all feedback plugins when
        // no grade record exists, preventing the spinner from showing.
        $assign->get_user_grade($userid, true);
    }

    /**
     * Listen to submission_removed events and delete associated AI feedback.
     *
     * @param \mod_assign\event\submission_removed $event The event object.
     * @return void
     */
    public static function submission_removed(\mod_assign\event\submission_removed $event): void {
        global $DB;

        $sql = "SELECT aif.id AS aifid
                  FROM {assignfeedback_aif} aif
                 WHERE aif.assignment = :aid";
        $param = ['aid' => $event->get_assign()->get_instance()->id];
        $aifid = $DB->get_field_sql($sql, $param);

        if ($aifid && !empty($event->other['submissionid'])) {
            $DB->delete_records('assignfeedback_aif_feedback', [
                'submission' => $event->other['submissionid'],
                'aif' => $aifid,
            ]);
        }
    }
}
