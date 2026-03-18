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
 * Tests for AI feedback tasks, observer and external API.
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aif;

defined('MOODLE_INTERNAL') || die();

use assignfeedback_aif\task\process_feedback_rubric;
use assignfeedback_aif\task\process_feedback_rubric_adhoc;
use assignfeedback_aif\external\regenerate_feedback;

require_once(__DIR__ . '/../../../tests/generator.php');

/**
 * Tests for scheduled tasks, adhoc tasks, event observer and external API.
 *
 * @package    assignfeedback_aif
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \assignfeedback_aif\task\process_feedback_rubric
 * @covers \assignfeedback_aif\task\process_feedback_rubric_adhoc
 * @covers \assignfeedback_aif\event\observer
 * @covers \assignfeedback_aif\external\regenerate_feedback
 */
final class process_feedback_test extends \advanced_testcase {
    /**
     * Set up the DI mock for the AI request provider before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setup_ai_mock();
    }

    /**
     * Register a mock AI request provider in the DI container.
     *
     * @param string $response The response to return from the mock.
     */
    private function setup_ai_mock(string $response = 'AI Feedback'): void {
        $mock = $this->createMock(\assignfeedback_aif\local\ai_request_provider::class);
        $mock->method('perform_request_core_ai')->willReturn($response);
        $mock->method('perform_request_local_ai_manager')->willReturn($response);
        $mock->method('is_available')->willReturn(true);
        \core\di::set(\assignfeedback_aif\local\ai_request_provider::class, $mock);
    }

