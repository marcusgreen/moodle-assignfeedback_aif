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
 * Ad-hoc task for processing AI feedback.
 *
 * Uses the stored progress trait to report task progress to the browser
 * via the Moodle stored_progress polling mechanism.
 *
 * @package    assignfeedback_aif
 * @copyright  2025 Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_feedback_adhoc extends \core\task\adhoc_task {
    use \core\task\stored_progress_task_trait;

    /**
     * Execute the ad-hoc task.
     */
    public function execute(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $this->start_stored_progress();

        $customdata = $this->get_custom_data();
        $assignmentid = $customdata->assignment;
        $users = $customdata->users;
        $action = $customdata->action;
        $triggeredby = $customdata->triggeredby ?? 'manual';

        // Create the assign instance once for all users in this batch.
        [$course, $cm] = get_course_and_cm_from_instance($assignmentid, 'assign');
        $context = \core\context\module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);

        $totalusers = count($users);

        foreach ($users as $index => $userid) {
            // Each user gets an equal slice of 0-100%.
            $slicestart = ($index / $totalusers) * 100;
            $slicesize = 100 / $totalusers;

            $record = $this->get_submission_record($assignmentid, $userid);

            if ($action === 'generate') {
                $this->generate_feedback($record, $triggeredby, $assign, $slicestart, $slicesize);
            } else if ($action === 'delete') {
                $this->delete_feedback($record, $assignmentid);
                $this->report_substep($slicestart, $slicesize, 100, 'feedbackgenerationcomplete');
            }
        }

        $this->progress->update_full(100, get_string('feedbackgenerationcomplete', 'assignfeedback_aif'));
    }

    /**
     * Sets the initial progress of the associated progress bar.
     *
     * Adds a message that the task is waiting to be picked up by cron.
     */
    public function set_initial_progress(): void {
        $this->progress->update_full(0, get_string('waitingforadhoctaskstart', 'assignfeedback_aif'));
    }

    #[\Override]
    public function retry_until_success(): bool {
        return false;
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
                JOIN {assignfeedback_aif} aif ON aif.assignment = a.id
                JOIN {assign_submission} sub ON sub.assignment = a.id
                WHERE sub.status = 'submitted'
                  AND cx.contextlevel = :contextlevel
                  AND a.id = :aid
                  AND sub.userid = :userid
                  AND sub.latest = 1";

        return $DB->get_record_sql($sql, ['aid' => $assignmentid, 'userid' => $userid, 'contextlevel' => CONTEXT_MODULE]);
    }

    /**
     * Generate AI feedback for a submission.
     *
     * Reports granular progress within the user's allocated slice of the progress bar.
     *
     * @param object|false $record The submission record.
     * @param string $triggeredby How the task was triggered: 'auto' (observer) or 'manual' (teacher).
     * @param \assign|null $assign The assign instance.
     * @param float $slicestart The starting percentage of this user's progress slice.
     * @param float $slicesize The size of this user's progress slice (percentage points).
     */
    private function generate_feedback(
        $record,
        string $triggeredby = 'manual',
        ?\assign $assign = null,
        float $slicestart = 0,
        float $slicesize = 100
    ): void {
        global $DB, $CFG;

        if (empty($record)) {
            return;
        }

        // Step 1: Preparing submission data (10%).
        $this->report_substep($slicestart, $slicesize, 10, 'progresssteppreparing');

        // Delete existing feedback for this submission to allow regeneration.
        $DB->delete_records('assignfeedback_aif_feedback', [
            'aif' => $record->aifid,
            'submission' => $record->subid,
        ]);

        // Use the context from the submission for proper permission checks.
        $aif = new \assignfeedback_aif\aif($record->contextid);

        // Step 2: Extracting submission content (30%).
        $this->report_substep($slicestart, $slicesize, 30, 'progressstepextracting');

        $promptdata = $aif->get_prompt($record, 'rubric');
        if (empty($promptdata['prompt'])) {
            return;
        }

        // Step 3: Requesting AI feedback (50%).
        $this->report_substep($slicestart, $slicesize, 50, 'progresssteprequesting');

        // All content (including images and PDFs) is now converted to text during
        // prompt building, so we always use the default feedback purpose.
        $provider = \core\di::get(\assignfeedback_aif\local\ai_request_provider::class);
        $purpose = 'feedback';

        // Set up the user context for the submission owner so that quota and
        // availability checks are performed against the correct user.
        // For team submissions, userid may be 0 (group submission); get_user()
        // returns false in that case, so fall back to null (cron default user).
        $submissionuser = \core_user::get_user($record->userid) ?: null;

        try {
            \core\cron::setup_user($submissionuser);

            if (!$provider->is_available($purpose, $record->contextid)) {
                $this->progress->error(get_string('ainavailable', 'assignfeedback_aif'));
                mtrace("AI backend not available for user {$record->userid}, skipping submission {$record->subid}.");
                return;
            }

            $aifeedback = $aif->perform_request(
                $promptdata['prompt'],
                null,
                $promptdata['options']
            );
        } catch (\Exception $e) {
            $this->progress->error($e->getMessage());
            mtrace("AI request failed for submission {$record->subid}: " . $e->getMessage());
            return;
        } finally {
            \core\cron::setup_user();
        }

        // Step 4: Saving feedback (90%).
        $this->report_substep($slicestart, $slicesize, 90, 'progressstepsaving');

        // Practice mode: only when auto-triggered (not teacher) and no marking workflow.
        $ispractice = ($triggeredby === 'auto') && $this->is_practice_mode($record->aid);

        // Append the appropriate disclaimer to feedback.
        $aifeedback = $aif->append_disclaimer($aifeedback, $ispractice);

        // Convert markdown to HTML so it can be displayed and edited in the TinyMCE editor.
        $aifeedbackhtml = format_text($aifeedback, FORMAT_MARKDOWN, ['filter' => false]);

        $clock = \core\di::get(\core\clock::class);
        $data = (object) [
            'aif' => $record->aifid,
            'feedback' => $aifeedbackhtml,
            'feedbackformat' => FORMAT_HTML,
            'timecreated' => $clock->now()->getTimestamp(),
            'submission' => $record->subid,
        ];
        $DB->insert_record('assignfeedback_aif_feedback', $data);

        // Ensure a grade record exists so students can see feedback in the submission view.
        $this->ensure_grade_record($record, $assign);

        mtrace("AI feedback generated for assignment {$record->aid} submission {$record->subid}");
    }

    /**
     * Report a sub-step within a user's progress slice.
     *
     * Calculates the absolute progress percentage based on the user's slice
     * of the total progress bar and the relative position within that slice.
     *
     * @param float $slicestart The starting percentage of this user's slice.
     * @param float $slicesize The size of this user's slice (percentage points).
     * @param float $relativepercent The relative progress within the slice (0-100).
     * @param string $langkey The lang string key for the progress message.
     */
    private function report_substep(float $slicestart, float $slicesize, float $relativepercent, string $langkey): void {
        $absolutepercent = $slicestart + ($slicesize * $relativepercent / 100);
        $absolutepercent = min($absolutepercent, 99); // Reserve 100% for the final completion message.
        $this->progress->update_full($absolutepercent, get_string($langkey, 'assignfeedback_aif'));
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
     * @param \assign $assign The assign instance.
     */
    private function ensure_grade_record(object $record, \assign $assign): void {
        global $DB;

        if (
            !$DB->record_exists('assign_grades', [
                'assignment' => $record->aid,
                'userid' => $record->userid,
            ])
        ) {
            $assign->get_user_grade($record->userid, true);
        }
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
