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
 */

namespace assignfeedback_aif;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../tests/generator.php');
require_once(__DIR__ . '/generator_trait.php');

/**
 * Tests for the AI handler class.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_aif\aif
 */
final class aif_test extends \advanced_testcase {
    use aif_test_helper;

    /** @var string Test disclaimer for custom config tests. */
    private const TEST_DISCLAIMER = 'Custom test disclaimer.';

    /** @var string Test practice disclaimer for custom config tests. */
    private const TEST_PRACTICE_DISCLAIMER = 'Custom test practice disclaimer.';

    /**
     * Test that build_prompt_from_template replaces all placeholders and strips HTML.
     *
     * @covers ::build_prompt_from_template
     */
    public function test_build_prompt_from_template_replaces_all_placeholders(): void {
        $this->resetAfterTest();

        $template = 'Submission: {{submission}} Rubric: {{rubric}} Prompt: {{prompt}} '
            . 'Assignment: {{assignmentname}} Language: {{language}}';
        set_config('prompttemplate', $template, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $submissiontext = 'Student text';
        $rubrictext = 'Rubric criteria';
        $prompttext = 'Teacher instructions';
        $assignmentname = 'My Assignment';

        $result = $aif->build_prompt_from_template(
            '<p>' . $submissiontext . '</p>',
            '<b>' . $rubrictext . '</b>',
            $prompttext,
            $assignmentname
        );

        // All placeholders should be replaced with values.
        $this->assertStringContainsString($submissiontext, $result);
        $this->assertStringContainsString($rubrictext, $result);
        $this->assertStringContainsString($prompttext, $result);
        $this->assertStringContainsString($assignmentname, $result);

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
     *
     * @covers ::build_prompt_from_template
     */
    public function test_build_prompt_from_template_uses_default_template(): void {
        $this->resetAfterTest();

        // Ensure no custom template is set.
        unset_config('prompttemplate', 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $submissiontext = 'Test submission';
        $assignmentname = 'English Essay';

        $result = $aif->build_prompt_from_template(
            $submissiontext,
            '',
            'Check grammar',
            $assignmentname
        );

        // Should use the default template and include the submission text.
        $this->assertNotEmpty($result);
        $this->assertStringContainsString($submissiontext, $result);
        $this->assertStringContainsString($assignmentname, $result);
    }

    /**
     * Test expert mode: when prompt contains {{submission}}, it replaces the admin template.
     *
     * @covers ::build_prompt_from_template
     */
    public function test_build_prompt_expert_mode(): void {
        $this->resetAfterTest();

        // Set a standard admin template that would normally be used.
        $admintemplatetext = 'ADMIN TEMPLATE';
        set_config('prompttemplate', $admintemplatetext . ': {{prompt}} {{submission}}', 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        // Expert mode prompt: teacher uses {{submission}} directly in their prompt.
        $expertprefix = 'You are a math teacher';
        $expertprompt = $expertprefix . '. Grade this: {{submission}} '
            . 'Rubric: {{rubric}} Assignment: {{assignmentname}} Language: {{language}}';

        $submissiontext = 'Student answer: 42';
        $rubrictext = 'Accuracy criteria';
        $assignmentname = 'Math Test';

        $result = $aif->build_prompt_from_template(
            $submissiontext,
            $rubrictext,
            $expertprompt,
            $assignmentname
        );

        // Expert mode: the teacher's prompt IS the template, admin template is NOT used.
        $this->assertStringNotContainsString($admintemplatetext, $result);
        $this->assertStringContainsString($expertprefix, $result);
        $this->assertStringContainsString($submissiontext, $result);
        $this->assertStringContainsString($rubrictext, $result);
        $this->assertStringContainsString($assignmentname, $result);

        // No placeholders should remain.
        $this->assertStringNotContainsString('{{submission}}', $result);
        $this->assertStringNotContainsString('{{rubric}}', $result);
        $this->assertStringNotContainsString('{{assignmentname}}', $result);
        $this->assertStringNotContainsString('{{language}}', $result);
    }

    /**
     * Test standard mode: prompt without {{submission}} uses admin template.
     *
     * @covers ::build_prompt_from_template
     */
    public function test_build_prompt_standard_mode_uses_admin_template(): void {
        $this->resetAfterTest();

        $templatemarker = 'TEMPLATE:';
        set_config('prompttemplate', $templatemarker . ' {{prompt}} --- {{submission}}', 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $submissiontext = 'Student work';
        $prompttext = 'Check grammar';

        // Standard prompt — no {{submission}} placeholder, so admin template is used.
        $result = $aif->build_prompt_from_template(
            $submissiontext,
            '',
            $prompttext,
            'Essay'
        );

        $this->assertStringContainsString($templatemarker, $result);
        $this->assertStringContainsString($prompttext, $result);
        $this->assertStringContainsString($submissiontext, $result);
    }

    /**
     * Test that append_disclaimer appends configured disclaimer text.
     *
     * @covers ::append_disclaimer
     */
    public function test_append_disclaimer(): void {
        $this->resetAfterTest();

        set_config('disclaimer', self::TEST_DISCLAIMER, 'assignfeedback_aif');
        set_config('translatedisclaimer', 0, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->append_disclaimer('Great work on your essay.');

        $this->assertStringContainsString(self::TEST_DISCLAIMER, $result);
    }

    /**
     * Test that append_disclaimer uses default when no config set.
     *
     * @covers ::append_disclaimer
     */
    public function test_append_disclaimer_uses_default(): void {
        $this->resetAfterTest();

        unset_config('disclaimer', 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->append_disclaimer('Feedback text.');

        $this->assertStringContainsString(
            get_string('defaultdisclaimer', 'assignfeedback_aif'),
            $result
        );
    }

    /**
     * Test that append_disclaimer uses practice disclaimer when ispractice is true.
     *
     * @covers ::append_disclaimer
     */
    public function test_append_disclaimer_practice_mode(): void {
        $this->resetAfterTest();

        set_config('practicedisclaimer', self::TEST_PRACTICE_DISCLAIMER, 'assignfeedback_aif');
        set_config('translatedisclaimer', 0, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->append_disclaimer('Your essay is good.', true);

        $this->assertStringContainsString(self::TEST_PRACTICE_DISCLAIMER, $result);
        $this->assertStringNotContainsString(
            get_string('defaultdisclaimer', 'assignfeedback_aif'),
            $result
        );
    }

    /**
     * Test that append_disclaimer uses default practice disclaimer when no config set.
     *
     * @covers ::append_disclaimer
     */
    public function test_append_disclaimer_practice_mode_default(): void {
        $this->resetAfterTest();

        unset_config('practicedisclaimer', 'assignfeedback_aif');
        set_config('translatedisclaimer', 0, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->append_disclaimer('Feedback text.', true);

        $this->assertStringContainsString(
            get_string('defaultpracticedisclaimer', 'assignfeedback_aif'),
            $result
        );
        $this->assertStringNotContainsString(
            get_string('defaultdisclaimer', 'assignfeedback_aif'),
            $result
        );
    }

    /**
     * Test that append_disclaimer without practice flag uses regular disclaimer.
     *
     * @covers ::append_disclaimer
     */
    public function test_append_disclaimer_not_practice_mode(): void {
        $this->resetAfterTest();

        set_config('disclaimer', self::TEST_DISCLAIMER, 'assignfeedback_aif');
        set_config('practicedisclaimer', self::TEST_PRACTICE_DISCLAIMER, 'assignfeedback_aif');
        set_config('translatedisclaimer', 0, 'assignfeedback_aif');

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        // Without practice flag - should use regular disclaimer.
        $result = $aif->append_disclaimer('Feedback.', false);
        $this->assertStringContainsString(self::TEST_DISCLAIMER, $result);
        $this->assertStringNotContainsString(self::TEST_PRACTICE_DISCLAIMER, $result);

        // With practice flag - should use practice disclaimer.
        $result = $aif->append_disclaimer('Feedback.', true);
        $this->assertStringContainsString(self::TEST_PRACTICE_DISCLAIMER, $result);
        $this->assertStringNotContainsString(self::TEST_DISCLAIMER, $result);
    }

    /**
     * Test that perform_request uses DI-injectable provider.
     *
     * @covers ::perform_request
     */
    public function test_perform_request_uses_di_provider(): void {
        $this->resetAfterTest();

        $expectedresponse = 'Mocked AI Response';
        $mock = $this->createMock(\assignfeedback_aif\local\ai_request_provider::class);
        $mock->method('perform_request_core_ai')->willReturn($expectedresponse);
        $mock->method('perform_request_local_ai_manager')->willReturn($expectedresponse);
        \core\di::set(\assignfeedback_aif\local\ai_request_provider::class, $mock);

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $result = $aif->perform_request('Test prompt');

        $this->assertEquals($expectedresponse, $result);
    }

    /**
     * Test get_prompt with an online text submission returns a non-empty prompt.
     *
     * @covers ::get_prompt
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
        $submissioncontent = 'My essay about climate change.';
        $DB->insert_record('assignsubmission_onlinetext', [
            'assignment' => $env->assign->id,
            'submission' => $subid,
            'onlinetext' => '<p>' . $submissioncontent . '</p>',
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
        $this->assertStringContainsString($submissioncontent, $result['prompt']);
        $this->assertIsArray($result['options']);
    }

    /**
     * Test get_prompt returns prompt even when feedback already exists.
     *
     * The duplicate prevention is handled by the adhoc task (which deletes
     * existing feedback before regenerating), not by get_prompt().
     *
     * @covers ::get_prompt
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
        $submissioncontent = 'My submission text';
        $DB->insert_record('assignsubmission_onlinetext', [
            'assignment' => $env->assign->id,
            'submission' => $subid,
            'onlinetext' => $submissioncontent,
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
        $this->assertStringContainsString($submissioncontent, $result['prompt']);
    }

    /**
     * Test get_prompt returns empty when no submission content is available.
     *
     * @covers ::get_prompt
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
     * Test get_prompt no longer returns image options (all files converted to text now).
     *
     * @covers ::get_prompt
     */
    public function test_get_prompt_returns_no_image_options(): void {
        global $DB;
        $this->resetAfterTest();

        // Mock the AI provider so ITT calls return extracted text.
        $mock = $this->createMock(\assignfeedback_aif\local\ai_request_provider::class);
        $mock->method('perform_request_core_ai')->willReturn('Mocked AI Response');
        $mock->method('perform_request_local_ai_manager')->willReturn('Text from image');
        $mock->method('is_available')->willReturn(true);
        \core\di::set(\assignfeedback_aif\local\ai_request_provider::class, $mock);

        $env = $this->create_test_environment();
        $aifid = $this->create_aif_config($env, 'Analyse');

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
            'onlinetext' => 'My text submission',
            'onlineformat' => FORMAT_HTML,
        ]);

        $record = (object) [
            'aid' => $env->assign->id,
            'subid' => $subid,
            'userid' => $env->student->id,
            'aifid' => $aifid,
            'prompt' => 'Analyse',
            'contextid' => $env->context->id,
            'assignmentname' => $env->assign->name,
        ];

        $aif = new aif($env->context->id);
        ob_start();
        $result = $aif->get_prompt($record, 'simple');
        ob_end_clean();

        $this->assertNotEmpty($result['prompt']);
        // The options should never contain 'image' anymore — all files become text.
        $this->assertArrayNotHasKey('image', $result['options']);
    }

    /**
     * Test that the resource cache stores and retrieves extracted content.
     *
     * @covers ::store_to_cache
     * @covers ::get_from_cache
     */
    public function test_cache_stores_and_retrieves_content(): void {
        global $DB;
        $this->resetAfterTest();

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        // Use reflection to access protected methods.
        $storemethod = new \ReflectionMethod($aif, 'store_to_cache');
        $getmethod = new \ReflectionMethod($aif, 'get_from_cache');

        $testhash = 'abc123hash';
        $testcontent = 'Extracted text from file.';

        // Initially no cache entry.
        $result = $getmethod->invoke($aif, $testhash);
        $this->assertNull($result);

        // Store content.
        $storemethod->invoke($aif, $testhash, $testcontent);

        // Retrieve.
        $result = $getmethod->invoke($aif, $testhash);
        $this->assertEquals($testcontent, $result);

        // Verify DB record.
        $record = $DB->get_record('assignfeedback_aif_rescache', ['contenthash' => $testhash]);
        $this->assertNotFalse($record);
        $this->assertEquals($testcontent, $record->extractedcontent);
    }

    /**
     * Test that the cache updates existing entries on store.
     *
     * @covers ::store_to_cache
     * @covers ::get_from_cache
     */
    public function test_cache_updates_existing_entry(): void {
        global $DB;
        $this->resetAfterTest();

        $context = \core\context\system::instance();
        $aif = new aif($context->id);

        $storemethod = new \ReflectionMethod($aif, 'store_to_cache');
        $getmethod = new \ReflectionMethod($aif, 'get_from_cache');

        $testhash = 'hash1';
        $updatedcontent = 'Version 2';

        $storemethod->invoke($aif, $testhash, 'Version 1');
        $storemethod->invoke($aif, $testhash, $updatedcontent);

        $result = $getmethod->invoke($aif, $testhash);
        $this->assertEquals($updatedcontent, $result);

        // Only one record should exist.
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_rescache', ['contenthash' => $testhash]));
    }

    /**
     * Test the cleanup cache task deletes expired entries.
     *
     * @covers \assignfeedback_aif\task\cleanup_cache::execute
     */
    public function test_cleanup_cache_task(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('cachecleanupdelay', 7, 'assignfeedback_aif');

        $clock = $this->mock_clock_with_frozen(time());

        $oldhash = 'oldhash';
        $recenthash = 'recenthash';

        // Insert an old cache entry (accessed 10 days ago).
        $oldtime = $clock->now()->getTimestamp() - (10 * DAYSECS);
        $DB->insert_record('assignfeedback_aif_rescache', [
            'contenthash' => $oldhash,
            'extractedcontent' => 'Old content',
            'timecreated' => $oldtime,
            'timemodified' => $oldtime,
            'timelastaccessed' => $oldtime,
        ]);

        // Insert a recent cache entry (accessed 2 days ago).
        $recenttime = $clock->now()->getTimestamp() - (2 * DAYSECS);
        $DB->insert_record('assignfeedback_aif_rescache', [
            'contenthash' => $recenthash,
            'extractedcontent' => 'Recent content',
            'timecreated' => $recenttime,
            'timemodified' => $recenttime,
            'timelastaccessed' => $recenttime,
        ]);

        $this->assertEquals(2, $DB->count_records('assignfeedback_aif_rescache'));

        $task = new \assignfeedback_aif\task\cleanup_cache();
        ob_start();
        $task->execute();
        ob_end_clean();

        // Old entry should be deleted, recent should remain.
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_rescache'));
        $this->assertTrue($DB->record_exists('assignfeedback_aif_rescache', ['contenthash' => $recenthash]));
        $this->assertFalse($DB->record_exists('assignfeedback_aif_rescache', ['contenthash' => $oldhash]));
    }

    /**
     * Test the cleanup cache task does nothing when disabled.
     *
     * @covers \assignfeedback_aif\task\cleanup_cache::execute
     */
    public function test_cleanup_cache_task_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('cachecleanupdelay', 0, 'assignfeedback_aif');

        $this->mock_clock_with_frozen(time());

        $oldtime = time() - (100 * DAYSECS);
        $DB->insert_record('assignfeedback_aif_rescache', [
            'contenthash' => 'hash1',
            'extractedcontent' => 'Content',
            'timecreated' => $oldtime,
            'timemodified' => $oldtime,
            'timelastaccessed' => $oldtime,
        ]);

        $task = new \assignfeedback_aif\task\cleanup_cache();
        ob_start();
        $task->execute();
        ob_end_clean();

        // Nothing should be deleted when disabled.
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_rescache'));
    }
}
