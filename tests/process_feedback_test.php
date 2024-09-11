<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace assignfeedback_aif\tests;

use advanced_testcase;
use assignfeedback_aif\task\process_feedback;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/feedback/aif/classes/task/process_feedback.php');

/**
 * Unit tests for the process_feedback scheduled task.
 *
 * @package     assignfeedback_aif
 * @category    test
 * @copyright   2024 Marcus Green
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_feedback_test extends advanced_testcase {

    /**
     * Test the get_name method of the process_feedback task.
     */
    public function test_get_name() {
        $this->resetAfterTest(true);
        $task = new process_feedback();
        $this->assertEquals(get_string('taskprocessfeedback', 'assignfeedback_aif'), $task->get_name());
    }

    /**
     * Test the execute method of the process_feedback task.
     */
    public function test_execute() {
        $this->resetAfterTest(true);
        $task = new process_feedback();

        // As the execute method is currently empty, we're just ensuring it runs without errors.
        $this->assertNull($task->execute());

        // Once you implement the execute method, you should add more specific tests here.
        // For example:
        // $this->assertTrue($task->execute());
        // $this->assertDatabaseHas('assign_feedback', ['status' => 'processed']);
    }
}