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

use assignfeedback_aif\local\rubric_grade_applier;
use assignfeedback_aif\task\process_feedback_adhoc;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../tests/generator.php');
require_once(__DIR__ . '/generator_trait.php');

/**
 * Tests for applying the AI rubric assessment to the advanced grading form.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 Jack
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \assignfeedback_aif\local\rubric_grade_applier
 */
final class rubric_grade_applier_test extends \advanced_testcase {
    use aif_test_helper;

    /** @var string Markdown code fence delimiter. */
    private const FENCE = '```'; // phpcs:ignore Squiz.Strings.EchoedStrings, moodle.Strings.ForbiddenStrings

    /** @var string A model response with feedback text followed by the structured block. */
    private const RESPONSE = "## Feedback\n\nGood work overall.\n\n### Rubric assessment\n\n" . self::FENCE . "json\n"
        . '{"rubric": [{"criterion": "Spelling is important", "level": "No mistakes", "score": 2, "remark": "Flawless."},'
        . ' {"criterion": "Pictures", "level": "One picture", "score": 1, "remark": "Add another one."}]}'
        . "\n" . self::FENCE . "\n";

    /**
     * Build a criteria array in the shape returned by load_criteria().
     *
     * @return array
     */
    private function sample_criteria(): array {
        return [
            11 => ['description' => 'Spelling is important', 'levels' => [
                101 => ['definition' => 'Nothing but mistakes', 'score' => 0.0],
                102 => ['definition' => 'Several mistakes', 'score' => 1.0],
                103 => ['definition' => 'No mistakes', 'score' => 2.0],
            ]],
            12 => ['description' => 'Pictures', 'levels' => [
                201 => ['definition' => 'No pictures', 'score' => 0.0],
                202 => ['definition' => 'One picture', 'score' => 1.0],
                203 => ['definition' => 'More than one picture', 'score' => 2.0],
            ]],
        ];
    }

    /**
     * The JSON block is parsed and removed from the feedback shown to students.
     */
    public function test_extract_strips_json_block(): void {
        $result = rubric_grade_applier::extract(self::RESPONSE);

        $this->assertCount(2, $result['assessment']);
        $this->assertSame('Pictures', $result['assessment'][1]['criterion']);
        $this->assertStringContainsString('Good work overall.', $result['feedback']);
        $this->assertStringNotContainsString(self::FENCE, $result['feedback']);
        $this->assertStringNotContainsString('"rubric"', $result['feedback']);
        $this->assertStringNotContainsString('Rubric assessment', $result['feedback']);
    }

    /**
     * Feedback without a block is returned untouched with a null assessment.
     */
    public function test_extract_without_block(): void {
        $result = rubric_grade_applier::extract("Plain feedback\n");

        $this->assertNull($result['assessment']);
        $this->assertSame("Plain feedback\n", $result['feedback']);
    }

    /**
     * A bare JSON object without code fences is still recognised and removed.
     */
    public function test_extract_unfenced_json(): void {
        $response = "Solid effort.\n\n"
            . '{"rubric": [{"criterion": "Pictures", "level": "One picture", "score": 1, "remark": ""}]}' . "\n";
        $result = rubric_grade_applier::extract($response);

        $this->assertCount(1, $result['assessment']);
        $this->assertSame('Pictures', $result['assessment'][0]['criterion']);
        $this->assertSame("Solid effort.\n", $result['feedback']);
    }

    /**
     * A bare JSON object followed by unrelated text containing its own "[...]}" must
     * not be overmatched by the greedy fallback pattern.
     */
    public function test_extract_unfenced_json_with_trailing_brackets(): void {
        $response = "Solid effort.\n\n"
            . '{"rubric": [{"criterion": "Pictures", "level": "One picture", "score": 1, "remark": ""}]}'
            . "\n\nExample: {\"tags\": [\"x\", \"y\"]}\n";
        $result = rubric_grade_applier::extract($response);

        $this->assertCount(1, $result['assessment']);
        $this->assertSame('Pictures', $result['assessment'][0]['criterion']);
    }

    /**
     * Prompt instructions list every criterion with its levels and scores.
     */
    public function test_build_prompt_instructions(): void {
        $this->resetAfterTest();
        $prompt = rubric_grade_applier::build_prompt_instructions($this->sample_criteria());

        $this->assertStringContainsString(
            '"Spelling is important": Nothing but mistakes (0) | Several mistakes (1) | No mistakes (2)',
            $prompt
        );
        $this->assertStringContainsString('"Pictures"', $prompt);
        $this->assertStringContainsString(self::FENCE . 'json', $prompt);
        $this->assertSame('', rubric_grade_applier::build_prompt_instructions([]));
    }

