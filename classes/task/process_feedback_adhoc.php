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
     * Execute the ad-hoc task for a single user.
     */
    public function execute(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $this->start_stored_progress();

        $customdata = $this->get_custom_data();
        $assignmentid = $customdata->assignment;
        $userid = $customdata->userid;
        $action = $customdata->action ?? 'generate';

        // Create the assign instance.
        [$course, $cm] = get_course_and_cm_from_instance($assignmentid, 'assign');
        $context = \core\context\module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);

        if ($action === 'delete') {
            $record = $this->get_submission_record($assignmentid, $userid);
            $this->delete_feedback($record, $assignmentid);
            $this->progress->update_full(100, get_string('feedbackgenerationcomplete', 'assignfeedback_aif'));
            return;
        }

        // Generate feedback for the single user.
        $record = $this->get_submission_record($assignmentid, $userid);

        // Determine triggeredby: if the task user matches the submission user, it's auto.
        $triggeredby = ((int) $this->get_userid() === $userid) ? 'auto' : 'manual';

        $error = $this->generate_feedback($record, $triggeredby, $assign);
        if ($error !== null) {
            // Truncate to prevent a database error (stored_progress message limited to 255 chars).
            if (\core_text::strlen($error) > 255) {
                $error = \core_text::substr($error, 0, 252) . '...';
            }
            $this->progress->error($error);
        } else {
            $this->progress->update_full(100, get_string('feedbackgenerationcomplete', 'assignfeedback_aif'));
        }
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
     * Determine the user under whose identity all AI operations must be executed.
     *
     * The acting user is the task runner: an automatically triggered generation is queued
     * as the student who submitted, a manual or bulk generation as the teacher who triggered
     * it. The same identity is used for content extraction (image/PDF to text) and for the AI
     * feedback request, so availability checks, terms of use confirmation and quota are always
     * attributed to one and the same user. Tasks queued without a task runner fall back to the
     * student the feedback is generated for.
     *
     * @param \stdClass $record The submission record, used as fallback if the task has no runner.
     * @return \stdClass The acting user record.
     * @throws \moodle_exception If no valid acting user can be resolved.
     */
    private function get_acting_user(\stdClass $record): \stdClass {
        $actinguserid = (int) $this->get_userid();
        if ($actinguserid <= 0) {
            $actinguserid = (int) $record->userid;
        }

        if ($actinguserid <= 0) {
            throw new \moodle_exception('errornoactinguser', 'assignfeedback_aif');
        }

        $actinguser = \core_user::get_user($actinguserid);
        if (!$actinguser) {
            throw new \moodle_exception('errornoactinguser', 'assignfeedback_aif');
        }

        return $actinguser;
    }

    /**
     * Generate AI feedback for a submission.
     *
     * Reports granular progress steps: 10%, 30%, 50%, 90%.
     *
     * @param object|false $record The submission record.
     * @param string $triggeredby How the task was triggered: 'auto' (observer) or 'manual' (teacher).
     * @param \assign|null $assign The assign instance.
     * @return string|null Error message if generation failed, null on success.
     */
    private function generate_feedback(
        $record,
        string $triggeredby = 'manual',
        ?\assign $assign = null
    ): ?string {
        global $DB, $CFG;

        if (empty($record)) {
            mtrace("No submission found, skipping.");
            return get_string('errornosubmission', 'assignfeedback_aif');
        }

        // Step 1: Preparing submission data (10%).
        $this->progress->update_full(10, get_string('progresssteppreparing', 'assignfeedback_aif'));

        // Replace any existing feedback with a 'pending' lock record.
        // This ensures that even if the task crashes fatally, the record will not
        // be stuck in limbo (no record + autogenerate = infinite pending).
        $clock = \core\di::get(\core\clock::class);
        $now = $clock->now()->getTimestamp();
        $existing = $DB->get_record('assignfeedback_aif_feedback', [
            'aif' => $record->aifid,
            'submission' => $record->subid,
        ]);
        if ($existing) {
            $existing->status = 'pending';
            $existing->errormessage = null;
            $existing->feedback = '';
            $existing->timemodified = $now;
            $DB->update_record('assignfeedback_aif_feedback', $existing);
        } else {
            $lockrecord = (object) [
                'aif' => $record->aifid,
                'submission' => $record->subid,
                'feedback' => '',
                'feedbackformat' => FORMAT_HTML,
                'status' => 'pending',
                'errormessage' => null,
                'timecreated' => $now,
                'timemodified' => $now,
                'skippedfiles' => null,
            ];
            $DB->insert_record('assignfeedback_aif_feedback', $lockrecord);
        }

        // Use the context from the submission for proper permission checks.
        // Resolve through DI so the handler can be replaced in tests.
        $aif = \core\di::get(\assignfeedback_aif\aif::class);
        $aif->set_contextid($record->contextid);

        // Step 2: Extracting submission content (30%).
        $this->progress->update_full(30, get_string('progressstepextracting', 'assignfeedback_aif'));

        // Single source of truth for the identity under which all AI operations run
        // (content extraction as well as the feedback request itself).
        try {
            $actinguser = $this->get_acting_user($record);
        } catch (\moodle_exception $e) {
            $this->save_error_feedback($record, $e->getMessage());
            mtrace("Cannot determine the acting user for submission {$record->subid}: " . $e->getMessage());
            return $e->getMessage();
        }

        // All content (including images and PDFs) is now converted to text during
        // prompt building, so we always use the default feedback purpose.
        $provider = \core\di::get(\assignfeedback_aif\local\ai_request_provider::class);
        $purpose = 'feedback';

        // Everything from here on runs as the acting user, so availability checks, terms of
        // use and quota are attributed consistently to one user, regardless of whether the AI
        // backend takes the user from the passed parameters or from the global $USER.
        \core\cron::setup_user($actinguser);
        try {
            // Determine the actual grading method for this assignment.
            $context = \core\context::instance_by_id($record->contextid);
            $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
            $gradingmethod = $gradingmanager->get_active_method() ?: 'simple';

            try {
                $promptdata = $aif->get_prompt($record, $gradingmethod, (int) $actinguser->id);
            } catch (\Exception $e) {
                $this->save_error_feedback($record, $e->getMessage());
                mtrace("Failed to build prompt for submission {$record->subid}: " . $e->getMessage());
                return $e->getMessage();
            }
            if (empty($promptdata['prompt'])) {
                // Build an informative error message including skipped file details.
                $errormsg = get_string('erroremptysubmission', 'assignfeedback_aif');
                if (!empty($promptdata['skippedfiles'])) {
                    $filelist = [];
                    foreach ($promptdata['skippedfiles'] as $skipped) {
                        $reasonkey = $skipped['reason'] ?? 'skipreason_conversionnotsupported';
                        $reasondata = $skipped['reasondata'] ?? null;
                        $reason = get_string($reasonkey, 'assignfeedback_aif', $reasondata);
                        if (!empty($skipped['errormessage'])) {
                            $reason .= ': ' . $skipped['errormessage'];
                        }
                        $filelist[] = $skipped['filename'] . ' (' . $reason . ')';
                    }
                    $errormsg .= ' ' . get_string('errorskippedfilesdetail', 'assignfeedback_aif', implode(', ', $filelist));
                }
                $this->save_error_feedback($record, $errormsg);
                mtrace("No submission text found for submission {$record->subid}.");
                return $errormsg;
            }

            // Step 3: Requesting AI feedback (50%).
            $this->progress->update_full(50, get_string('progresssteprequesting', 'assignfeedback_aif'));

            $unavailablereason = $provider->get_unavailability_reason($purpose, $record->contextid);
            if ($unavailablereason !== null) {
                $errormsg = $unavailablereason;
                $this->save_error_feedback($record, $errormsg);
                mtrace("AI backend not available, skipping submission {$record->subid}: {$errormsg}");
                return $errormsg;
            }

            // The AI request runs under the very same identity that was already used for
            // content extraction.
            $aifeedback = $aif->perform_request(
                $promptdata['prompt'],
                (int) $actinguser->id,
                'feedback',
                $promptdata['options']
            );
        } catch (\Exception $e) {
            $debuginfo = ($e instanceof \moodle_exception && !empty($e->debuginfo)) ? $e->debuginfo : '';
            $this->save_error_feedback($record, $e->getMessage(), $debuginfo);
            mtrace("AI request failed for submission {$record->subid}: " . $e->getMessage());
            return $e->getMessage();
        } finally {
            \core\cron::setup_user();
        }

        // Step 4: Saving feedback (90%).
        $this->progress->update_full(90, get_string('progressstepsaving', 'assignfeedback_aif'));

        // Practice mode: only when auto-triggered (not teacher) and no marking workflow.
        $ispractice = ($triggeredby === 'auto') && $this->is_practice_mode($record->aid);

        // Append the appropriate disclaimer to feedback.
        $aifeedback = $aif->append_disclaimer($aifeedback, $ispractice);

        // Convert markdown to HTML so it can be displayed and edited in the TinyMCE editor.
        if (class_exists('\local_ai_manager\base_purpose')) {
            $purpose = new \local_ai_manager\base_purpose();
            $aifeedbackhtml = $purpose->format_ai_markdown_output($aifeedback, ['filter' => false]);
        } else {
            $aifeedbackhtml = format_text($aifeedback, FORMAT_MARKDOWN, ['filter' => false]);
        }

        $clock = \core\di::get(\core\clock::class);
        $now = $clock->now()->getTimestamp();

        // Update the pending lock record with the actual feedback.
        $feedbackrecord = $DB->get_record('assignfeedback_aif_feedback', [
            'aif' => $record->aifid,
            'submission' => $record->subid,
        ]);
        if ($feedbackrecord) {
            $feedbackrecord->feedback = $aifeedbackhtml;
            $feedbackrecord->feedbackformat = FORMAT_HTML;
            $feedbackrecord->status = 'completed';
            $feedbackrecord->errormessage = null;
            $feedbackrecord->timemodified = $now;
            $feedbackrecord->skippedfiles = !empty($promptdata['skippedfiles'])
                ? json_encode($promptdata['skippedfiles']) : null;
            $DB->update_record('assignfeedback_aif_feedback', $feedbackrecord);
        } else {
            // Fallback: record was unexpectedly removed, re-create it.
            $data = (object) [
                'aif' => $record->aifid,
                'feedback' => $aifeedbackhtml,
                'feedbackformat' => FORMAT_HTML,
                'status' => 'completed',
                'errormessage' => null,
                'timecreated' => $now,
                'timemodified' => $now,
                'submission' => $record->subid,
                'skippedfiles' => !empty($promptdata['skippedfiles'])
                    ? json_encode($promptdata['skippedfiles']) : null,
            ];
            $DB->insert_record('assignfeedback_aif_feedback', $data);
        }

        // Ensure a grade record exists so students can see feedback in the submission view.
        $this->ensure_grade_record($record, $assign);

        mtrace("AI feedback generated for assignment {$record->aid} submission {$record->subid}");

        return null;
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
     * Save an error feedback record so the teacher can see what went wrong.
     *
     * Creates or updates a feedback record with status='error' and stores
     * the error message in the dedicated errormessage field.
     *
     * @param object $record The submission record.
     * @param string $errormsg The error message to store.
     * @param string $debuginfo Optional debug info (stored in errormessage if non-empty).
     */
    private function save_error_feedback(object $record, string $errormsg, string $debuginfo = ''): void {
        global $DB;

        $clock = \core\di::get(\core\clock::class);
        $now = $clock->now()->getTimestamp();

        $fullmsg = $errormsg;
        if ($debuginfo !== '') {
            $fullmsg .= ' | ' . $debuginfo;
        }

        // Check if a record already exists (e.g. a pending lock record).
        $existing = $DB->get_record('assignfeedback_aif_feedback', [
            'aif' => $record->aifid,
            'submission' => $record->subid,
        ]);

        if ($existing) {
            $existing->status = 'error';
            $existing->errormessage = $fullmsg;
            $existing->feedback = '';
            $existing->timemodified = $now;
            $DB->update_record('assignfeedback_aif_feedback', $existing);
        } else {
            $data = (object) [
                'aif' => $record->aifid,
                'feedback' => '',
                'feedbackformat' => FORMAT_HTML,
                'status' => 'error',
                'errormessage' => $fullmsg,
                'timecreated' => $now,
                'timemodified' => $now,
                'submission' => $record->subid,
                'skippedfiles' => null,
            ];
            $DB->insert_record('assignfeedback_aif_feedback', $data);
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
