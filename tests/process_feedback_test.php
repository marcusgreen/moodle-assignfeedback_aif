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

namespace assignfeedback_aif;

/**
 * Tests for AI Assisted Feedback
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2025 2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
global $CFG;

use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

final class process_feedback_test extends \advanced_testcase {

    public $course;
    public $student;
    public $teacher;
    public $assign;
    public $generator;

    public function setUp(): void{
        $this->resetAfterTest();

        parent::setUp();
        set_config('enabled', 1, 'aiprovider_openai');
        set_config('apikey', TEST_LLM_APIKEY, 'aiprovider_openai');
        set_config('summarise_text', 1, 'aiprovider_openai');
    }

    public function test_execute() :void {
        $this->resetAfterTest();
        global $DB;
        //Create test data
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->student = $this->getDataGenerator()->create_user();
        // Enroll users
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'teacher');
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

         $params = [
            'course' => $this->course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_aif_enabled' => 1,
            'assignfeedback_aif_prompt' => 'Analyse the English grammar',

        ];
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $assign = $generator->create_instance($params);

        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $assignobj = new \mod_assign_testable_assign(\context_module::instance($cm->id), $cm, $this->course);
        $this->setUser($this->student);

        $submissiondata = [
        'cmid' => $cm->id,
        'course' => $this->course->id,
        'userid' => $this->student->id,
        'onlinetext' => 'Yesterday I go prk',
        'onlinetext_editor' => [
                'text' => 'This is my assignment submission',
                'format' => FORMAT_HTML,
              ],
         ];

        $generator->create_submission($submissiondata);
        $assignobj->submit_for_grading($this->student, $submissiondata);

        $task = new \assignfeedback_aif\task\process_feedback();
        $recordcount = $DB->count_records('assignfeedback_aif_feedback');
        $this->assertEquals(0, $recordcount);
        $task->execute();
        $recordcount = $DB->count_records('assignfeedback_aif_feedback');
        $this->assertEquals(1, $recordcount);

    }

}
