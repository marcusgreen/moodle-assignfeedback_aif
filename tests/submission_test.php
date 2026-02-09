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
 * Tests for the AI feedback plugin class (locallib).
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assign_feedback_aif
 */

namespace assignfeedback_aif;

require_once(__DIR__ . '/../../../tests/generator.php');

final class submission_test extends \advanced_testcase {
    /**
     * Test that the plugin can be enabled on an assignment instance.
     */
    public function test_plugin_can_be_enabled(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $plugin = $this->get_aif_plugin($env->assignobj);

        $this->assertNotNull($plugin);
        $this->assertNotEmpty($plugin->is_enabled());
        $this->assertEquals('aif', $plugin->get_type());
    }

    /**
     * Test save_settings inserts a new config record and updates it on second call.
     */
    public function test_save_settings_creates_and_updates(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $plugin = $this->get_aif_plugin($env->assignobj);

        // First call: insert new config.
        $data = new \stdClass();
        $data->assignfeedback_aif_prompt = 'Analyse the grammar';
        $data->assignfeedback_aif_autogenerate = 1;
        $data->coursemodule = $env->cm->id;
        $plugin->save_settings($data);

        $record = $DB->get_record('assignfeedback_aif', ['assignment' => $env->cm->id]);
        $this->assertNotFalse($record);
        $this->assertEquals('Analyse the grammar', $record->prompt);
        $this->assertEquals(1, (int) $record->autogenerate);

        // Second call: update existing config.
        $data->assignfeedback_aif_prompt = 'Updated prompt instructions';
        $data->assignfeedback_aif_autogenerate = 0;
        $plugin->save_settings($data);

        $updated = $DB->get_record('assignfeedback_aif', ['assignment' => $env->cm->id]);
        $this->assertEquals($record->id, $updated->id); // Same record, not a new one.
        $this->assertEquals('Updated prompt instructions', $updated->prompt);
        $this->assertEquals(0, (int) $updated->autogenerate);
    }

