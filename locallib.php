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

// File component for AI feedback.
define('ASSIGNFEEDBACK_AIF_COMPONENT', 'assignfeedback_aif');

// File area for AI feedback.
define('ASSIGNFEEDBACK_AIF_FILEAREA', 'feedback');

/**
 * Library class for AI feedback plugin extending feedback plugin base class.
 *
 * @package   assignfeedback_aif
 * @copyright 2024 Marcus Green
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_feedback_aif extends assign_feedback_plugin {
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
            'noclean' => true,
            'trusttext' => true,
        ];
    }

    /**
     * Get the default setting for feedback comments plugin.
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

        // Expert mode template button (only shown when admin setting is enabled).
        if (get_config('assignfeedback_aif', 'enableexpertmode')) {
            $mform->addElement(
                'button',
                'assignfeedback_aif_expertmodebtn',
                get_string('useexpertmodetemplate', 'assignfeedback_aif')
            );
            $mform->hideIf('assignfeedback_aif_expertmodebtn', 'assignfeedback_aif_enabled', 'notchecked');

            // Initialize the expert mode JS module with the admin template.
            global $PAGE;
            $experttemplate = get_config('assignfeedback_aif', 'prompttemplate');
            if (empty($experttemplate)) {
                $experttemplate = get_string('defaultprompttemplate', 'assignfeedback_aif');
            }
            $PAGE->requires->js_call_amd('assignfeedback_aif/expertmode', 'init', [$experttemplate]);
        }

        // Auto-generate on submission checkbox.
        $mform->addElement(
            'advcheckbox',
            'assignfeedback_aif_autogenerate',
            get_string('autogenerate', 'assignfeedback_aif'),
            ' ',
            [],
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
        // Disable Prompt if AI assisted feedback if comment feedback plugin is disabled.
        $mform->hideIf('assignfeedback_aif_prompt', 'assignfeedback_aif_enabled', 'notchecked');

        $mform->addHelpButton('assignfeedback_aif_file', 'file', 'assignfeedback_aif');
        $mform->hideIf('assignfeedback_aif_file', 'assignfeedback_aif_enabled', 'notchecked');

        global $DB;

        $record = $DB->get_record('assignfeedback_aif', ['assignment' => $this->assignment->get_instance()->id]);
        if ($record) {
            $mform->setDefault('assignfeedback_aif_prompt', $record->prompt);
            $mform->setDefault('assignfeedback_aif_autogenerate', $record->autogenerate ?? 0);
        }
    }

    /**
     * Preprocessing data for the form.
     *
     * @param array $defaultvalues The default values for the form.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues): void {
    }
    /**
     * Has the comment feedback been modified?
     *
     * @param stdClass $grade The grade object.
     * @param stdClass $data Data from the form submission.
     * @return boolean True if the comment feedback has been modified, else false.
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
                    $record->timecreated = $clock->now()->getTimestamp();
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
        global $PAGE;

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
            ASSIGNFEEDBACK_AIF_COMPONENT,
            ASSIGNFEEDBACK_AIF_FILEAREA,
            $grade->id
        );

        $mform->addElement('editor', 'assignfeedbackaif_editor', $this->get_name(), null, $this->get_editor_options());

        // Add regenerate button.
        $assignmentid = $this->assignment->get_instance()->id;
        $buttonhtml = html_writer::tag(
            'button',
            get_string('regenerate', 'assignfeedback_aif'),
            [
                'type' => 'button',
                'class' => 'btn btn-secondary mt-2',
                'data-action' => 'regenerate-aif',
                'data-assignmentid' => $assignmentid,
                'data-userid' => $userid,
            ]
        );
        $mform->addElement('html', $buttonhtml);

        // Initialize the AMD module.
        $PAGE->requires->js_call_amd(
            'assignfeedback_aif/regenerate',
            'init',
            [$assignmentid, $userid]
        );

        return true;
    }
    /**
     * Save the settings for feedback comments plugin.
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
     * Saving the comment content into database.
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
            ASSIGNFEEDBACK_AIF_COMPONENT,
            ASSIGNFEEDBACK_AIF_FILEAREA,
            $grade->id
        );

        $record = $this->get_feedbackaif($grade->assignment, $grade->userid);

        if ($record) {
            $record->timecreated = $clock->now()->getTimestamp();
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
                'icon' => $OUTPUT->pix_icon('i/upload', ''),
                'confirmationtitle' => get_string('generatefeedbackai', 'assignfeedback_aif'),
                'confirmationquestion' => get_string('batchoperationconfirmgeneratefeedbackai', 'assignfeedback_aif'),
            ],
            (object) [
                'key' => 'deletefeedbackai',
                'label' => get_string('batchoperationdeletefeedbackai', 'assignfeedback_aif'),
                'icon' => $OUTPUT->pix_icon('i/upload', ''),
                'confirmationtitle' => get_string('deletefeedbackai', 'assignfeedback_aif'),
                'confirmationquestion' => get_string('batchoperationconfirmdeletefeedbackai', 'assignfeedback_aif'),
            ],
        ];
    }

    /**
     * User has chosen a custom grading batch operation and selected some users.
     *
     * @param string $action The chosen action.
     * @param array $users An array of user ids.
     * @return string The response html.
     */
    public function grading_batch_operation($action, $users): string {
        // Currently only supports rubric grading method.
        if ($action == 'generatefeedbackai') {
            $this->process_feedbackaif($users, 'generate');
        }
        if ($action == 'deletefeedbackai') {
            $this->process_feedbackaif($users, 'delete');
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
        $task = new \assignfeedback_aif\task\process_feedback_rubric_adhoc();
        $task->set_custom_data([
            'assignment' => $this->assignment->get_instance()->id,
            'users' => $users,
            'action' => $action,
            'triggeredby' => 'manual',
        ]);
        global $USER;
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task, true);

        redirect(new moodle_url('view.php', [
            'id' => $this->assignment->get_course_module()->id,
            'action' => 'grading',
        ]), get_string('processfeedbackainotify', 'assignfeedback_aif'));
    }

    /**
     * Display the comment in the feedback table.
     *
     * @param stdClass $grade The grade object.
     * @param bool $showviewlink Set to true to show a link to view the full feedback.
     * @return string The formatted feedback summary.
     */
    public function view_summary(stdClass $grade, &$showviewlink): string {
        $record = $this->get_feedbackaif($grade->assignment, $grade->userid);
        if (!$record) {
            return '';
        }
        $format = $record->feedbackformat ?? FORMAT_HTML;
        return format_text($record->feedback, $format, [
            'context' => $this->assignment->get_context(),
        ]);
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
     * Display the full feedback.
     *
     * @param stdClass $grade The grade object.
     * @return string The formatted feedback text.
     */
    public function view(stdClass $grade): string {
        $record = $this->get_feedbackaif($grade->assignment, $grade->userid);
        if (!$record) {
            return '';
        }
        $format = $record->feedbackformat ?? FORMAT_HTML;
        return format_text($record->feedback, $format, [
            'context' => $this->assignment->get_context(),
        ]);
    }

    /**
     * If this plugin adds to the gradebook comments field, it must format the text
     * of the comment.
     *
     * Only one feedback plugin can push comments to the gradebook and that is chosen by the assignment
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
     * Returns true if there are no feedback comments for the given grade.
     *
     * @param stdClass $grade The grade object.
     * @return bool True if no feedback exists.
     */
    public function is_empty(stdClass $grade): bool {
        return $this->view($grade) === '';
    }

    /**
     * Return a description of external params suitable for uploading an feedback comment from a webservice.
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
