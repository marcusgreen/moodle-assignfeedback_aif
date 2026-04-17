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
 * Tests for the assignfeedback_aif privacy provider.
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aif\privacy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../tests/generator.php');
require_once(__DIR__ . '/../generator_trait.php');

use assignfeedback_aif\aif_test_helper;
use core_privacy\local\metadata\collection;
use core_privacy\tests\request\content_writer;
use mod_assign\privacy\assign_plugin_request_data;

/**
 * Tests for the assignfeedback_aif privacy provider.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_aif\privacy\provider
 */
final class provider_test extends \advanced_testcase {
    use aif_test_helper;

    /**
     * Test that metadata is described.
     *
     * @covers \assignfeedback_aif\privacy\provider::get_metadata
     */
    public function test_get_metadata(): void {
        $collection = new collection('assignfeedback_aif');
        $collection = provider::get_metadata($collection);
        $items = $collection->get_collection();
        $this->assertNotEmpty($items);

        // The feedback table is declared.
        $tablenames = array_map(static fn($item) => $item->get_name(), $items);
        $this->assertContains('assignfeedback_aif_feedback', $tablenames);
    }

    /**
     * Test exporting feedback user data writes the feedback text for the target user.
     *
     * @covers \assignfeedback_aif\privacy\provider::export_feedback_user_data
     */
    public function test_export_feedback_user_data(): void {
        global $DB;
        $this->resetAfterTest();

        [$env, $grade, $feedbacktext] = $this->seed_feedback('Exported feedback for student');

        $exportdata = new assign_plugin_request_data(
            $env->context,
            $env->assignobj,
            $grade,
            [],
            $env->student
        );
        provider::export_feedback_user_data($exportdata);

        /** @var content_writer $writer */
        $writer = \core_privacy\local\request\writer::with_context($env->context);
        $this->assertTrue($writer->has_any_data());

        $subcontext = [get_string('privacy:aipath', 'assignfeedback_aif')];
        $exported = $writer->get_data($subcontext);
        $this->assertNotEmpty($exported);
        $this->assertStringContainsString($feedbacktext, $exported->feedback);
    }

    /**
     * Test that deleting feedback for a context removes all plugin data.
     *
     * @covers \assignfeedback_aif\privacy\provider::delete_feedback_for_context
     */
    public function test_delete_feedback_for_context(): void {
        global $DB;
        $this->resetAfterTest();

        [$env] = $this->seed_feedback('Context-level delete');

        $requestdata = new assign_plugin_request_data($env->context, $env->assignobj);
        provider::delete_feedback_for_context($requestdata);

        $this->assertFalse($DB->record_exists('assignfeedback_aif', ['assignment' => $env->assign->id]));
        $this->assertEquals(0, $DB->count_records('assignfeedback_aif_feedback'));
    }

