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

/**
 * Main class for AI Feedback feedback plugin.
 *
 * @package    assignfeedback_aif
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Library class for AI feedback plugin extending feedback plugin base class.
 *
 * @package   assignfeedback_aif
 * @copyright 2024 Marcus Green
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_feedback_aif extends assign_feedback_plugin {
    /** @var bool Whether the generating spinner has already been rendered on this page. */
    private static bool $spinnerrendered = false;

    /** @var string File component for AI feedback. */
    const COMPONENT = 'assignfeedback_aif';

    /** @var string File area for AI feedback. */
    const FILEAREA = 'feedback';
    /**
     * Should return the name of this plugin type.
     *
     * @return string - the name
     */
    public function get_name(): string {
        return get_string('pluginname', 'assignfeedback_aif');
    }

    /**
     * Get editor options for the feedback editor.
     *
     * @return array
     */
    public function get_editor_options(): array {
        return [
            'subdirs' => 1,
            'maxbytes' => $this->assignment->get_course()->maxbytes,
            'maxfiles' => -1,
            'context' => $this->assignment->get_context(),
        ];
    }

    /**
     * Get the settings for AI feedback plugin.
     *
     * @param MoodleQuickForm $mform The form to add elements to.
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform): void {

        $defaultprompt = get_config('assignfeedback_aif', 'prompt');

        $mform->addElement(
            'textarea',
            'assignfeedback_aif_prompt',
            get_string('prompt', 'assignfeedback_aif'),
            ['size' => 70, 'rows' => 10]
        );
        $mform->setDefault('assignfeedback_aif_prompt', $defaultprompt);
        $mform->setType('assignfeedback_aif_prompt', PARAM_RAW);

        // Expert mode template button (only shown when admin setting is enabled).
        if (get_config('assignfeedback_aif', 'enableexpertmode')) {
            $mform->addElement(
                'button',
                'assignfeedback_aif_expertmodebtn',
                get_string('useexpertmodetemplate', 'assignfeedback_aif')
            );
            $mform->hideIf('assignfeedback_aif_expertmodebtn', 'assignfeedback_aif_enabled', 'notchecked');

            // Pass the expert template via data attribute to avoid exceeding the
            // 1024-char limit of js_call_amd arguments.
            global $PAGE;
            $experttemplate = get_config('assignfeedback_aif', 'prompttemplate');
            if (empty($experttemplate)) {
                $experttemplate = get_string('defaultprompttemplate', 'assignfeedback_aif');
            }
            $mform->addElement(
                'html',
                '<div id="aif-expertmode-data" data-template="' . s($experttemplate) . '" class="hidden"></div>'
            );
            $PAGE->requires->js_call_amd('assignfeedback_aif/expertmode', 'init');
        }

        // Auto-generate on submission checkbox.
        $mform->addElement(
            'advcheckbox',
            'assignfeedback_aif_autogenerate',
            get_string('autogenerate', 'assignfeedback_aif'),
            '',
            ['id' => 'id_assignfeedback_aif_autogenerate'],
            [0, 1]
        );
        $mform->setDefault('assignfeedback_aif_autogenerate', 0);
        $mform->addHelpButton('assignfeedback_aif_autogenerate', 'autogenerate', 'assignfeedback_aif');
        $mform->hideIf('assignfeedback_aif_autogenerate', 'assignfeedback_aif_enabled', 'notchecked');

        $mform->addElement(
            'filemanager',
            'assignfeedback_aif_file',
            get_string('file', 'assignfeedback_aif'),
            ['maxfiles' => 1, 'maxfilesize' => '10MB']
        );

        $mform->addHelpButton('assignfeedback_aif_prompt', 'prompt', 'assignfeedback_aif');
        // Disable prompt if AI assisted feedback plugin is disabled.
        $mform->hideIf('assignfeedback_aif_prompt', 'assignfeedback_aif_enabled', 'notchecked');

        $mform->addHelpButton('assignfeedback_aif_file', 'file', 'assignfeedback_aif');
        $mform->hideIf('assignfeedback_aif_file', 'assignfeedback_aif_enabled', 'notchecked');

        global $DB;

        $instance = $this->assignment->get_default_instance();
        if ($instance && !empty($instance->id)) {
            $record = $DB->get_record('assignfeedback_aif', ['assignment' => $instance->id]);
            if ($record) {
                $mform->setDefault('assignfeedback_aif_prompt', $record->prompt);
                $mform->setDefault('assignfeedback_aif_autogenerate', $record->autogenerate ?? 0);
            }
        }
    }
    /**
     * Has the AI feedback been modified?
     *
     * @param stdClass $grade The grade object.
     * @param stdClass $data Data from the form submission.
     * @return boolean True if the AI feedback has been modified, else false.
     */
    public function is_feedback_modified(stdClass $grade, stdClass $data): bool {
        $record = $this->get_feedbackaif($grade->assignment, $grade->userid);
        $oldvalue = $record ? $record->feedback : '';

        // Get the new value from the editor.
        if (isset($data->assignfeedbackaif_editor['text'])) {
            $newvalue = $data->assignfeedbackaif_editor['text'];
        } else {
            $newvalue = '';
        }

        return $oldvalue !== $newvalue;
    }

    /**
     * Return a list of the text fields that can be imported/exported by this plugin.
     *
     * @return array An array of field names and descriptions. (name=>description, ...)
     */
    public function get_editor_fields(): array {
        return ['aif' => get_string('pluginname', 'assignfeedback_aif')];
    }

    /**
     * Get the saved text content from the editor.
     *
     * @param string $name The field name.
     * @param int $gradeid The grade ID.
     * @return string The saved text content.
     */
    public function get_editor_text($name, $gradeid): string {
        global $DB;
        if ($name === 'aif') {
            // Get the grade to find the assignment and user.
            $grade = $DB->get_record('assign_grades', ['id' => $gradeid]);
            if ($grade) {
                $record = $this->get_feedbackaif($grade->assignment, $grade->userid);
                return $record ? $record->feedback : '';
            }
        }
        return '';
    }

    /**
     * Set the saved text content from the editor.
     *
     * @param string $name The field name.
     * @param string $value The text value to save.
     * @param int $gradeid The grade ID.
     * @return bool True if the text was saved.
     */
    public function set_editor_text($name, $value, $gradeid): bool {
        global $DB;
        if ($name === 'aif') {
            // Get the grade to find the assignment and user.
            $grade = $DB->get_record('assign_grades', ['id' => $gradeid]);
            if ($grade) {
                $record = $this->get_feedbackaif($grade->assignment, $grade->userid);
                if ($record) {
                    $clock = \core\di::get(\core\clock::class);
                    $record->feedback = $value;
                    $record->feedbackformat = FORMAT_HTML;
                    $record->timemodified = $clock->now()->getTimestamp();
                    $DB->update_record('assignfeedback_aif_feedback', $record);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get form elements for the grading page
     *
     * @param stdClass|null $grade
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @param int $userid
     * @return bool true if elements were added to the form
     */
    public function get_form_elements_for_user($grade, MoodleQuickForm $mform, stdClass $data, $userid): bool {
        global $DB, $PAGE, $USER;

        // Get the existing feedback.
        $record = $this->get_feedbackaif($grade->assignment, $grade->userid);

        // Check first for data from last form submission in case grading validation failed.
        if (!empty($data->assignfeedbackaif_editor['text'])) {
            $data->assignfeedbackaif = $data->assignfeedbackaif_editor['text'];
            $data->assignfeedbackaifformat = $data->assignfeedbackaif_editor['format'];
        } else if ($record && !empty($record->feedback)) {
            $data->assignfeedbackaif = $record->feedback;
            $data->assignfeedbackaifformat = $record->feedbackformat ?? FORMAT_HTML;
        } else {
            $data->assignfeedbackaif = '';
            $data->assignfeedbackaifformat = FORMAT_HTML;
        }

        // Prepare the editor with files.
        file_prepare_standard_editor(
            $data,
            'assignfeedbackaif',
            $this->get_editor_options(),
            $this->assignment->get_context(),
            self::COMPONENT,
            self::FILEAREA,
            $grade->id
        );

        $mform->addElement('editor', 'assignfeedbackaif_editor', $this->get_name(), null, $this->get_editor_options());

        // Add (re-)generate button.
        $assignmentid = $this->assignment->get_instance()->id;

        // Check if the student has a submission (to disable button if not).
        $hassubmission = $DB->record_exists('assign_submission', [
            'assignment' => $assignmentid,
            'userid' => $userid,
            'status' => 'submitted',
            'latest' => 1,
        ]);

        $buttonhtml = html_writer::tag(
            'button',
            get_string('generatefeedbackai', 'assignfeedback_aif'),
            [
                'type' => 'button',
                'class' => 'btn btn-secondary mt-2 mb-3',
                'data-action' => 'regenerate-aif',
                'data-assignmentid' => $assignmentid,
                'data-userid' => $userid,
                'disabled' => $hassubmission ? null : 'disabled',
            ]
        );
        $mform->addElement('html', $buttonhtml);

        // Check for a running adhoc task with stored progress for this assignment+user.
        $runningprogressid = $this->get_running_progress_id($assignmentid, $userid);

        // Local ai_manager widgets: infobox (data sharing notice) and quota.
        if (get_config('assignfeedback_aif', 'backend') === 'local_ai_manager') {
            $mform->addElement('html', '<div data-aif="aiinfo"></div>');
            $mform->addElement('html', '<div data-aif="aiuserquota" class="mb-2"></div>');
            $PAGE->requires->js_call_amd(
                'local_ai_manager/infobox',
                'renderInfoBox',
                ['assignfeedback_aif', $USER->id, '[data-aif="aiinfo"]', ['feedback', 'itt']]
            );
            $PAGE->requires->js_call_amd(
                'local_ai_manager/userquota',
                'renderUserQuota',
                ['[data-aif="aiuserquota"]', ['feedback', 'itt']]
            );
        }

        // Initialize the AMD module. Pass running progress ID to resume polling on page load.
        $PAGE->requires->js_call_amd(
            'assignfeedback_aif/regenerate',
            'init',
            [$assignmentid, $userid, $runningprogressid]
        );

        return true;
    }
    /**
     * Save the settings for AI feedback plugin.
     *
     * @param stdClass $data The form data.
     * @return bool
     */
    public function save_settings(stdClass $data): bool {
        global $DB;
        $prompt = $data->assignfeedback_aif_prompt;
        $autogenerate = !empty($data->assignfeedback_aif_autogenerate) ? 1 : 0;
        $assignment = $this->assignment->get_instance()->id;
        $feedback = $DB->get_record('assignfeedback_aif', ['assignment' => $assignment]);
        if ($feedback) {
            $feedback->prompt = $prompt;
            $feedback->autogenerate = $autogenerate;
            $DB->update_record('assignfeedback_aif', $feedback);
        } else {
            $clock = \core\di::get(\core\clock::class);
            $feedback = new stdClass();
            $feedback->prompt = $prompt;
            $feedback->autogenerate = $autogenerate;
            $feedback->assignment = $assignment;
            $feedback->timecreated = $clock->now()->getTimestamp();
            $DB->insert_record('assignfeedback_aif', $feedback);
        }
        return true;
    }

    /**
     * Save the AI feedback to the database.
     *
     * @param stdClass $grade The grade object.
     * @param stdClass $data The form data.
     * @return bool
     */
    public function save(stdClass $grade, stdClass $data): bool {
        global $DB;

        $clock = \core\di::get(\core\clock::class);

        // Process the editor files.
        $data = file_postupdate_standard_editor(
            $data,
            'assignfeedbackaif',
            $this->get_editor_options(),
            $this->assignment->get_context(),
            self::COMPONENT,
            self::FILEAREA,
            $grade->id
        );

        $record = $this->get_feedbackaif($grade->assignment, $grade->userid);

        if ($record) {
            $record->timemodified = $clock->now()->getTimestamp();
            $record->feedback = $data->assignfeedbackaif;
            $record->feedbackformat = $data->assignfeedbackaifformat;
            $DB->update_record('assignfeedback_aif_feedback', $record);
        } else {
            // Create new record if none exists yet.
            $aif = $DB->get_record('assignfeedback_aif', [
                'assignment' => $this->assignment->get_instance()->id,
            ]);
            if ($aif) {
                $submission = $DB->get_record('assign_submission', [
                    'assignment' => $grade->assignment,
                    'userid' => $grade->userid,
                    'latest' => 1,
                ]);
                $newrecord = new stdClass();
                $newrecord->aif = $aif->id;
                $newrecord->submission = $submission ? $submission->id : null;
                $newrecord->feedback = $data->assignfeedbackaif;
                $newrecord->feedbackformat = $data->assignfeedbackaifformat;
                $newrecord->timecreated = $clock->now()->getTimestamp();
                $DB->insert_record('assignfeedback_aif_feedback', $newrecord);
            } else {
                debugging(
                    'assignfeedback_aif: No config record found for assignment, cannot save feedback.',
                    DEBUG_DEVELOPER
                );
            }
        }
        return true;
    }


    /**
     * Return a list of detailed batch grading operations supported by this plugin.
     *
     * @return array An array of objects containing batch operation details.
     */
    public function get_grading_batch_operation_details(): array {
        global $OUTPUT;

        return [
            (object) [
                'key' => 'generatefeedbackai',
                'label' => get_string('batchoperationgeneratefeedbackai', 'assignfeedback_aif'),
                'icon' => $OUTPUT->pix_icon('i/completion-auto-y', ''),
                'confirmationtitle' => get_string('generatefeedbackai', 'assignfeedback_aif'),
                'confirmationquestion' => get_string('batchoperationconfirmgeneratefeedbackai', 'assignfeedback_aif'),
            ],
            (object) [
                'key' => 'deletefeedbackai',
                'label' => get_string('batchoperationdeletefeedbackai', 'assignfeedback_aif'),
                'icon' => $OUTPUT->pix_icon('t/delete', ''),
                'confirmationtitle' => get_string('deletefeedbackai', 'assignfeedback_aif'),
                'confirmationquestion' => get_string('batchoperationconfirmdeletefeedbackai', 'assignfeedback_aif'),
            ],
        ];
    }

    /**
     * User has chosen a custom grading batch operation and selected some users.
     *
     * Redirects back to the grading table with a toast notification instead of
     * returning HTML, which would result in a blank page.
     *
     * @param string $action The chosen action.
     * @param array $users An array of user ids.
     * @return string The response html (never reached due to redirect).
     */
    public function grading_batch_operation($action, $users): string {
        $cmid = $this->assignment->get_course_module()->id;
        $gradingurl = new \moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grading']);

        if ($action == 'generatefeedbackai') {
            $this->process_feedbackaif($users, 'generate');
            redirect($gradingurl, get_string('regenerate_queued', 'assignfeedback_aif'),
                null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action == 'deletefeedbackai') {
            $this->delete_feedbackaif($users);
            redirect($gradingurl, get_string('batchdeletefeedbackcomplete', 'assignfeedback_aif'),
                null, \core\output\notification::NOTIFY_SUCCESS);
        }
        return '';
    }

    /**
     * Generate or delete AI feedback for the given users.
     *
     * @param array $users The user IDs to process.
     * @param string $action The action to perform ('generate' or 'delete').
     * @return void
     */
    public function process_feedbackaif(array $users, string $action): void {
        // Run an ad-hoc task to generate AI feedback for submission.
        $task = new \assignfeedback_aif\task\process_feedback_adhoc();
        $task->set_custom_data([
            'assignment' => $this->assignment->get_instance()->id,
            'users' => $users,
            'action' => $action,
            'triggeredby' => 'manual',
        ]);
        global $USER;
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Delete AI feedback synchronously for the given users.
     *
     * Unlike generate, delete is a fast DB operation that does not need
     * background processing. Running it synchronously ensures the grading
     * table reflects the deletion immediately after the batch operation.
     *
     * @param array $users The user IDs to delete feedback for.
     * @return void
     */
    private function delete_feedbackaif(array $users): void {
        global $DB;

        $assignmentid = $this->assignment->get_instance()->id;
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
     * Display the AI feedback in the feedback table.
     *
     * When feedback does not exist yet but generation is pending (autogenerate
     * enabled, submission present), a spinner and polling script are rendered
     * so the page refreshes automatically once feedback arrives.
     *
     * @param stdClass $grade The grade object.
     * @param bool $showviewlink Set to true to show a link to view the full feedback.
     * @return string The formatted feedback summary.
     */
    public function view_summary(stdClass $grade, &$showviewlink): string {
        $record = $this->get_feedbackaif($grade->assignment, $grade->userid);
        if ($record) {
            // Check for error marker in skippedfiles.
            $errormsg = $this->get_error_from_feedback($record);
            if ($errormsg !== null) {
                global $OUTPUT;
                return $OUTPUT->notification($errormsg, \core\output\notification::NOTIFY_ERROR);
            }
            $format = $record->feedbackformat ?? FORMAT_HTML;
            $text = format_text($record->feedback, $format, [
                'context' => $this->assignment->get_context(),
            ]);
            // Truncate for the grading table overview. Full feedback via "view" link.
            $shorttext = shorten_text(strip_tags($text), 140);
            $showviewlink = ($shorttext !== strip_tags($text));
            return s($shorttext) . $this->render_warningbox();
        }

        // No feedback yet — check for running task with stored progress or pending autogenerate.
        $progressid = $this->get_running_progress_id($grade->assignment, $grade->userid);
        if ($progressid > 0) {
            return $this->render_generating_progress($grade->assignment, $grade->userid, $progressid);
        }
        if ($this->is_feedback_pending($grade->assignment, $grade->userid)) {
            return $this->render_generating_spinner($grade->assignment, $grade->userid);
        }

        return '';
    }

    /**
     * Get AI feedback for a submission.
     *
     * @param int $assignment The assignment ID.
     * @param int $userid The user ID.
     * @return stdClass|false The feedback record or false if not found.
     */
    public function get_feedbackaif(int $assignment, int $userid): stdClass|false {
        global $DB;
        $sql = "SELECT aiff.*
                  FROM {assign} a
                  JOIN {assignfeedback_aif} aif ON aif.assignment = a.id
                  JOIN {assignfeedback_aif_feedback} aiff ON aiff.aif = aif.id
                  JOIN {assign_submission} sub ON sub.assignment = a.id AND aiff.submission = sub.id
                 WHERE a.id = :assignment AND sub.userid = :userid AND sub.latest = 1";
        $params = ['assignment' => $assignment, 'userid' => $userid];
        return $DB->get_record_sql($sql, $params);
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
    private function get_running_progress_id(int $assignmentid, int $userid): int {
        global $DB;

        $taskclass = \assignfeedback_aif\task\process_feedback_adhoc::class;

        // Find queued adhoc tasks for this class.
        $tasks = \core\task\manager::get_adhoc_tasks($taskclass);
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            if (
                isset($data->assignment) && (int) $data->assignment === $assignmentid
                && isset($data->users) && in_array($userid, (array) $data->users)
            ) {
                // Found a matching task — look up its stored_progress record.
                $idnumber = \core\output\stored_progress_bar::convert_to_idnumber(
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
     * Display the full feedback.
     *
     * @param stdClass $grade The grade object.
     * @return string The formatted feedback text.
     */
    public function view(stdClass $grade): string {
        global $OUTPUT;
        $record = $this->get_feedbackaif($grade->assignment, $grade->userid);
        if (!$record) {
            return '';
        }

        // Check for error marker in skippedfiles.
        $errormsg = $this->get_error_from_feedback($record);
        if ($errormsg !== null) {
            return $OUTPUT->notification($errormsg, \core\output\notification::NOTIFY_ERROR);
        }

        $format = $record->feedbackformat ?? FORMAT_HTML;
        $text = format_text($record->feedback, $format, [
            'context' => $this->assignment->get_context(),
        ]);
        $result = $text;

        // Show notice about skipped files.
        if (!empty($record->skippedfiles)) {
            $skipped = json_decode($record->skippedfiles, true);
            if (!empty($skipped)) {
                $filelist = [];
                foreach ($skipped as $entry) {
                    if (is_array($entry) && isset($entry['filename'])) {
                        $reasonkey = $entry['reason'] ?? 'skipreason_conversionnotsupported';
                        $reasondata = $entry['reasondata'] ?? null;
                        $reason = get_string($reasonkey, 'assignfeedback_aif', $reasondata);
                        $filelist[] = s($entry['filename']) . ' (' . s($reason) . ')';
                    } else {
                        // Legacy format: plain filename string.
                        $filelist[] = s($entry);
                    }
                }
                $result .= $OUTPUT->notification(
                    get_string('feedbackskippedfiles', 'assignfeedback_aif', implode(', ', $filelist)),
                    \core\output\notification::NOTIFY_WARNING
                );
            }
        }

        return $result . $this->render_warningbox();
    }

    /**
     * Extract error message from a feedback record's skippedfiles JSON.
     *
     * Error feedback records are stored with a special '_error' key in the
     * skippedfiles JSON when feedback generation fails. This allows the error
     * to persist and be visible even after the adhoc task has been cleaned up.
     *
     * @param stdClass $record The feedback record.
     * @return string|null The error message, or null if no error.
     */
    private function get_error_from_feedback(stdClass $record): ?string {
        if (empty($record->skippedfiles)) {
            return null;
        }
        $skipped = json_decode($record->skippedfiles, true);
        if (!empty($skipped) && is_array($skipped)) {
            foreach ($skipped as $entry) {
                if (is_array($entry) && isset($entry['_error'])) {
                    return get_string('feedbackgenerationerror', 'assignfeedback_aif', s($entry['_error']));
                }
            }
        }
        return null;
    }

    /**
     * Render the ai_manager warning box about AI result quality.
     *
     * Only renders when the local_ai_manager backend is configured.
     * The JS AMD call is registered only once per page to prevent
     * duplicate warning boxes when multiple feedback views exist.
     *
     * @return string HTML for the warning box container.
     */
    private function render_warningbox(): string {
        if (get_config('assignfeedback_aif', 'backend') !== 'local_ai_manager') {
            return '';
        }
        static $jsregistered = false;
        if (!$jsregistered) {
            global $PAGE;
            $PAGE->requires->js_call_amd(
                'local_ai_manager/warningbox',
                'renderWarningBox',
                ['[data-aif="aiwarning"]']
            );
            $jsregistered = true;
        }
        return '<div data-aif="aiwarning"></div>';
    }

    /**
     * If this plugin adds to the gradebook, it must format the text
     * of the AI feedback.
     *
     * Only one feedback plugin can push feedback to the gradebook and that is chosen by the assignment
     * settings page.
     *
     * @param stdClass $grade The grade object.
     * @return string The feedback text for the gradebook.
     */
    public function text_for_gradebook(stdClass $grade): string {
        $record = $this->get_feedbackaif($grade->assignment, $grade->userid);
        if (!$record) {
            return '';
        }
        return $record->feedback ?? '';
    }

    /**
     * The assignment has been deleted - cleanup.
     *
     * @return bool
     */
    public function delete_instance(): bool {
        global $DB;
        $assignmentid = $this->assignment->get_instance()->id;
        $records = $DB->get_records('assignfeedback_aif', ['assignment' => $assignmentid], '', 'id');
        foreach ($records as $record) {
            $DB->delete_records('assignfeedback_aif_feedback', ['aif' => $record->id]);
        }
        $DB->delete_records(
            'assignfeedback_aif',
            ['assignment' => $assignmentid]
        );
        return true;
    }

    /**
     * Returns true if there are no AI feedback entries for the given grade.
     *
     * Also returns false when feedback generation is pending so that the
     * feedback section is rendered and the spinner can be displayed.
     *
     * @param stdClass $grade The grade object.
     * @return bool True if no feedback exists and none is pending.
     */
    public function is_empty(stdClass $grade): bool {
        if ($this->get_feedbackaif($grade->assignment, $grade->userid)) {
            return false;
        }
        // Show section when feedback is still being generated.
        if ($this->is_feedback_pending($grade->assignment, $grade->userid)) {
            return false;
        }
        return true;
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
    private function is_feedback_pending(int $assignmentid, int $userid): bool {
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
     * Render the stored progress bar and start polling for a running task.
     *
     * Used in the summary view when a task is actively running with stored progress.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @param int $progressid The stored_progress record ID.
     * @return string HTML with progress bar and JS initialisation.
     */
    private function render_generating_progress(int $assignmentid, int $userid, int $progressid): string {
        global $OUTPUT, $PAGE;
        $PAGE->requires->js_call_amd(
            'assignfeedback_aif/feedbackpoller',
            'initWithProgress',
            [$assignmentid, $userid, $progressid]
        );
        self::$spinnerrendered = true;
        return $OUTPUT->render_from_template('assignfeedback_aif/feedback_generating', [
            'message' => get_string('feedbackgenerating', 'assignfeedback_aif'),
        ]);
    }

    /**
     * Render the spinner and start the polling JS module.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return string HTML with spinner and JS initialisation.
     */
    private function render_generating_spinner(int $assignmentid, int $userid): string {
        global $OUTPUT, $PAGE;
        $PAGE->requires->js_call_amd(
            'assignfeedback_aif/feedbackpoller',
            'init',
            [$assignmentid, $userid]
        );
        self::$spinnerrendered = true;
        return $OUTPUT->render_from_template('assignfeedback_aif/feedback_generating', []);
    }

    /**
     * Check whether a spinner has already been rendered on this page.
     *
     * Used by the before_footer hook to avoid duplicate spinners.
     *
     * @return bool True if the spinner was already rendered.
     */
    public static function is_spinner_rendered(): bool {
        return self::$spinnerrendered;
    }

    /**
     * Return a description of external params suitable for uploading AI feedback from a webservice.
     *
     * Used in WebServices mod_assign_save_grade and mod_assign_save_grades.
     *
     * @return array The external parameters.
     */
    public function get_external_parameters(): array {
        return ['assignfeedbackaif' => new \core_external\external_value(PARAM_RAW, 'The text for this feedback.')];
    }

    /**
     * Return the plugin configs for external functions.
     *
     * @return array The list of settings.
     */
    public function get_config_for_external(): array {
        return (array) $this->get_config();
    }
}