    /**
     * Test save creates feedback and updates existing feedback for a grade.
     */
    public function test_save_creates_and_updates_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env);

        // Create a grade for the student.
        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);

        // Insert a feedback record so save() goes through the update path.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $clock = \core\di::get(\core\clock::class);
        $feedbackid = $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Initial AI feedback',
            'feedbackformat' => FORMAT_HTML,
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);

        // Now call save() to update the feedback via the plugin.
        $plugin = $this->get_aif_plugin($env->assignobj);
        $data = new \stdClass();
        $data->assignfeedbackaif_editor = [
            'text' => 'Teacher-edited feedback',
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];

        $result = $plugin->save($grade, $data);

        $this->assertTrue($result);
        $updated = $DB->get_record('assignfeedback_aif_feedback', ['id' => $feedbackid]);
        $this->assertEquals('Teacher-edited feedback', $updated->feedback);
    }

    /**
     * Test is_feedback_modified detects changed and unchanged feedback text.
     */
    public function test_is_feedback_modified(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);
        $this->create_aif_config($env);

        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);
        $plugin = $this->get_aif_plugin($env->assignobj);

        // No feedback exists yet — empty string vs empty string should be unmodified.
        $data = new \stdClass();
        $data->assignfeedbackaif_editor = ['text' => '', 'format' => FORMAT_HTML];
        $this->assertFalse($plugin->is_feedback_modified($grade, $data));

        // Empty string vs new text should be modified.
        $data->assignfeedbackaif_editor = ['text' => 'New feedback', 'format' => FORMAT_HTML];
        $this->assertTrue($plugin->is_feedback_modified($grade, $data));
    }

    /**
     * Test get_feedbackaif returns the record or false.
     */
    public function test_get_feedbackaif(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env);

        $plugin = $this->get_aif_plugin($env->assignobj);

        // No feedback yet — should return false.
        $result = $plugin->get_feedbackaif($env->assign->id, $env->student->id);
        $this->assertFalse($result);

        // Insert feedback.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Generated feedback content',
            'feedbackformat' => FORMAT_HTML,
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);

        // Should now return the feedback record.
        $result = $plugin->get_feedbackaif($env->assign->id, $env->student->id);
        $this->assertNotFalse($result);
        $this->assertEquals('Generated feedback content', $result->feedback);
    }

    /**
     * Test view and view_summary return formatted feedback or empty string.
     */
    public function test_view_and_view_summary(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env);

        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);
        $plugin = $this->get_aif_plugin($env->assignobj);

        // No feedback — should return empty.
        $this->assertEquals('', $plugin->view($grade));

        $showviewlink = false;
        $this->assertEquals('', $plugin->view_summary($grade, $showviewlink));

        // Insert feedback.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => '<p>Well done on your essay.</p>',
            'feedbackformat' => FORMAT_HTML,
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);

        // Should now return formatted feedback.
        $viewresult = $plugin->view($grade);
        $this->assertStringContainsString('Well done on your essay.', $viewresult);

        $summaryresult = $plugin->view_summary($grade, $showviewlink);
        $this->assertStringContainsString('Well done on your essay.', $summaryresult);
    }

    /**
     * Test text_for_gradebook returns raw feedback text or empty string.
     */
    public function test_text_for_gradebook(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env);

        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);
        $plugin = $this->get_aif_plugin($env->assignobj);

        // No feedback — empty string.
        $this->assertEquals('', $plugin->text_for_gradebook($grade));

        // Insert feedback.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Gradebook feedback text',
            'feedbackformat' => FORMAT_HTML,
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);

        $this->assertEquals('Gradebook feedback text', $plugin->text_for_gradebook($grade));
    }

    /**
     * Test delete_instance cascades deletion to both config and feedback tables.
     */
    public function test_delete_instance_cascading(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);

        // The AIF config record was already created by save_settings during create_instance.
        $aifrecord = $DB->get_record('assignfeedback_aif', ['assignment' => $env->cm->id]);
        $this->assertNotFalse($aifrecord);
        $aifid = $aifrecord->id;

        // Insert feedback record.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Feedback to delete',
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);

        // Verify records exist.
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif', ['assignment' => $env->cm->id]));
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));

        // Delete instance.
        $plugin = $this->get_aif_plugin($env->assignobj);
        $result = $plugin->delete_instance();

        $this->assertTrue($result);
        $this->assertEquals(0, $DB->count_records('assignfeedback_aif', ['assignment' => $env->cm->id]));
        $this->assertEquals(0, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));
    }

    /**
     * Test is_empty reflects whether feedback exists for a grade.
     */
    public function test_is_empty(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env);

        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);
        $plugin = $this->get_aif_plugin($env->assignobj);

        // No feedback — should be empty.
        $this->assertTrue($plugin->is_empty($grade));

        // Insert feedback.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Non-empty feedback',
            'feedbackformat' => FORMAT_HTML,
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);

        // Should not be empty now.
        $this->assertFalse($plugin->is_empty($grade));
    }

    /**
     * Test get_editor_text and set_editor_text roundtrip for import/export.
     */
    public function test_editor_text_roundtrip(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env);

        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);
        $plugin = $this->get_aif_plugin($env->assignobj);

        // Insert feedback so the record exists.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Original text',
            'feedbackformat' => FORMAT_HTML,
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);

        // get_editor_text should return the current feedback.
        $this->assertEquals('Original text', $plugin->get_editor_text('aif', $grade->id));

        // set_editor_text should update it.
        $result = $plugin->set_editor_text('aif', 'Updated via import', $grade->id);
        $this->assertTrue($result);
        $this->assertEquals('Updated via import', $plugin->get_editor_text('aif', $grade->id));

        // Invalid field name should return empty/false.
        $this->assertEquals('', $plugin->get_editor_text('invalid', $grade->id));
        $this->assertFalse($plugin->set_editor_text('invalid', 'test', $grade->id));
    }

    /**
     * Create a standard test environment with course, users, and assignment.
     *
     * @param array $assignparams Additional assignment parameters.
     * @return \stdClass Environment object.
     */
    private function create_test_environment(array $assignparams = []): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $defaults = [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_aif_enabled' => 1,
            'assignfeedback_aif_prompt' => 'Default test prompt',
        ];
        $params = array_merge($defaults, $assignparams);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $assign = $generator->create_instance($params);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $context = \context_module::instance($cm->id);
        $assignobj = new \mod_assign_testable_assign($context, $cm, $course);

        return (object) [
            'course' => $course,
            'teacher' => $teacher,
            'student' => $student,
            'assign' => $assign,
            'cm' => $cm,
            'context' => $context,
            'assignobj' => $assignobj,
        ];
    }

    /**
     * Create and submit a student submission with online text.
     *
     * @param \stdClass $env The test environment.
     * @param string $text The submission text.
     */
    private function create_and_submit(\stdClass $env, string $text = 'Test submission'): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $this->setUser($env->student);

        $submissiondata = [
            'cmid' => $env->cm->id,
            'course' => $env->course->id,
            'userid' => $env->student->id,
            'onlinetext_editor' => [
                'text' => $text,
                'format' => FORMAT_HTML,
            ],
        ];
        $generator->create_submission($submissiondata);

        $sink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $env->student->id], []);
        $sink->close();
    }

    /**
     * Create an AIF configuration record for the assignment.
     *
     * @param \stdClass $env The test environment.
     * @param string $prompt The prompt text.
     * @param int $autogenerate Whether to auto-generate feedback.
     * @return int The AIF config record ID.
     */
    private function create_aif_config(\stdClass $env, string $prompt = 'Test prompt', int $autogenerate = 0): int {
        global $DB;
        $clock = \core\di::get(\core\clock::class);
        return $DB->insert_record('assignfeedback_aif', [
            'assignment' => $env->cm->id,
            'prompt' => $prompt,
            'autogenerate' => $autogenerate,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);
    }

    /**
     * Get the AIF feedback plugin from an assign instance.
     *
     * @param \mod_assign_testable_assign $assignobj The testable assign instance.
     * @return \assign_feedback_aif The AIF feedback plugin.
     */
    private function get_aif_plugin(\mod_assign_testable_assign $assignobj): \assign_feedback_aif {
        $plugins = $assignobj->get_feedback_plugins();
        foreach ($plugins as $plugin) {
            if ($plugin->get_type() === 'aif') {
                return $plugin;
            }
        }
        $this->fail('AIF feedback plugin not found on assignment.');
    }
}
