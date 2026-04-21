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
    /** @var string File component for AI feedback. */
    const COMPONENT = 'assignfeedback_aif';

    /** @var string File area for AI feedback. */
    const FILEAREA = 'assignfeedback_aif_feedback';
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
        global $DB, $PAGE, $USER;

        $defaultprompt = get_config('assignfeedback_aif', 'prompt');

        $mform->addElement(
            'textarea',
            'assignfeedback_aif_prompt',
            get_string('prompt', 'assignfeedback_aif'),
            ['size' => 70, 'rows' => 10]
        );
        $mform->setDefault('assignfeedback_aif_prompt', $defaultprompt);
        $mform->setType('assignfeedback_aif_prompt', PARAM_RAW);
        $PAGE->requires->js_call_amd(
            'local_ai_manager/infobox',
            'renderInfoBox',
            ['local_myplugin', $USER->id, '[data-myplugin="aiinfo"]', ['singleprompt', 'translate']]
        );
        // Expert mode template button (only shown when admin setting is enabled).
        if (get_config('assignfeedback_aif', 'enableexpertmode')) {
            $mform->addElement(
                'button',
                'assignfeedback_aif_expertmodebtn',
                get_string('useexpertmodetemplate', 'assignfeedback_aif')
            );
            $mform->hideIf('assignfeedback_aif_expertmodebtn', 'assignfeedback_aif_enabled', 'notchecked');

            // Embed the prompt template as data-attribute so JS can read it directly
            // without an extra AJAX call.
            $prompttemplate = get_config('assignfeedback_aif', 'prompttemplate') ?: '';
            global $PAGE;
            $PAGE->requires->js_call_amd('assignfeedback_aif/expertmode', 'init', [$prompttemplate]);
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

        // Show info box about AI control center when block_ai_control is installed and active.
        $enabledblocks = \core_plugin_manager::instance()->get_enabled_plugins('block');
        if (isset($enabledblocks['ai_control'])) {
            $mform->addElement(
                'static',
                'assignfeedback_aif_aicontrolnotice',
                '',
                \html_writer::div(
                    get_string('aicontrolnotice', 'assignfeedback_aif'),
                    'alert alert-info'
                )
            );
            $mform->hideIf('assignfeedback_aif_aicontrolnotice', 'assignfeedback_aif_enabled', 'notchecked');
            $mform->hideIf('assignfeedback_aif_aicontrolnotice', 'assignfeedback_aif_autogenerate', 'notchecked');
        }

        // Show data sharing notice from AI Manager (only when the plugin is installed).
        if (\core_plugin_manager::instance()->get_plugin_info('local_ai_manager')) {
            $mform->addElement(
                'static',
                'assignfeedback_aif_datasharingnotice',
                '',
                \html_writer::div(
                    get_string('aiisbeingused', 'local_ai_manager'),
                    'alert alert-warning'
                )
            );
            $mform->hideIf('assignfeedback_aif_datasharingnotice', 'assignfeedback_aif_enabled', 'notchecked');
        }

        // Prompt file upload (only shown when admin setting is enabled).
        if (get_config('assignfeedback_aif', 'enablepromptfile')) {
            $mform->addElement(
            'filemanager',
            'assignfeedback_aif_file',
            get_string('file', 'assignfeedback_aif'),
            null,
            [
                'maxfiles' => 1,
                'maxbytes' => 1024 * 1024 * 10,
            ]
        );

            $mform->addHelpButton('assignfeedback_aif_file', 'file', 'assignfeedback_aif');
            $mform->hideIf('assignfeedback_aif_file', 'assignfeedback_aif_enabled', 'notchecked');
        }

        $mform->addHelpButton('assignfeedback_aif_prompt', 'prompt', 'assignfeedback_aif');
        // Disable prompt if AI assisted feedback plugin is disabled.
        $mform->hideIf('assignfeedback_aif_prompt', 'assignfeedback_aif_enabled', 'notchecked');

        // Read settings from the plugin's own table rather than assign_plugin_config
        // because the AIF config record stores additional fields (autogenerate, prompt)
        // that go beyond what the base class get_config()/set_config() supports.

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
                return \assignfeedback_aif\local\feedback_utils::save_feedback(
                    $grade->assignment,
                    $grade->userid,
                    $value,
                    FORMAT_HTML
                );
            }
        }
        return false;
    }

    /**
     * Get form elements for the grading page
     *
     * @param stdClass|null $submissionorgrade
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @param int $userid
     * @return bool true if elements were added to the form
     */
    public function get_form_elements_for_user($submissionorgrade, MoodleQuickForm $mform, stdClass $data, $userid): bool {
        global $DB, $PAGE, $USER;

        // Get the existing feedback.
        $record = $this->get_feedbackaif($submissionorgrade->assignment, $submissionorgrade->userid);

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
            $submissionorgrade->id
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
        \assignfeedback_aif\local\output_helper::render_ai_manager_widgets($mform, $USER->id);

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
        $prompt = $data->assignfeedback_aif_prompt;
        $autogenerate = !empty($data->assignfeedback_aif_autogenerate) ? 1 : 0;
        return \assignfeedback_aif\local\feedback_utils::save_settings(
            $this->assignment->get_instance()->id,
            $prompt,
            $autogenerate
        );
    }

    /**
     * Save the AI feedback to the database.
     *
     * @param stdClass $submissionorgrade The grade object.
     * @param stdClass $data The form data.
     * @return bool
     */
    public function save(stdClass $submissionorgrade, stdClass $data): bool {
        // Process the editor files.
        $data = file_postupdate_standard_editor(
            $data,
            'assignfeedbackaif',
            $this->get_editor_options(),
            $this->assignment->get_context(),
            self::COMPONENT,
            self::FILEAREA,
            $submissionorgrade->id
        );

        return \assignfeedback_aif\local\feedback_utils::save_feedback(
            $submissionorgrade->assignment,
            $submissionorgrade->userid,
            $data->assignfeedbackaif,
            $data->assignfeedbackaifformat
        );
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
            redirect(
                $gradingurl,
                get_string('regenerate_queued', 'assignfeedback_aif'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        if ($action == 'deletefeedbackai') {
            \assignfeedback_aif\local\feedback_utils::delete_feedback_for_users(
                $this->assignment->get_instance()->id,
                $users
            );
            redirect(
                $gradingurl,
                get_string('batchdeletefeedbackcomplete', 'assignfeedback_aif'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
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
     * Display the AI feedback in the feedback table.
     *
     * When feedback does not exist yet but generation is pending (autogenerate
     * enabled, submission present), a spinner and polling script are rendered
     * so the page refreshes automatically once feedback arrives.
     *
     * @param stdClass $submissionorgrade The grade object.
     * @param bool $showviewlink Set to true to show a link to view the full feedback.
     * @return string The formatted feedback summary.
     */
    public function view_summary(stdClass $submissionorgrade, &$showviewlink): string {
        $record = $this->get_feedbackaif($submissionorgrade->assignment, $submissionorgrade->userid);
        if ($record) {
            // Check for error marker in skippedfiles.
            $errormsg = $this->get_error_from_feedback($record);
            if ($errormsg !== null) {
                return \assignfeedback_aif\local\output_helper::render_error_with_retry(
                    $errormsg,
                    $submissionorgrade->assignment,
                    $submissionorgrade->userid
                );
            }
            $format = $record->feedbackformat ?? FORMAT_HTML;
            $text = format_text($record->feedback, $format, [
                'context' => $this->assignment->get_context(),
            ]);
            // Truncate for the grading table overview. Full feedback via "view" link.
            $shorttext = shorten_text(strip_tags($text), 140);
            $showviewlink = ($shorttext !== strip_tags($text));
            return \assignfeedback_aif\local\output_helper::render_warningbox() . $shorttext;
        }

        // No feedback yet — check for running task with stored progress or pending autogenerate.
        $progressid = $this->get_running_progress_id($submissionorgrade->assignment, $submissionorgrade->userid);
        if ($progressid > 0) {
            return \assignfeedback_aif\local\output_helper::render_generating_progress(
                $submissionorgrade->assignment,
                $submissionorgrade->userid,
                $progressid
            );
        }
        if ($this->is_feedback_pending($submissionorgrade->assignment, $submissionorgrade->userid)) {
            return \assignfeedback_aif\local\output_helper::render_generating_spinner(
                $submissionorgrade->assignment,
                $submissionorgrade->userid
            );
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
        return \assignfeedback_aif\local\feedback_utils::get_feedbackaif($assignment, $userid);
    }

    /**
     * Check if there is a running adhoc task with stored progress for this assignment and user.
     *
     * @param int $assignmentid The assignment instance ID.
     * @param int $userid The user ID.
     * @return int The stored_progress record ID, or 0 if no running task.
     */
    private function get_running_progress_id(int $assignmentid, int $userid): int {
        return \assignfeedback_aif\local\feedback_utils::get_running_progress_id($assignmentid, $userid);
    }

    /**
     * Display the full feedback.
     *
     * @param stdClass $submissionorgrade The grade object.
     * @return string The formatted feedback text.
     */
    public function view(stdClass $submissionorgrade): string {
        $record = $this->get_feedbackaif($submissionorgrade->assignment, $submissionorgrade->userid);
        if (!$record) {
            return '';
        }

        // Check for error marker in skippedfiles.
        $errormsg = $this->get_error_from_feedback($record);
        if ($errormsg !== null) {
            return \assignfeedback_aif\local\output_helper::render_error_with_retry(
                $errormsg,
                $submissionorgrade->assignment,
                $submissionorgrade->userid
            );
        }

        $format = $record->feedbackformat ?? FORMAT_HTML;
        $text = format_text($record->feedback, $format, [
            'context' => $this->assignment->get_context(),
        ]);
        $result = \html_writer::div($text, 'assignfeedback_aif-feedback');
        $result .= \assignfeedback_aif\local\output_helper::format_skipped_files_notice($record);

        return $result;
    }

    /**
     * Extract error message from a feedback record's skippedfiles JSON.
     *
     * @param stdClass $record The feedback record.
     * @return string|null The error message, or null if no error.
     */
    private function get_error_from_feedback(stdClass $record): ?string {
        return \assignfeedback_aif\local\feedback_utils::get_error_from_feedback($record);
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
        return \assignfeedback_aif\local\feedback_utils::delete_all_feedback(
            $this->assignment->get_instance()->id
        );
    }

    /**
     * Return a list of the file areas used by this plugin.
     *
     * This is used by mod_assign during reset_userdata and delete_instance to clean up stored files.
     *
     * @return array Associative array mapping filearea => human readable name.
     */
    public function get_file_areas(): array {
        return [self::FILEAREA => $this->get_name()];
    }

    /**
     * Returns true if there are no AI feedback entries for the given grade.
     *
     * Also returns false when feedback generation is pending so that the
     * feedback section is rendered and the spinner can be displayed.
     *
     * @param stdClass $submissionorgrade The grade object.
     * @return bool True if no feedback exists and none is pending.
     */
    public function is_empty(stdClass $submissionorgrade): bool {
        if ($this->get_feedbackaif($submissionorgrade->assignment, $submissionorgrade->userid)) {
            return false;
        }
        // Show section when feedback is still being generated.
        if ($this->is_feedback_pending($submissionorgrade->assignment, $submissionorgrade->userid)) {
            return false;
        }
        return true;
    }

    /**
     * Check whether AI feedback generation is pending for a submission.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return bool True if feedback generation is expected but not yet complete.
     */
    private function is_feedback_pending(int $assignmentid, int $userid): bool {
        return \assignfeedback_aif\local\feedback_utils::is_feedback_pending($assignmentid, $userid);
    }

    /**
     * Check whether a spinner has already been rendered on this page.
     *
     * Used by the before_footer hook to avoid duplicate spinners.
     *
     * @return bool True if the spinner was already rendered.
     */
    public static function is_spinner_rendered(): bool {
        return \assignfeedback_aif\local\output_helper::is_spinner_rendered();
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
