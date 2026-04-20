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
 * Tests for hook_callbacks and spinner label rendering.
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
 * Tests for hook_callbacks notifications and spinner label rendering.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_aif\local\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    use aif_test_helper;

    /**
     * Helper to dispatch the before_footer hook and return the collected HTML.
     *
     * Sets up $PAGE to simulate the assign view page for the given cm and user.
     *
     * @param \stdClass $cm The course module.
     * @param \stdClass $user The user viewing the page.
     * @return string The HTML added by the hook.
     */
    private function dispatch_before_footer(\stdClass $cm, \stdClass $user): string {
        global $PAGE;

        $this->setUser($user);

        $context = \core\context\module::instance($cm->id);

        // Simulate the assign view page.
        $PAGE->set_url(new \moodle_url('/mod/assign/view.php', ['id' => $cm->id]));
        $PAGE->set_context($context);
        $PAGE->set_pagetype('mod-assign-view');

        // Get a proper renderer_base instance (not the bootstrap_renderer that $OUTPUT is in tests).
        $output = $PAGE->get_renderer('core');

        $hook = new \core\hook\output\before_footer_html_generation($output);
        \assignfeedback_aif\local\hook_callbacks::before_footer($hook);

        return $hook->get_output();
    }

    /**
     * Test student sees data sharing notice when autogenerate is on and AI is available.
     *
     * @covers ::before_footer
     */
    public function test_student_sees_data_sharing_notice_when_ai_available(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Test prompt', 1);

        // Student must have a submission so the notice path is taken (not the infobox path).
        $this->create_and_submit($env, 'My submission text');

        // Set up real ai_manager infrastructure so AI is available for the student.
        $this->setup_ai_availability($env->student, true);

        $html = $this->dispatch_before_footer($env->cm, $env->student);

        $this->assertStringContainsString(
            get_string('studentsubmissionainotice', 'assignfeedback_aif'),
            $html,
            'Student should see data sharing notice when AI is available'
        );
        $this->assertStringNotContainsString(
            get_string('ainavailable', 'assignfeedback_aif'),
            $html,
            'Student should NOT see unavailability message when AI is available'
        );
    }

    /**
     * Test student sees no notice when autogenerate is on but AI is hidden.
     *
     * When AI is hidden (e.g. tenant not enabled, no capability), the student
     * should not see any notice at all — neither data sharing nor unavailability.
     *
     * @covers ::before_footer
     */
    public function test_student_sees_no_notice_when_ai_hidden(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Test prompt', 1);

        // Without AI manager config, AI is hidden for the student.
        $this->setup_ai_availability($env->student, false);

        $html = $this->dispatch_before_footer($env->cm, $env->student);

        // Student should NOT see any notice when AI is hidden.
        $this->assertStringNotContainsString(
            get_string('studentsubmissionainotice', 'assignfeedback_aif'),
            $html,
            'Student should NOT see data sharing notice when AI is hidden'
        );
        $this->assertEmpty(
            $html,
            'No notification should be shown when AI is hidden'
        );
    }

    /**
     * Test student sees warning with errormessage when AI is disabled.
     *
     * When AI is disabled (e.g. user is locked), the student should see
     * a warning notification with the errormessage from the ai_manager.
     *
     * @covers ::before_footer
     */
    public function test_student_sees_warning_when_ai_disabled(): void {
        $this->resetAfterTest();

        if (!class_exists(\local_ai_manager\ai_manager_utils::class)) {
            $this->markTestSkipped('local_ai_manager plugin is not installed.');
        }

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Test prompt', 1);

        // Student must have a submission so the notice path is taken (not the infobox path).
        $this->create_and_submit($env, 'My submission text');

        // Set up AI as available first, then lock the user to trigger 'disabled' state.
        $this->setup_ai_availability($env->student, true);

        // Lock the user so determine_availability returns AVAILABILITY_DISABLED.
        $userinfo = new \local_ai_manager\local\userinfo($env->student->id);
        $userinfo->set_locked(true);
        $userinfo->store();

        $html = $this->dispatch_before_footer($env->cm, $env->student);

        // Student should NOT see the data sharing notice.
        $this->assertStringNotContainsString(
            get_string('studentsubmissionainotice', 'assignfeedback_aif'),
            $html,
            'Student should NOT see data sharing notice when AI is disabled'
        );

        // Student should see a warning notification with the errormessage from ai_manager.
        $this->assertNotEmpty(
            $html,
            'Student should see a warning notification when AI is disabled'
        );
        $this->assertStringContainsString(
            get_string('error_http403blocked', 'local_ai_manager'),
            $html,
            'Warning should contain the errormessage from ai_manager'
        );
    }

    /**
     * Test student without submission sees infobox when AI is available.
     *
     * When a student has not yet submitted and AI is available, the AI manager
     * infobox widget should be rendered instead of the plain notification.
     *
     * @covers ::before_footer
     */
    public function test_student_without_submission_sees_infobox(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Test prompt', 1);

        // Set up real ai_manager infrastructure so AI is available for the student.
        $this->setup_ai_availability($env->student, true);

        // No submission created — student should see the infobox container.
        $html = $this->dispatch_before_footer($env->cm, $env->student);

        $this->assertStringContainsString(
            'data-aif="submission-infobox"',
            $html,
            'Student without submission should see the AI manager infobox'
        );
    }

    /**
     * Test student sees no notice when autogenerate is off.
     *
     * @covers ::before_footer
     */
    public function test_student_sees_no_notice_when_autogenerate_off(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Test prompt', 0);

        $html = $this->dispatch_before_footer($env->cm, $env->student);

        $this->assertStringNotContainsString(
            get_string('studentsubmissionainotice', 'assignfeedback_aif'),
            $html
        );
        $this->assertEmpty(
            $html,
            'No notification should be shown when autogenerate is off'
        );
    }

    /**
     * Test that view_summary renders spinner with waiting message when feedback is pending.
     *
     * @covers \assign_feedback_aif::view_summary
     */
    public function test_view_summary_spinner_shows_waiting_message(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Test text for spinner');
        $this->create_aif_config($env, 'Test prompt', 1);

        // Get grade record (auto-created by observer on submission).
        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);

        $plugin = $this->get_aif_plugin($env->assignobj);

        // No feedback exists, but autogenerate=1 and submitted — spinner should render.
        $showviewlink = false;
        $result = $plugin->view_summary($grade, $showviewlink);

        $this->assertStringContainsString(
            get_string('waitingforadhoctaskstart', 'assignfeedback_aif'),
            $result,
            'Spinner should display the waiting message label'
        );
    }

    /**
     * Set up local_ai_manager infrastructure so that AI is available/unavailable for a user.
     *
     * Sets the plugin backend to local_ai_manager.
     * When $available is true, configures tenant, capability, tool instance and purpose
     * mapping so that get_ai_config returns 'feedback' as available.
     * When $available is false with ai_manager installed, the default unconfigured state
     * already results in unavailability.
     *
     * @param \stdClass $user The user to configure AI availability for.
     * @param bool $available Whether AI should be reported as available.
     */
    private function setup_ai_availability(\stdClass $user, bool $available): void {
        if (!class_exists(\local_ai_manager\ai_manager_utils::class)) {
            if ($available) {
                $this->markTestSkipped('local_ai_manager plugin is not installed.');
            }
            return;
        }

        // Use local_ai_manager backend so the provider delegates to the AI Manager.
        set_config('backend', 'local_ai_manager', 'assignfeedback_aif');

        if (!$available) {
            // Default state: no config means AI is not available.
            return;
        }

        // Grant the user the local/ai_manager:use capability.
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'aiuser_' . $user->id]);
        role_assign($roleid, $user->id, SYSCONTEXTID);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $roleid, SYSCONTEXTID);

        // Set the current user so the tenant is resolved from their profile.
        $this->setUser($user);
        $tenant = \core\di::get(\local_ai_manager\local\tenant::class);

        // Enable the tenant.
        $configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);
        $configmanager->set_config('tenantenabled', true);

        // Create a tool instance (chatgpt supports the feedback purpose).
        $factory = \core\di::get(\local_ai_manager\local\connector_factory::class);
        $instance = $factory->get_new_instance('chatgpt');
        $instance->store();

        // Map the feedback purpose to the tool instance for the basic role.
        $configmanager->set_config(
            \local_ai_manager\base_purpose::get_purpose_tool_config_key(
                'feedback',
                \local_ai_manager\local\userinfo::ROLE_BASIC
            ),
            $instance->get_id()
        );

        // Redirect the additional_user_restriction hook so block_ai_control does not interfere.
        $hookmanager = \core\di::get(\core\hook\manager::class);
        $hookmanager->phpunit_redirect_hook(
            \local_ai_manager\hook\additional_user_restriction::class,
            function ($hook) {
                $hook->set_access_allowed(true);
            }
        );
    }

    /**
     * Get the AIF feedback plugin instance from an assignment.
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
