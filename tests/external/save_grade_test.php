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

namespace assignfeedback_aif\external;

use assignfeedback_aif\local\feedback_utils;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/externallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');
require_once(__DIR__ . '/../generator_trait.php');

/**
 * Tests for grading via the mod_assign web services with the AI feedback plugin enabled.
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assign_feedback_aif
 */
final class save_grade_test extends \advanced_testcase {
    use \assignfeedback_aif\aif_test_helper;

    /**
     * Saving a grade without AI feedback data must work and keep the existing AI feedback.
     */
    public function test_save_grade_without_aif_data_keeps_feedback(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);
        $this->create_aif_config($env);
        feedback_utils::save_feedback($env->assign->id, $env->student->id, 'Existing AI feedback', FORMAT_HTML);

        $this->setUser($env->teacher);
        $result = \mod_assign_external::save_grade($env->assign->id, $env->student->id, 50.0, -1, false, '', false, []);
        $this->assertNull($result);

        $grade = $env->assignobj->get_user_grade($env->student->id, false);
        $this->assertEquals(50.0, (float) $grade->grade);
        $record = feedback_utils::get_feedbackaif($env->assign->id, $env->student->id);
        $this->assertEquals('Existing AI feedback', $record->feedback);
    }
}
