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
 * Tests for the AI handler class.
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_aif\aif
 */

namespace assignfeedback_aif;

require_once(__DIR__ . '/../../../tests/generator.php');

final class aif_test extends \advanced_testcase {
    /**
     * Test that build_prompt_from_template replaces all placeholders and strips HTML.
     */
    public function test_build_prompt_from_template_replaces_all_placeholders(): void {
        $this->resetAfterTest();

        $template = 'Submission: {{submission}} Rubric: {{rubric}} Prompt: {{prompt}} '
            . 'Assignment: {{assignmentname}} Language: {{language}}';
        set_config('prompttemplate', $template, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->build_prompt_from_template(
            '<p>Student text</p>',
            '<b>Rubric criteria</b>',
            'Teacher instructions',
            'My Assignment'
        );

        // All placeholders should be replaced with values.
        $this->assertStringContainsString('Student text', $result);
        $this->assertStringContainsString('Rubric criteria', $result);
        $this->assertStringContainsString('Teacher instructions', $result);
        $this->assertStringContainsString('My Assignment', $result);

        // HTML tags should be preserved (submission may contain source code).
        $this->assertStringContainsString('<p>', $result);
        $this->assertStringContainsString('<b>', $result);

        // No placeholders should remain.
        $this->assertStringNotContainsString('{{submission}}', $result);
        $this->assertStringNotContainsString('{{rubric}}', $result);
        $this->assertStringNotContainsString('{{prompt}}', $result);
        $this->assertStringNotContainsString('{{assignmentname}}', $result);
        $this->assertStringNotContainsString('{{language}}', $result);
    }

    /**
     * Test that build_prompt_from_template uses default template when no config set.
     */
    public function test_build_prompt_from_template_uses_default_template(): void {
        $this->resetAfterTest();

        // Ensure no custom template is set.
        unset_config('prompttemplate', 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->build_prompt_from_template(
            'Test submission',
            '',
            'Check grammar',
            'English Essay'
        );

        // Should use the default template and include the submission text.
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('Test submission', $result);
        $this->assertStringContainsString('English Essay', $result);
    }

    /**
     * Test expert mode: when prompt contains {{submission}}, it replaces the admin template.
     */
    public function test_build_prompt_expert_mode(): void {
        $this->resetAfterTest();

        // Set a standard admin template that would normally be used.
        set_config('prompttemplate', 'ADMIN TEMPLATE: {{prompt}} {{submission}}', 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        // Expert mode prompt: teacher uses {{submission}} directly in their prompt.
        $expertprompt = 'You are a math teacher. Grade this: {{submission}} '
            . 'Rubric: {{rubric}} Assignment: {{assignmentname}} Language: {{language}}';

        $result = $aif->build_prompt_from_template(
            'Student answer: 42',
            'Accuracy criteria',
            $expertprompt,
            'Math Test'
        );

        // Expert mode: the teacher's prompt IS the template, admin template is NOT used.
        $this->assertStringNotContainsString('ADMIN TEMPLATE', $result);
        $this->assertStringContainsString('You are a math teacher', $result);
        $this->assertStringContainsString('Student answer: 42', $result);
        $this->assertStringContainsString('Accuracy criteria', $result);
        $this->assertStringContainsString('Math Test', $result);

        // No placeholders should remain.
        $this->assertStringNotContainsString('{{submission}}', $result);
        $this->assertStringNotContainsString('{{rubric}}', $result);
        $this->assertStringNotContainsString('{{assignmentname}}', $result);
        $this->assertStringNotContainsString('{{language}}', $result);
    }

    /**
     * Test standard mode: prompt without {{submission}} uses admin template.
     */
    public function test_build_prompt_standard_mode_uses_admin_template(): void {
        $this->resetAfterTest();

        set_config('prompttemplate', 'TEMPLATE: {{prompt}} --- {{submission}}', 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        // Standard prompt — no {{submission}} placeholder, so admin template is used.
        $result = $aif->build_prompt_from_template(
            'Student work',
            '',
            'Check grammar',
            'Essay'
        );

        $this->assertStringContainsString('TEMPLATE:', $result);
        $this->assertStringContainsString('Check grammar', $result);
        $this->assertStringContainsString('Student work', $result);
    }

    /**
     * Test that append_disclaimer appends configured disclaimer text.
     */
    public function test_append_disclaimer(): void {
        $this->resetAfterTest();

        set_config('disclaimer', 'AI generated content warning.', 'assignfeedback_aif');
        set_config('translatedisclaimer', 0, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->append_disclaimer('Great work on your essay.');

        $this->assertStringContainsString('Great work on your essay.', $result);
        $this->assertStringContainsString('AI generated content warning.', $result);
    }

    /**
     * Test that append_disclaimer uses default when no config set.
     */
    public function test_append_disclaimer_uses_default(): void {
        $this->resetAfterTest();

        unset_config('disclaimer', 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->append_disclaimer('Feedback text.');

        $this->assertStringContainsString('Feedback text.', $result);
        // The result should be longer than just the feedback (disclaimer appended).
        $this->assertGreaterThan(strlen('Feedback text.'), strlen($result));
    }

    /**
     * Test that append_disclaimer uses practice disclaimer when ispractice is true.
     */
    public function test_append_disclaimer_practice_mode(): void {
        $this->resetAfterTest();

        set_config('practicedisclaimer', 'Practice: not reviewed by teacher.', 'assignfeedback_aif');
        set_config('translatedisclaimer', 0, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->append_disclaimer('Your essay is good.', true);

        $this->assertStringContainsString('Your essay is good.', $result);
        $this->assertStringContainsString('Practice: not reviewed by teacher.', $result);
        $this->assertStringNotContainsString('reviewed by your teacher', $result);
    }

    /**
     * Test that append_disclaimer uses default practice disclaimer when no config set.
     */
    public function test_append_disclaimer_practice_mode_default(): void {
        $this->resetAfterTest();

        unset_config('practicedisclaimer', 'assignfeedback_aif');
        set_config('translatedisclaimer', 0, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->append_disclaimer('Feedback text.', true);

        $this->assertStringContainsString('NOT been reviewed', $result);
        $this->assertStringContainsString('self-study', $result);
    }

    /**
     * Test that append_disclaimer without practice flag uses regular disclaimer.
     */
    public function test_append_disclaimer_not_practice_mode(): void {
        $this->resetAfterTest();

        set_config('disclaimer', 'Teacher reviewed.', 'assignfeedback_aif');
        set_config('practicedisclaimer', 'Not reviewed.', 'assignfeedback_aif');
        set_config('translatedisclaimer', 0, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        // Without practice flag - should use regular disclaimer.
        $result = $aif->append_disclaimer('Feedback.', false);
        $this->assertStringContainsString('Teacher reviewed.', $result);
        $this->assertStringNotContainsString('Not reviewed.', $result);

        // With practice flag - should use practice disclaimer.
        $result = $aif->append_disclaimer('Feedback.', true);
        $this->assertStringContainsString('Not reviewed.', $result);
        $this->assertStringNotContainsString('Teacher reviewed.', $result);
    }

    /**
     * Test that perform_request uses DI-injectable provider.
     */
    public function test_perform_request_uses_di_provider(): void {
        $this->resetAfterTest();

        $mock = $this->createMock(\assignfeedback_aif\local\ai_request_provider::class);
        $mock->method('perform_request_core_ai')->willReturn('Mocked AI Response');
        $mock->method('perform_request_local_ai_manager')->willReturn('Mocked AI Response');
        \core\di::set(\assignfeedback_aif\local\ai_request_provider::class, $mock);

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->perform_request('Test prompt');

        $this->assertEquals('Mocked AI Response', $result);
    }

    /**
     * Test get_prompt with an online text submission returns a non-empty prompt.
     */
    public function test_get_prompt_with_online_text(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $aifid = $this->create_aif_config($env, 'Analyse the grammar');

        // Manually create submission and onlinetext for full control over IDs.
        $clock = \core\di::get(\core\clock::class);
        $subid = $DB->insert_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'status' => 'submitted',
            'latest' => 1,
            'timecreated' => $clock->now()->getTimestamp(),
            'timemodified' => $clock->now()->getTimestamp(),
            'attemptnumber' => 0,
        ]);
        $DB->insert_record('assignsubmission_onlinetext', [
            'assignment' => $env->assign->id,
            'submission' => $subid,
            'onlinetext' => '<p>My essay about climate change.</p>',
            'onlineformat' => FORMAT_HTML,
        ]);

        $record = (object) [
            'aid' => $env->assign->id,
            'subid' => $subid,
            'userid' => $env->student->id,
            'aifid' => $aifid,
            'prompt' => 'Analyse the grammar',
            'contextid' => $env->context->id,
            'assignmentname' => $env->assign->name,
        ];

        $aif = new aif($env->context->id);
        ob_start();
        $result = $aif->get_prompt($record, 'simple');
        ob_end_clean();

        $this->assertNotEmpty($result['prompt']);
        $this->assertStringContainsString('climate change', $result['prompt']);
        $this->assertIsArray($result['options']);
    }

    /**
     * Test get_prompt returns prompt even when feedback already exists.
     *
     * The duplicate prevention is handled by the adhoc task (which deletes
     * existing feedback before regenerating), not by get_prompt().
     */
    public function test_get_prompt_returns_prompt_when_feedback_exists(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $aifid = $this->create_aif_config($env, 'Test prompt');

        $clock = \core\di::get(\core\clock::class);
        $subid = $DB->insert_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'status' => 'submitted',
            'latest' => 1,
            'timecreated' => $clock->now()->getTimestamp(),
            'timemodified' => $clock->now()->getTimestamp(),
            'attemptnumber' => 0,
        ]);
        $DB->insert_record('assignsubmission_onlinetext', [
            'assignment' => $env->assign->id,
            'submission' => $subid,
            'onlinetext' => 'My submission text',
            'onlineformat' => FORMAT_HTML,
        ]);

        // Insert existing feedback — get_prompt should still return a prompt.
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Existing feedback',
            'submission' => $subid,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);

        $record = (object) [
            'aid' => $env->assign->id,
            'subid' => $subid,
            'userid' => $env->student->id,
            'aifid' => $aifid,
            'prompt' => 'Test prompt',
            'contextid' => $env->context->id,
            'assignmentname' => $env->assign->name,
        ];

        $aif = new aif($env->context->id);
        ob_start();
        $result = $aif->get_prompt($record, 'simple');
        ob_end_clean();

        $this->assertNotEmpty($result['prompt']);
        $this->assertStringContainsString('My submission text', $result['prompt']);
    }

    /**
     * Test get_prompt returns empty when no submission content is available.
     */
    public function test_get_prompt_returns_empty_without_content(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $aifid = $this->create_aif_config($env, 'Analyse grammar');

        // Create a submission record manually without online text.
        $clock = \core\di::get(\core\clock::class);
        $subid = $DB->insert_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'status' => 'submitted',
            'latest' => 1,
            'timecreated' => $clock->now()->getTimestamp(),
            'timemodified' => $clock->now()->getTimestamp(),
            'attemptnumber' => 0,
        ]);

        $record = (object) [
            'aid' => $env->assign->id,
            'subid' => $subid,
            'userid' => $env->student->id,
            'aifid' => $aifid,
            'prompt' => 'Analyse grammar',
            'contextid' => $env->context->id,
            'assignmentname' => $env->assign->name,
        ];

        $aif = new aif($env->context->id);
        ob_start();
        $result = $aif->get_prompt($record, 'simple');
        ob_end_clean();

        $this->assertEmpty($result['prompt']);
    }

    /**
     * Create a standard test environment with course, users, and assignment.
     *
     * @param array $assignparams Additional assignment parameters.
     * @return \stdClass Environment object with course, teacher, student, assign, cm, context, assignobj.
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
}
