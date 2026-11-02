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
 * Tests that teachers can only act on students in their own groups.
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aif\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../tests/generator.php');
require_once(__DIR__ . '/../generator_trait.php');

/**
 * Tests that teachers can only act on students in their own groups.
 *
 * In separate groups mode, a non-editing teacher in group A must not be able to
 * generate, retry, analyse or check AI feedback for a student in group B.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(regenerate_feedback::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(retry_feedback::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_submission_analysis::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(check_feedback_status::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(\assign_feedback_aif::class, 'grading_batch_operation')]
final class group_access_test extends \advanced_testcase {
    use \assignfeedback_aif\aif_test_helper;

    /**
     * Create a separate groups assignment with a non-editing teacher in group A.
     *
     * @return \stdClass Environment with teacher, studenta (group A) and studentb (group B) added.
     */
    private function create_group_environment(): \stdClass {
        $env = $this->create_test_environment(['groupmode' => SEPARATEGROUPS]);
        $generator = $this->getDataGenerator();

        $env->teacher = $generator->create_and_enrol($env->course, 'teacher');
        $env->studenta = $env->student;
        $env->studentb = $generator->create_and_enrol($env->course, 'student');

        $groupa = $generator->create_group(['courseid' => $env->course->id]);
        $groupb = $generator->create_group(['courseid' => $env->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $env->teacher->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $env->studenta->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $env->studentb->id]);

        $this->create_aif_config($env);
        $this->setUser($env->teacher);
        return $env;
    }

    /**
     * Provides the external functions that act on a single student.
     *
     * @return array
     */
    public static function external_function_provider(): array {
        return [
            'regenerate_feedback' => [regenerate_feedback::class],
            'retry_feedback' => [retry_feedback::class],
            'get_submission_analysis' => [get_submission_analysis::class],
            'check_feedback_status' => [check_feedback_status::class],
        ];
    }

    /**
     * A teacher cannot call the function for a student outside their groups.
     *
     * @param string $classname The external function class.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('external_function_provider')]
    public function test_student_in_other_group_is_rejected(string $classname): void {
        $this->resetAfterTest();
        $env = $this->create_group_environment();

        $this->expectException(\required_capability_exception::class);
        $classname::execute($env->assign->id, $env->studentb->id);
    }

    /**
     * A teacher can still call the function for a student in their own group.
     *
     * @param string $classname The external function class.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('external_function_provider')]
    public function test_student_in_own_group_is_allowed(string $classname): void {
        $this->resetAfterTest();
        $env = $this->create_group_environment();

        $result = $classname::execute($env->assign->id, $env->studenta->id);
        $this->assertIsArray($result);
    }

    /**
     * Bulk generation skips selected students outside the teacher's groups.
     */
    public function test_batch_generation_skips_other_groups(): void {
        $this->resetAfterTest();
        $env = $this->create_group_environment();
        $plugin = $env->assignobj->get_feedback_plugin_by_type('aif');

        $_POST['sesskey'] = sesskey();
        try {
            $plugin->grading_batch_operation('generatefeedbackai', [$env->studenta->id, $env->studentb->id]);
        } catch (\moodle_exception $e) {
            // The operation ends with a redirect, which PHPUnit reports as an exception.
            $this->assertStringContainsString('redirect', $e->getMessage());
        }

        $this->assertNotNull(\assignfeedback_aif\local\task_manager::find_task_for_user($env->assign->id, $env->studenta->id));
        $this->assertNull(\assignfeedback_aif\local\task_manager::find_task_for_user($env->assign->id, $env->studentb->id));
    }
}