    /**
     * Resolution matches names tolerantly and produces advanced grading form data.
     */
    public function test_resolve_success(): void {
        $assessment = [
            ['criterion' => 'spelling is important!', 'level' => 'no mistakes', 'remark' => 'Flawless.'],
            ['criterion' => 'Pictures', 'level' => 'unknown wording', 'score' => '1', 'remark' => ''],
        ];
        $formdata = rubric_grade_applier::resolve($assessment, $this->sample_criteria());

        $this->assertNotNull($formdata);
        $this->assertSame(103, $formdata['criteria'][11]['levelid']);
        $this->assertSame('Flawless.', $formdata['criteria'][11]['remark']);
        $this->assertSame(202, $formdata['criteria'][12]['levelid']);
        $this->assertSame(FORMAT_PLAIN, $formdata['criteria'][12]['remarkformat']);
    }

    /**
     * Any mismatch aborts the whole application (feedback-only fallback).
     *
     * @dataProvider resolve_failure_provider
     * @param array $assessment The model assessment.
     */
    public function test_resolve_failure(array $assessment): void {
        $this->assertNull(rubric_grade_applier::resolve($assessment, $this->sample_criteria()));
    }

    /**
     * Data provider for test_resolve_failure.
     *
     * @return array
     */
    public static function resolve_failure_provider(): array {
        return [
            'missing criterion' => [[
                ['criterion' => 'Spelling is important', 'level' => 'No mistakes'],
            ]],
            'unknown criterion' => [[
                ['criterion' => 'Grammar', 'level' => 'No mistakes'],
                ['criterion' => 'Pictures', 'level' => 'One picture'],
            ]],
            'duplicate criterion' => [[
                ['criterion' => 'Pictures', 'level' => 'One picture'],
                ['criterion' => 'Pictures', 'level' => 'No pictures'],
            ]],
            'unknown level and score' => [[
                ['criterion' => 'Spelling is important', 'level' => 'Perfect', 'score' => 7],
                ['criterion' => 'Pictures', 'level' => 'One picture'],
            ]],
            'malformed entry' => [[
                'not an array',
                ['criterion' => 'Pictures', 'level' => 'One picture'],
            ]],
        ];
    }

