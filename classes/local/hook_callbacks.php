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

namespace assignfeedback_aif\local;

/**
 * Hook listener callbacks for assignfeedback_aif.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Provide information about which AI purposes are being used by this plugin.
     *
     * @param \local_ai_manager\hook\purpose_usage $hook The purpose_usage hook object.
     */
    public static function handle_purpose_usage(\local_ai_manager\hook\purpose_usage $hook): void {
        $hook->set_component_displayname(
            'assignfeedback_aif',
            get_string('pluginname_userfaced', 'assignfeedback_aif')
        );
        $hook->add_purpose_usage_description(
            'feedback',
            'assignfeedback_aif',
            get_string('purposeplacedescription_feedback', 'assignfeedback_aif')
        );
        $hook->add_purpose_usage_description(
            'itt',
            'assignfeedback_aif',
            get_string('purposeplacedescription_itt', 'assignfeedback_aif')
        );
    }

    /**
     * Show notifications on assign pages when AI feedback tasks are pending or when
     * students need to be warned about data being sent to AI.
     *
     * For teachers on the grading overview: shows a spinner when adhoc tasks are pending.
     * Also warns teachers when autogenerate is active but AI is not enabled via
     * block_ai_control, so students cannot receive AI feedback.
     * For students on the submission page: shows a data sharing notice when autogenerate
     * is enabled, or an info notice when autogenerate is active but AI is not enabled.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The hook instance.
     */
    public static function before_footer(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $DB, $OUTPUT, $USER;

        // Only act on assign pages.
        if (!str_starts_with($PAGE->pagetype, 'mod-assign-')) {
            return;
        }

        $context = $PAGE->context;
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('assign', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        // Student submission page: show data sharing notice when autogenerate is enabled.
        if ($PAGE->pagetype === 'mod-assign-view' && !has_capability('mod/assign:grade', $context)) {
            $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => (int) $cm->instance]);
            if ($aifconfig && !empty($aifconfig->autogenerate)) {
                if (self::is_ai_available_for_user($USER, $context)) {
                    // AI is available: show normal data sharing notice.
                    $html = $OUTPUT->notification(
                        get_string('studentsubmissionainotice', 'assignfeedback_aif'),
                        \core\output\notification::NOTIFY_INFO
                    );
                    $hook->add_html($html);
                } else {
                    // AI is not available: inform student that AI feedback is not active.
                    $html = $OUTPUT->notification(
                        get_string('aicontrolinactive_student', 'assignfeedback_aif'),
                        \core\output\notification::NOTIFY_INFO
                    );
                    $hook->add_html($html);
                }
            }
            return;
        }

        // Teacher grading overview: show spinner when adhoc tasks are pending.
        if ($PAGE->pagetype !== 'mod-assign-view') {
            return;
        }

        if (!has_capability('mod/assign:grade', $context)) {
            return;
        }

        // Warn teacher when autogenerate is active but AI is not enabled.
        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => (int) $cm->instance]);
        if ($aifconfig && !empty($aifconfig->autogenerate) && !self::is_ai_active_for_context($context)) {
            $html = $OUTPUT->notification(
                get_string('aicontrolinactive_teacher', 'assignfeedback_aif'),
                \core\output\notification::NOTIFY_WARNING
            );
            $hook->add_html($html);
        }

        // Check if there are pending adhoc tasks for this assignment.
        $taskclass = \assignfeedback_aif\task\process_feedback_adhoc::class;
        $tasks = \core\task\manager::get_adhoc_tasks($taskclass);
        $pending = false;
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            if (isset($data->assignment) && (int) $data->assignment === (int) $cm->instance) {
                $pending = true;
                break;
            }
        }

        if (!$pending) {
            return;
        }

        // Skip if view_summary() already rendered a spinner for this page.
        if (\assign_feedback_aif::is_spinner_rendered()) {
            return;
        }

        // Render the spinner notification and start the poller.
        $html = $OUTPUT->render_from_template('assignfeedback_aif/feedback_generating', [
            'message' => get_string('waitingforadhoctaskstart', 'assignfeedback_aif'),
        ]);
        $hook->add_html($html);

        $PAGE->requires->js_call_amd(
            'assignfeedback_aif/feedbackpoller',
            'init',
            [(int) $cm->instance, 0]
        );
    }

    /**
     * Check if AI feedback is available for a user via local_ai_manager.
     *
     * Uses the full ai_manager availability stack which evaluates tenant config,
     * purpose configuration, quotas, and block_ai_control restrictions via hooks.
     *
     * @param \stdClass $user The user to check availability for.
     * @param \context $context The module context.
     * @return bool True if the 'feedback' purpose is available for the user.
     */
    private static function is_ai_available_for_user(\stdClass $user, \context $context): bool {
        if (!class_exists(\local_ai_manager\ai_manager_utils::class)) {
            return false;
        }

        try {
            $aiconfig = \local_ai_manager\ai_manager_utils::get_ai_config($user, $context->id, null, ['feedback']);

            // General availability must be 'available'.
            if ($aiconfig['availability']['available'] !== \local_ai_manager\ai_manager_utils::AVAILABILITY_AVAILABLE) {
                return false;
            }

            // The 'feedback' purpose must be 'available'.
            foreach ($aiconfig['purposes'] as $purposeconfig) {
                if (
                    $purposeconfig['purpose'] === 'feedback'
                        && $purposeconfig['available'] === \local_ai_manager\ai_manager_utils::AVAILABILITY_AVAILABLE
                ) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

    /**
     * Check if AI is active for the given module context via block_ai_control.
     *
     * Used for teacher warnings only, since teachers are exempt from
     * block_ai_control restrictions in the ai_manager hook chain.
     *
     * @param \context $context The module context to check.
     * @return bool True if AI is active, false otherwise.
     */
    private static function is_ai_active_for_context(\context $context): bool {
        if (!class_exists(\block_ai_control\local\aiconfig::class)) {
            return true;
        }

        $coursecontext = $context->get_course_context(false);
        if (!$coursecontext) {
            return false;
        }

        try {
            $aiconfig = new \block_ai_control\local\aiconfig($coursecontext->id);
            return $aiconfig->record_exists() && $aiconfig->is_enabled();
        } catch (\Exception $e) {
            return false;
        }
    }
}