    /**
     * Test that deleting feedback for one grade only removes that user's feedback.
     *
     * @covers \assignfeedback_aif\privacy\provider::delete_feedback_for_grade
     */
    public function test_delete_feedback_for_grade(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $aifid = $this->create_aif_config($env, 'Per-grade delete prompt');

        // First student with feedback.
        $this->create_and_submit($env, 'Student one submission');
        $this->setUser($env->teacher);
        $grade1 = $env->assignobj->get_user_grade($env->student->id, true);
        $this->insert_feedback_record($env->assign->id, $env->student->id, $aifid, 'Feedback for student one');

        // Second student with feedback.
        $student2 = $this->getDataGenerator()->create_and_enrol($env->course, 'student');
        $this->setUser($student2);
        $submissiondata = [
            'cmid' => $env->cm->id,
            'userid' => $student2->id,
            'onlinetext' => 'Student two submission',
        ];
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $generator->create_submission($submissiondata);
        $sink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $student2->id], []);
        $sink->close();
        $this->setUser($env->teacher);
        $env->assignobj->get_user_grade($student2->id, true);
        $this->insert_feedback_record($env->assign->id, $student2->id, $aifid, 'Feedback for student two');

        // Delete only student one's feedback.
        $requestdata = new assign_plugin_request_data(
            $env->context,
            $env->assignobj,
            $grade1,
            [],
            $env->student
        );
        provider::delete_feedback_for_grade($requestdata);

        $remaining = $DB->get_records('assignfeedback_aif_feedback', ['aif' => $aifid]);
        $this->assertCount(1, $remaining);
        $this->assertStringContainsString('student two', reset($remaining)->feedback);
        // Config row stays.
        $this->assertTrue($DB->record_exists('assignfeedback_aif', ['id' => $aifid]));
    }

    /**
     * Test deleting feedback for a list of grades.
     *
     * @covers \assignfeedback_aif\privacy\provider::delete_feedback_for_grades
     */
    public function test_delete_feedback_for_grades(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $aifid = $this->create_aif_config($env, 'Bulk delete prompt');

        // Three students with feedback.
        $students = [];
        $students[] = $env->student;
        $this->create_and_submit($env, 'Student one submission');
        $this->setUser($env->teacher);
        $env->assignobj->get_user_grade($env->student->id, true);
        $this->insert_feedback_record($env->assign->id, $env->student->id, $aifid, 'Feedback user 1');

        foreach ([2, 3] as $idx) {
            $s = $this->getDataGenerator()->create_and_enrol($env->course, 'student');
            $students[] = $s;
            $this->setUser($s);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
            $generator->create_submission([
                'cmid' => $env->cm->id,
                'userid' => $s->id,
                'onlinetext' => "Student $idx submission",
            ]);
            $sink = $this->redirectMessages();
            $env->assignobj->submit_for_grading((object) ['userid' => $s->id], []);
            $sink->close();
            $this->setUser($env->teacher);
            $env->assignobj->get_user_grade($s->id, true);
            $this->insert_feedback_record($env->assign->id, $s->id, $aifid, "Feedback user $idx");
        }

        $this->assertEquals(3, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));

        // Delete feedback for students 1 and 3 (keep student 2).
        $deletedata = new assign_plugin_request_data($env->context, $env->assignobj);
        $deletedata->set_userids([$students[0]->id, $students[2]->id]);
        $deletedata->populate_submissions_and_grades();
        provider::delete_feedback_for_grades($deletedata);

        $remaining = $DB->get_records('assignfeedback_aif_feedback', ['aif' => $aifid]);
        $this->assertCount(1, $remaining);
        $this->assertStringContainsString('user 2', reset($remaining)->feedback);
    }

    /**
     * Create an AIF feedback record linked to the user's latest submission.
     *
     * @param int $assignmentid Assignment id.
     * @param int $userid User id.
     * @param int $aifid AIF config id.
     * @param string $text Feedback text.
     */
    private function insert_feedback_record(int $assignmentid, int $userid, int $aifid, string $text): void {
        global $DB;
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assignmentid,
            'userid' => $userid,
            'latest' => 1,
        ]);
        $this->assertNotFalse($submission, 'Submission must exist before seeding feedback.');
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'submission' => $submission->id,
            'feedback' => $text,
            'feedbackformat' => FORMAT_HTML,
            'timecreated' => $clock->now()->getTimestamp(),
            'timemodified' => $clock->now()->getTimestamp(),
        ]);
    }

    /**
     * Seed a test environment with one student submission and AI feedback record.
     *
     * @param string $feedbacktext Feedback text to store.
     * @return array{0: \stdClass, 1: \stdClass, 2: string}
     */
    private function seed_feedback(string $feedbacktext): array {
        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Seeded submission');
        $aifid = $this->create_aif_config($env, 'Privacy test prompt', 0);

        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);
        $this->insert_feedback_record($env->assign->id, $env->student->id, $aifid, $feedbacktext);

        return [$env, $grade, $feedbacktext];
    }
}