    /**
     * End to end: the adhoc task applies the rubric filling and keeps the grade in review.
     */
    public function test_adhoc_task_applies_rubric_in_review(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment(['markingworkflow' => 1, 'grade' => 100]);
        $controller = $this->create_rubric($env);
        $this->create_and_submit($env, 'An essay with one picture.');
        $aifid = $this->create_aif_config($env, 'Evaluate based on rubric', 0);
        $DB->set_field('assignfeedback_aif', 'applyrubricgrades', 1, ['id' => $aifid]);

        $this->setup_ai_mock(self::RESPONSE);
        $this->run_adhoc_task($env);

        // Feedback stored without the JSON block.
        $feedback = $DB->get_record('assignfeedback_aif_feedback', ['aif' => $aifid], '*', MUST_EXIST);
        $this->assertStringContainsString('Good work overall.', $feedback->feedback);
        $this->assertStringNotContainsString('rubric', $feedback->feedback);

        // Rubric instance filled with the expected levels and remarks.
        $grade = $env->assignobj->get_user_grade($env->student->id, false);
        $instance = $controller->get_current_instance($env->teacher->id, $grade->id);
        $this->assertNotNull($instance);
        $filling = $instance->get_rubric_filling();
        $criteria = $controller->get_definition()->rubric_criteria;
        $expected = ['Spelling is important' => 2.0, 'Pictures' => 1.0];
        foreach ($criteria as $criterionid => $criterion) {
            $levelid = $filling['criteria'][$criterionid]['levelid'];
            $this->assertEquals($expected[$criterion['description']], $criterion['levels'][$levelid]['score']);
        }
        $this->assertSame('Flawless.', $filling['criteria'][array_key_first($criteria)]['remark']);

        // Grade 3 of 4 rubric points = 75 and workflow state "In review", not released.
        $this->assertEqualsWithDelta(75.0, (float) $grade->grade, 0.01);
        $this->assertEquals($env->teacher->id, $grade->grader);
        $flags = $env->assignobj->get_user_flags($env->student->id, false);
        $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW, $flags->workflowstate);
        $gradinginfo = grade_get_grades($env->course->id, 'mod', 'assign', $env->assign->id, $env->student->id);
        $this->assertNull($gradinginfo->items[0]->grades[$env->student->id]->grade);
    }

    /**
     * Without marking workflow nothing is applied, feedback is still stored.
     */
    public function test_adhoc_task_skips_without_marking_workflow(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment(['markingworkflow' => 0]);
        $controller = $this->create_rubric($env);
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env, 'Evaluate', 0);
        $DB->set_field('assignfeedback_aif', 'applyrubricgrades', 1, ['id' => $aifid]);

        $this->setup_ai_mock(self::RESPONSE);
        $this->run_adhoc_task($env);

        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));
        $grade = $env->assignobj->get_user_grade($env->student->id, false);
        $this->assertNull($controller->get_current_instance($env->teacher->id, $grade->id));
        $this->assertEquals(-1, $grade->grade);
    }

    /**
     * When the setting is off, the prompt does not request a JSON block and nothing is applied.
     */
    public function test_setting_off_leaves_grading_untouched(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment(['markingworkflow' => 1]);
        $controller = $this->create_rubric($env);
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env, 'Evaluate', 0);

        $this->setup_ai_mock(self::RESPONSE);
        $this->run_adhoc_task($env);

        $grade = $env->assignobj->get_user_grade($env->student->id, false);
        $this->assertNull($controller->get_current_instance($env->teacher->id, $grade->id));
        // The block is kept in the feedback because the feature is off.
        $feedback = $DB->get_record('assignfeedback_aif_feedback', ['aif' => $aifid], '*', MUST_EXIST);
        $this->assertStringContainsString('rubric', $feedback->feedback);
    }

    /**
     * The settings form warns while the assignment has no rubric with criteria.
     */
    public function test_settings_form_warns_without_rubric(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/formslib.php');

        $env = $this->create_test_environment(['markingworkflow' => 1]);
        $this->setUser($env->teacher);
        $plugin = $env->assignobj->get_feedback_plugin_by_type('aif');

        $mform = new \MoodleQuickForm('aiftest', 'post', '');
        $plugin->get_settings($mform);
        $this->assertTrue($mform->elementExists('assignfeedback_aif_norubricnotice'));

        $this->create_rubric($env);
        $mform = new \MoodleQuickForm('aiftest2', 'post', '');
        $plugin->get_settings($mform);
        $this->assertFalse($mform->elementExists('assignfeedback_aif_norubricnotice'));
    }

    /**
     * Rubric method active but no criteria defined: the task logs the skip and stays feedback-only.
     */
    public function test_adhoc_task_logs_when_rubric_has_no_criteria(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment(['markingworkflow' => 1]);
        get_grading_manager($env->context, 'mod_assign', 'submissions')->set_active_method('rubric');
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env, 'Evaluate', 0);
        $DB->set_field('assignfeedback_aif', 'applyrubricgrades', 1, ['id' => $aifid]);

        $this->setup_ai_mock("Plain feedback only.\n");
        $output = $this->run_adhoc_task($env);

        $this->assertStringContainsString(get_string('rubricapplyskipped_norubric', 'assignfeedback_aif'), $output);
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));
        $this->assertEquals(0, $DB->count_records('grading_instances'));
    }

    /**
     * An automatic run has no triggering teacher, so the site admin is recorded as rater.
     */
    public function test_auto_run_records_admin_as_grader(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment(['markingworkflow' => 1, 'grade' => 100]);
        $controller = $this->create_rubric($env);
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env, 'Evaluate', 1);
        $DB->set_field('assignfeedback_aif', 'applyrubricgrades', 1, ['id' => $aifid]);

        $this->setup_ai_mock(self::RESPONSE);
        $this->run_adhoc_task($env, 'auto');

        $admin = get_admin();
        $grade = $env->assignobj->get_user_grade($env->student->id, false);
        $this->assertEquals($admin->id, $grade->grader);
        // Core resolves the current instance per item regardless of rater, so check the rater on the record.
        $instance = $controller->get_current_instance($admin->id, $grade->id);
        $this->assertNotNull($instance);
        $this->assertEquals($admin->id, $instance->get_data('raterid'));
        $flags = $env->assignobj->get_user_flags($env->student->id, false);
        $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW, $flags->workflowstate);
    }

    /**
     * Create the standard test rubric (two criteria, scores 0-2) as the teacher.
     *
     * @param \stdClass $env The test environment.
     * @return \gradingform_rubric_controller
     */
    private function create_rubric(\stdClass $env): \gradingform_rubric_controller {
        $this->setUser($env->teacher);
        return $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')
            ->get_test_rubric($env->context, 'mod_assign', 'submissions');
    }

    /**
     * Register a mock AI request provider returning the given response.
     *
     * @param string $response The response text.
     */
    private function setup_ai_mock(string $response): void {
        $mock = $this->createMock(\assignfeedback_aif\local\ai_request_provider::class);
        $mock->method('perform_request_core_ai')->willReturn($response);
        $mock->method('perform_request_local_ai_manager')->willReturn($response);
        $mock->method('is_available')->willReturn(true);
        $mock->method('get_unavailability_reason')->willReturn(null);
        \core\di::set(\assignfeedback_aif\local\ai_request_provider::class, $mock);
    }

    /**
     * Run the adhoc task for the student.
     *
     * 'manual' runs as if the teacher triggered it, 'auto' as the submission observer would queue it.
     *
     * @param \stdClass $env The test environment.
     * @param string $triggeredby 'manual' or 'auto'.
     * @return string The task output (mtrace lines).
     */
    private function run_adhoc_task(\stdClass $env, string $triggeredby = 'manual'): string {
        $task = new process_feedback_adhoc();
        $task->set_custom_data([
            'assignment' => $env->assign->id,
            'users' => [$env->student->id],
            'action' => 'generate',
            'triggeredby' => $triggeredby,
        ]);
        if ($triggeredby === 'manual') {
            $this->setUser($env->teacher);
            $task->set_userid($env->teacher->id);
        } else {
            $this->setAdminUser();
        }
        ob_start();
        $task->execute();
        return ob_get_clean();
    }
}
