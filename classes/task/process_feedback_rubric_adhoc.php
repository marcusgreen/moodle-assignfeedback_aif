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

use context_system;

/**
 * Ad-hoc task for processing AI feedback with rubric grading.
 *
 * @package    assignfeedback_aif
 * @copyright  2025 Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_feedback_rubric_adhoc extends \core\task\adhoc_task {
    /**
     * Execute the ad-hoc task.
     */
    public function execute(): void {
        global $DB;

        $customdata = $this->get_custom_data();
        $assignmentid = $customdata->assignment;
        $users = $customdata->users;
        $action = $customdata->action;
        $triggeredby = $customdata->triggeredby ?? 'manual';

        foreach ($users as $userid) {
            $record = $this->get_submission_record($assignmentid, $userid);

            if ($action === 'generate') {
                $this->generate_feedback($record, $triggeredby);
            } else if ($action === 'delete') {
                $this->delete_feedback($record, $assignmentid);
            }
        }
    }

    /**
     * Get the submission record for a user.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return object|false The record or false if not found.
     */
    private function get_submission_record(int $assignmentid, int $userid) {
        global $DB;

        $sql = "SELECT sub.id AS subid,
                       cx.id AS contextid,
                       aif.id AS aifid,
                       aif.prompt AS prompt,
                       a.id AS aid,
                       a.name AS assignmentname,
                       sub.userid
                FROM {assign} a
                JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                JOIN {context} cx ON cx.instanceid = cm.id
                JOIN {assignfeedback_aif} aif ON aif.assignment = cm.id
                JOIN {assign_submission} sub ON sub.assignment = a.id
                WHERE sub.status = 'submitted'
                  AND cx.contextlevel = 70
                  AND a.id = :aid
                  AND sub.userid = :userid
                  AND sub.latest = 1";

        return $DB->get_record_sql($sql, ['aid' => $assignmentid, 'userid' => $userid]);
    }

    /**
     * Generate AI feedback for a submission.
     *
     * @param object|false $record The submission record.
     * @param string $triggeredby How the task was triggered: 'auto' (observer) or 'manual' (teacher).
     */
    private function generate_feedback($record, string $triggeredby = 'manual'): void {
        global $DB, $CFG;

        if (empty($record)) {
            return;
        }

        // Delete existing feedback for this submission to allow regeneration.
        $DB->delete_records('assignfeedback_aif_feedback', [
            'aif' => $record->aifid,
            'submission' => $record->subid,
        ]);

        // Use the context from the submission for proper permission checks.
        $aif = new \assignfeedback_aif\aif($record->contextid);

        $promptdata = $aif->get_prompt($record, 'rubric');
        if (empty($promptdata['prompt'])) {
            return;
        }

        try {
            // Determine purpose based on whether we have an image.
            $purpose = null;
            if (!empty($promptdata['options']['image'])) {
                // Use 'itt' (Image To Text) purpose for image analysis.
                $purpose = 'itt';
                mtrace("Using 'itt' purpose for image analysis.");
            }

            $aifeedback = $aif->perform_request($promptdata['prompt'], $purpose, $promptdata['options']);

            // Practice mode: only when auto-triggered (not teacher) and no marking workflow.
            $ispractice = ($triggeredby === 'auto') && $this->is_practice_mode($record->aid);

            // Append the appropriate disclaimer to feedback.
            $aifeedback = $aif->append_disclaimer($aifeedback, $ispractice);

            $clock = \core\di::get(\core\clock::class);
            $data = (object) [
                'aif' => $record->aifid,
                'feedback' => $aifeedback,
                'timecreated' => $clock->now()->getTimestamp(),
                'submission' => $record->subid,
            ];
            $DB->insert_record('assignfeedback_aif_feedback', $data);

            // Ensure a grade record exists so students can see feedback in the submission view.
            $this->ensure_grade_record($record);

            mtrace("AI feedback generated for assignment {$record->aid} submission {$record->subid}");
        } catch (\Exception $e) {
            mtrace("Error generating AI feedback: " . $e->getMessage());
        }
    }

    /**
     * Check whether this assignment operates in practice mode.
     *
     * Practice mode means autogenerate is enabled and marking workflow is off.
     * In this mode, students see AI feedback immediately without teacher review,
     * so a different disclaimer is used.
     *
     * @param int $assignmentid The assign instance ID.
     * @return bool True if practice mode is active.
     */
    private function is_practice_mode(int $assignmentid): bool {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $assignmentid], 'id, markingworkflow');
        if (empty($assign)) {
            return false;
        }

        // Practice mode: marking workflow is off, so feedback is visible immediately.
        return empty($assign->markingworkflow);
    }

    /**
     * Ensure an assign_grades record exists for the user so feedback is visible.
     *
     * The assign module only renders feedback plugins when an assign_grades record
     * exists. This creates one with grade=-1 (not yet graded) if none exists.
     *
     * @param object $record The submission record.
     */
    private function ensure_grade_record(object $record): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        [$course, $cm] = get_course_and_cm_from_instance($record->aid, 'assign');
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);
        $assign->get_user_grade($record->userid, true);
    }

    /**
     * Delete AI feedback for a submission.
     *
     * @param object|false $record The submission record.
     * @param int $assignmentid The assignment ID.
     */
    private function delete_feedback($record, int $assignmentid): void {
        global $DB;

        if (empty($record) || empty($record->subid)) {
            return;
        }

        $DB->delete_records('assignfeedback_aif_feedback', [
            'aif' => $record->aifid,
            'submission' => $record->subid,
        ]);

        mtrace("AI feedback deleted for assignment {$assignmentid} submission {$record->subid}");
    }
}
