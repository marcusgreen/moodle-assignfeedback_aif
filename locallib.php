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
 * Main class for AI Feedback feedback plugin
 *
 * @package    assignfeedback_aif
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_feedback_aif extends assign_feedback_plugin {

    /**
     * Should return the name of this plugin type.
     *
     * @return string - the name
     */
    public function get_name() {
        return get_string('pluginname', 'assignfeedback_aif');
    }

    /**
     * Get the default setting for feedback comments plugin
     *
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform) {

        $defaultprompt = get_config('assignfeedback_aif', 'prompt');
        $mform->addElement('textarea',
                        'assignfeedback_aif_prompt',
                        get_string('prompt', 'assignfeedback_aif'),
                        ['size' => 70, 'rows' => 10]
                        );
        $mform->setDefault('assignfeedback_aif_prompt', $defaultprompt);

        $mform->addElement('filemanager', // or 'file' for simpler file selection
                        'assignfeedback_aif_file',
                        get_string('file', 'assignfeedback_aif'), // label for file selection
                        ['maxfiles' => 1, 'maxfilesize' => '10MB'] // adjust as needed
                        );


        $mform->addHelpButton('assignfeedback_aif_prompt', 'prompt', 'assignfeedback_aif');
        // Disable Prompt if AI assisted feedback if comment feedback plugin is disabled.
        $mform->hideIf('assignfeedback_aif_prompt', 'assignfeedback_aif_enabled', 'notchecked');

        $mform->addHelpButton('assignfeedback_aif_file', 'file', 'assignfeedback_aif');
        $mform->hideIf('assignfeedback_aif_file', 'assignfeedback_aif_enabled', 'notchecked');

        global $DB;
        $id = optional_param('update', 0, PARAM_INT);
        $record = $DB->get_record('assignfeedback_aif', ['assignment' => $id]);
        if ($record) {
            $mform->setDefault('assignfeedback_aif_prompt', $record->prompt);
        }

    }

    public function get_prompt()    {
        global $DB;

        $id = optional_param('id', 0, PARAM_INT);
        $prompt = $DB->get_record('assignfeedback_aif', ['assignment' => $id]);
        return $prompt;
    }

    public function data_preprocessing(&$defaultvalues) {
        global $DB;
        return;
        $id = optional_param('id', 0, PARAM_INT);
        $prompt = $DB->get_record('assignfeedback_aif', ['assignment' => $id]);
        $defaultvalues['assignfeedback_aif_prompt'] = $prompt->prompt;
        return $defaultvalues;
    }
    /**
     * Has the comment feedback been modified   ?
     *
     * @param stdClass $grade The grade object.
     * @param stdClass $data Data from the form submission.
     * @return boolean True if the comment feedback has been modified, else false.
     */
    public function is_feedback_modified(stdClass $grade, stdClass $data) {
        global $DB;
        return 'is_feedback_modified function';
        $feedback = $DB->get_record('assignfeedback_aif', ['grade' => $grade->id]);
        $oldvalue = $feedback ? $feedback->value : '';
        $newvalue = $data->assignfeedbackaif ?? '';
        return $oldvalue !== $newvalue;
    }

    /**
     * Return a list of the text fields that can be imported/exported by this plugin.
     *
     * @return array An array of field names and descriptions. (name=>description, ...)
     */
    public function get_editor_fields() {
        return ['aif' => get_string('pluginname', 'assignfeedback_aif')];
    }

    /**
     * Get the saved text content from the editor.
     *
     * @param string $name
     * @param int $gradeid
     * @return string
     */
    public function get_editor_text($name, $gradeid) {
        global $DB;
        xmldb_debug();
        return 'get_editor_text function';
        if ($name === 'aif') {
            $feedback = $DB->get_record('assignfeedback_aif', ['grade' => $gradeid]);
            return $feedback ? $feedback->value : '';
        }
        return '';
    }

    /**
     * Get the saved text content from the editor.
     *
     * @param string $name
     * @param string $value
     * @param int $gradeid
     * @return string
     */
    public function set_editor_text($name, $value, $gradeid) {
        global $DB;
        xdebug_break();
        if ($name === 'aif') {
            $feedback = $DB->get_record('assignfeedback_aif', ['grade' => $gradeid]);
            if ($feedback) {
                $feedback->value = $value;
                $DB->update_record('assignfeedback_aif', $feedback);
            } else {
                $feedback = new stdClass();
                $feedback->value = $value;
                $feedback->grade = $gradeid;
                $feedback->assignment = $this->assignment->get_instance()->id;
                $DB->insert_record('assignfeedback_aif', $feedback);
            }
            return true;
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
    // public function get_form_elements_for_user($grade, MoodleQuickForm $mform, stdClass $data, $userid) {
    //     global $DB;
    //     $mform->addElement('text', 'assignfeedbackaif', $this->get_name());
    //     $mform->setType('assignfeedbackaif', PARAM_TEXT);
    //     $mform->setDefault('assignfeedbackaif', 'zzzzzz');
    //     xdebug_break();
    //     //global $DB;
    //     $aifeedback =  $DB->get_record('assignfeedback_aif', array('grade'=>$grade->id));


    //     return true;
    // }
    /**
     * Save the settings for feedback comments plugin
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {
        global $DB;
        xdebug_break();
        $prompt = $data->assignfeedback_aif_prompt;
        $instance = $data->instance;
        $record = $DB->get_record('assignfeedback_aif', ['assignment' => $instance]);
        if($record) {
            $data = (object) [
                'id' => $record->id,
                'prompt' => $prompt,
                'assignment' => $instance
            ];

            $DB->update_record('assignfeedback_aif', $data);
        } else {
              $data = (object) [
                'prompt' => $prompt,
                'assignment' => $instance
            ];
            $DB->insert_record('assignfeedback_aif', $data);
        }
        return true;
    }
    /**
     * Saving the comment content into database.
     *
     * @param stdClass $grade
     * @param stdClass $data
     * @return bool
     */
    public function save(stdClass $grade, stdClass $data) {
        global $DB;
        xdebug_break();
        $feedback = $DB->get_record('assignfeedback_aif', ['grade' => $grade->id]);
        if ($feedback) {
            $feedback->value = $data->assignfeedbackaif;
            $DB->update_record('assignfeedback_aif', $feedback);
        } else {
            $feedback = new stdClass();
            $feedback->commenttext = $data->assignfeedbackaif;
            $feedback->gradeid = $grade->id;
            $feedback->assignment = $this->assignment->get_instance()->id;
            $DB->insert_record('assignfeedback_aif', $feedback);
        }
        return true;
    }

    /**
     * Display the comment in the feedback table.
     *
     * @param stdClass $grade
     * @param bool $showviewlink Set to true to show a link to view the full feedback
     * @return string
     */
    public function view_summary(stdClass $grade, & $showviewlink) {

        /**
         *
         SELECT *
         FROM mdl_assign a
         JOIN mdl_course_modules cm
         ON cm.instance = a.id and cm.course = a.course
         JOIN mdl_assignfeedback_aif aif
         ON aif.assignment = cm.id
         JOIN mdl_assignfeedback_aif_feedback aiff
         ON aiff.aif = aif.id
         JOIN mdl_assign_submission sub
         ON sub.assignment = cm.instance AND aiff.submission = sub.id
         ORDER BY aiff.id\G

         SELECT * FROM mdl_assign_submission sub
         JOIN mdl_assignfeedback_aif aif ON aif.assignment = sub.assignment
         JOIN mdl_assignfeedback_aif_feedback aiff on aiff.aif = aif.id;


         *
         *
         */
        // global $DB;
        // xdebug_break();
        // $sql = "SELECT aiff.feedback
        // FROM {assign} a
        // JOIN {course_modules} cm
        // ON cm.instance = a.id and cm.course = a.course
        // JOIN {assignfeedback_aif} aif
        // ON aif.assignment = cm.id
        // JOIN {assignfeedback_aif_feedback} aiff
        // ON aiff.aif = aif.id
        // JOIN {assign_submission} sub
        // ON sub.assignment = a.id AND aiff.submission = sub.id
        // WHERE a.id = :assignment AND sub.userid = :userid AND sub.latest = 1 AND sub.status = 'submitted'
        // ORDER BY aiff.id";
        // $params = ['assignment' => $grade->assignment, 'userid' => $grade->userid];
        xdebug_break();
        global $DB;
        $sql = "SELECT aiff.feedback
            FROM {course_modules} cm
            JOIN {assignfeedback_aif} aif
            ON aif.assignment = cm.instance
            JOIN {assignfeedback_aif_feedback} aiff
            ON aiff.aif = aif.id
            JOIN {assign_submission} sub
            ON sub.assignment = cm.instance
            JOIN {assignsubmission_onlinetext} olt
            ON olt.assignment = cm.instance
            WHERE sub.status='submitted'
            AND sub.userid=:userid";
            $params = ['assignment' => $grade->assignment, 'userid' => $grade->userid];

        $record = $DB->get_record_sql($sql, $params);
        //return 'Here is some ai feedback';
        return $record ? format_text($record->feedback) : '';
    }

    /**
     * Display the comment in the feedback table.
     *
     * @param stdClass $grade
     * @return string
     */
    public function view(stdClass $grade) {
        global $DB;
        return 'view function';
        $feedback = $DB->get_record('assignfeedback_aif', ['grade' => $grade->id]);
        return $feedback ? s($feedback->value) : '';
    }

    /**
     * If this plugin adds to the gradebook comments field, it must format the text
     * of the comment
     *
     * Only one feedback plugin can push comments to the gradebook and that is chosen by the assignment
     * settings page.
     *
     * @param stdClass $grade The grade
     * @return string
     */
    public function text_for_gradebook(stdClass $grade) {
        global $DB;
        xdebug_break();
        return 'text_for_gradebook function';
        $record = $DB->get_record('assignfeedback_aif_feedback', ['grade' => $grade->id]);
        return $feedback ? $fecord->feedback : '';
    }

    /**
     * The assignment has been deleted - cleanup
     *
     * @return bool
     */
    public function delete_instance() {
        global $DB;
        $DB->delete_records('assignfeedback_aif',
                            ['assignment' => $this->assignment->get_instance()->id]);
        return true;
    }

    /**
     * Returns true if there are no feedback comments for the given grade.
     *
     * @param stdClass $grade
     * @return bool
     */
    public function is_empty(stdClass $grade) {
        return $this->view($grade) === '';
    }

    /**
     * Return a description of external params suitable for uploading an feedback comment from a webservice.
     *
     * Used in WebServices mod_assign_save_grade and mod_assign_save_grades
     *
     * @return array
     */
    public function get_external_parameters() {
        global $CFG;
        require_once($CFG->dirroot . '/lib/externallib.php');

        return ['assignfeedbackaif' => new external_value(PARAM_RAW, 'The text for this feedback.')];
    }

    /**
     * Return the plugin configs for external functions.
     *
     * @return array the list of settings
     * @since Moodle 3.2
     */
    public function get_config_for_external() {
        return (array) $this->get_config();
    }
}