    /**
     * Test the dispatcher scheduled task enqueues adhoc tasks for unprocessed submissions.
     */
    public function test_rubric_scheduled_task_generates_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'My essay about renewable energy');
        $this->create_aif_config($env, 'Evaluate based on rubric');

        $this->assertEquals(0, $DB->count_records('assignfeedback_aif_feedback'));

        $task = new process_feedback_rubric();
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback'));
    }

    /**
     * Test the rubric scheduled task skips submissions with existing feedback.
     */
    public function test_rubric_scheduled_task_skips_existing(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Test essay');
        $aifid = $this->create_aif_config($env, 'Evaluate');

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);

        // Pre-insert feedback.
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Already processed',
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);

        $task = new process_feedback_rubric();
        ob_start();
        $task->execute();
        ob_end_clean();

        // No new feedback created.
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback'));
    }

    /**
     * Test the adhoc task generates feedback for a specific user.
     */
    public function test_adhoc_task_generates_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student assignment text');
        $this->create_aif_config($env, 'Provide feedback');

        $task = new process_feedback_rubric_adhoc();
        $task->set_custom_data([
            'assignment' => $env->assign->id,
            'users' => [$env->student->id],
            'action' => 'generate',
        ]);

        $this->assertEquals(0, $DB->count_records('assignfeedback_aif_feedback'));

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback'));
    }

    /**
     * Test the adhoc task deletes feedback for a specific user.
     */
    public function test_adhoc_task_deletes_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student text');
        $aifid = $this->create_aif_config($env, 'Test prompt');

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);

        // Insert feedback to delete.
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Feedback to remove',
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback'));

        $task = new process_feedback_rubric_adhoc();
        $task->set_custom_data([
            'assignment' => $env->assign->id,
            'users' => [$env->student->id],
            'action' => 'delete',
        ]);
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals(0, $DB->count_records('assignfeedback_aif_feedback'));
    }

    /**
     * Test that the observer queues an adhoc task when autogenerate is enabled.
     */
    public function test_observer_queues_task_when_autogenerate_enabled(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Analyse grammar', 1);

        // Submit as student — this triggers the assessable_submitted event.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $this->setUser($env->student);
        $submissiondata = [
            'cmid' => $env->cm->id,
            'userid' => $env->student->id,
            'onlinetext' => 'My submission for auto-generate test',
        ];
        $generator->create_submission($submissiondata);

        // Count adhoc tasks before submission.
        $tasksbefore = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_rubric_adhoc',
        ]);

        $sink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $env->student->id], []);
        $sink->close();

        // An adhoc task should have been queued.
        $tasksafter = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_rubric_adhoc',
        ]);
        $this->assertGreaterThan($tasksbefore, $tasksafter);

        // Verify the queued task contains the correct student userid (not null).
        $task = $DB->get_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_rubric_adhoc',
        ], 'id DESC', '*', 0, 1);
        $task = reset($task);
        $customdata = json_decode($task->customdata);
        $this->assertContains(
            $env->student->id,
            $customdata->users,
            'Adhoc task must contain the submitting student userid, not null'
        );
    }

    /**
     * Diagnostic test: trace every checkpoint in the autogenerate chain (submissiondrafts=1).
     *
     * Uses submit_for_grading() which is the path when "Require students to click submit" is YES.
     * Events are NOT intercepted with redirectEvents() so the observer actually runs.
     */
    public function test_observer_autogenerate_diagnostic(): void {
        global $DB;
        $this->resetAfterTest();

        // Setup: assignment with submissiondrafts=1 and autogenerate enabled.
        $env = $this->create_test_environment([
            'assignfeedback_aif_autogenerate' => 1,
            'submissiondrafts' => 1,
        ]);

        // CP1: AIF config record exists with autogenerate=1.
        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => $env->assign->id]);
        $this->assertNotEmpty(
            $aifconfig,
            'CP1a: assignfeedback_aif record must exist for assign.id=' . $env->assign->id
            . '. All records: ' . json_encode($DB->get_records('assignfeedback_aif'))
        );
        $this->assertEquals(1, (int) $aifconfig->autogenerate, 'CP1b: autogenerate must be 1');

        // CP2: AIF plugin enabled on assignment.
        $aifenabled = false;
        foreach ($env->assignobj->get_feedback_plugins() as $plugin) {
            if ($plugin->get_type() === 'aif') {
                $aifenabled = !empty($plugin->is_enabled());
                break;
            }
        }
        $this->assertTrue($aifenabled, 'CP2: AIF feedback plugin must be enabled');

        // Student creates a draft submission.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $this->setUser($env->student);
        $generator->create_submission([
            'cmid' => $env->cm->id,
            'userid' => $env->student->id,
            'onlinetext' => 'Diagnostic test submission text',
        ]);

        // CP3: Submission record exists.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $this->assertNotEmpty($submission, 'CP3: assign_submission record must exist');

        // Count tasks before submission.
        $taskclass = '\\assignfeedback_aif\\task\\process_feedback_rubric_adhoc';
        $tasksbefore = $DB->count_records('task_adhoc', ['classname' => $taskclass]);

        // Submit_for_grading fires assessable_submitted, observer runs, task queued.
        // Only redirect messages (notifications), NOT events, so the observer runs.
        $msgsink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $env->student->id], []);
        $msgsink->close();

        // CP4: Adhoc task was queued by the observer.
        $tasksafter = $DB->count_records('task_adhoc', ['classname' => $taskclass]);
        $this->assertGreaterThan(
            $tasksbefore,
            $tasksafter,
            'CP4: Adhoc task must be queued after submit_for_grading. '
            . "Before={$tasksbefore}, After={$tasksafter}"
        );

        // CP5: Task has correct custom data.
        $task = $DB->get_records('task_adhoc', ['classname' => $taskclass], 'id DESC', '*', 0, 1);
        $task = reset($task);
        $customdata = json_decode($task->customdata);
        $this->assertContains(
            $env->student->id,
            $customdata->users,
            'CP5a: Task users must contain the student. customdata=' . $task->customdata
        );
        $this->assertEquals(
            $env->assign->id,
            $customdata->assignment,
            'CP5b: Task assignment must be the assign instance id'
        );
        $this->assertEquals('generate', $customdata->action, 'CP5c: Task action must be generate');

        // CP6: Execute the adhoc task — feedback is created.
        $adhoctask = new process_feedback_rubric_adhoc();
        $adhoctask->set_custom_data($customdata);
        ob_start();
        $adhoctask->execute();
        ob_end_clean();

        $this->assertGreaterThan(
            0,
            $DB->count_records('assignfeedback_aif_feedback'),
            'CP6: Feedback record must be created after task execution'
        );
    }

    /**
     * Diagnostic test: autogenerate chain with submissiondrafts=0 (Moodle default).
     *
     * When "Require students to click submit" is NO (default), the assessable_submitted
     * event fires from save_submission() — a different code path than submit_for_grading().
     * This test ensures the observer works for the default production configuration.
     */
    public function test_observer_autogenerate_no_submissiondrafts(): void {
        global $DB;
        $this->resetAfterTest();

        // Setup: submissiondrafts=0 (default) with autogenerate enabled.
        $env = $this->create_test_environment([
            'assignfeedback_aif_autogenerate' => 1,
            'submissiondrafts' => 0,
        ]);

        // CP1: AIF config with autogenerate=1.
        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => $env->assign->id]);
        $this->assertNotEmpty($aifconfig, 'CP1a: AIF config must exist');
        $this->assertEquals(1, (int) $aifconfig->autogenerate, 'CP1b: autogenerate must be 1');

        // Count tasks before.
        $taskclass = '\\assignfeedback_aif\\task\\process_feedback_rubric_adhoc';
        $tasksbefore = $DB->count_records('task_adhoc', ['classname' => $taskclass]);

        // Student saves a submission. When submissiondrafts=0, this auto-submits.
        // save_submission() fires assessable_submitted at line 7871 of mod/assign/locallib.php.
        $this->setUser($env->student);
        $msgsink = $this->redirectMessages();
        $env->assignobj->save_submission(
            (object) [
                'userid' => $env->student->id,
                'onlinetext_editor' => [
                    'text' => '<p>Submission via save_submission path</p>',
                    'format' => FORMAT_HTML,
                    'itemid' => file_get_unused_draft_itemid(),
                ],
            ],
            $notices
        );
        $msgsink->close();

        // CP2: Submission is auto-submitted (status = SUBMITTED, not DRAFT).
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $this->assertNotEmpty($submission, 'CP2a: Submission must exist');
        $this->assertEquals(
            ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            $submission->status,
            'CP2b: Submission status must be SUBMITTED when submissiondrafts=0'
        );

        // CP3: Adhoc task was queued.
        $tasksafter = $DB->count_records('task_adhoc', ['classname' => $taskclass]);
        $this->assertGreaterThan(
            $tasksbefore,
            $tasksafter,
            'CP3: Adhoc task must be queued via save_submission path. '
            . "Before={$tasksbefore}, After={$tasksafter}"
        );

        // CP4: Task has correct data and execution creates feedback.
        $task = $DB->get_records('task_adhoc', ['classname' => $taskclass], 'id DESC', '*', 0, 1);
        $task = reset($task);
        $customdata = json_decode($task->customdata);
        $this->assertContains(
            $env->student->id,
            $customdata->users,
            'CP4a: Task users must contain the student'
        );

        $adhoctask = new process_feedback_rubric_adhoc();
        $adhoctask->set_custom_data($customdata);
        ob_start();
        $adhoctask->execute();
        ob_end_clean();

        $this->assertGreaterThan(
            0,
            $DB->count_records('assignfeedback_aif_feedback'),
            'CP4b: Feedback record must be created'
        );
    }

    /**
     * Test that the observer does not queue a task when autogenerate is disabled.
     */
    public function test_observer_no_task_when_autogenerate_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Analyse grammar', 0);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $this->setUser($env->student);
        $submissiondata = [
            'cmid' => $env->cm->id,
            'userid' => $env->student->id,
            'onlinetext' => 'My submission without auto-generate',
        ];
        $generator->create_submission($submissiondata);

        $tasksbefore = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_rubric_adhoc',
        ]);

        $sink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $env->student->id], []);
        $sink->close();

        // No new adhoc task should be queued.
        $tasksafter = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_rubric_adhoc',
        ]);
        $this->assertEquals($tasksbefore, $tasksafter);
    }

    /**
     * Test that the submission_removed observer deletes associated AI feedback.
     */
    public function test_observer_submission_removed_deletes_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Text to be removed');
        $aifid = $this->create_aif_config($env, 'Prompt');

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);

        // Insert feedback.
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Feedback to be removed with submission',
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));

        // Use admin to remove submission (needs editothersubmission capability).
        $this->setAdminUser();
        $env->assignobj->remove_submission($env->student->id);

        // Feedback should be deleted.
        $this->assertEquals(0, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));
    }

    /**
     * Test the regenerate_feedback external API queues an adhoc task.
     */
    public function test_regenerate_external_api_queues_task(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student work');
        $this->create_aif_config($env, 'Test');

        // External function requires a teacher with grading capability.
        $this->setUser($env->teacher);

        $tasksbefore = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_rubric_adhoc',
        ]);

        $result = regenerate_feedback::execute($env->assign->id, $env->student->id);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['message']);

        $tasksafter = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_rubric_adhoc',
        ]);
        $this->assertGreaterThan($tasksbefore, $tasksafter);
    }

    /**
     * Test the regenerate_feedback external API requires grade capability.
     */
    public function test_regenerate_external_api_requires_capability(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student work');
        $this->create_aif_config($env, 'Test');

        // Student should not be able to regenerate feedback.
        $this->setUser($env->student);

        $this->expectException(\required_capability_exception::class);
        regenerate_feedback::execute($env->assign->id, $env->student->id);
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
            'userid' => $env->student->id,
            'onlinetext' => $text,
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

        // Update the existing config created by save_settings, or create a new one.
        $existing = $DB->get_record('assignfeedback_aif', ['assignment' => $env->assign->id]);
        if ($existing) {
            $existing->prompt = $prompt;
            $existing->autogenerate = $autogenerate;
            $DB->update_record('assignfeedback_aif', $existing);
            return $existing->id;
        }

        return $DB->insert_record('assignfeedback_aif', [
            'assignment' => $env->assign->id,
            'prompt' => $prompt,
            'autogenerate' => $autogenerate,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);
    }
}
