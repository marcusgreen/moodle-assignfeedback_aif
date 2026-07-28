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
     * Swap the AI request provider with a fake for behat testing.
     *
     * When running in the behat test environment and mock mode is enabled,
     * this replaces the real ai_request_provider with a fake that returns
     * configurable responses from DB config. This works across all PHP
     * processes (web, CLI cron) because the DI container is rebuilt per request.
     *
     * @param \core\hook\di_configuration $hook The DI configuration hook.
     */
    public static function configure_di(\core\hook\di_configuration $hook): void {
        if (!defined('BEHAT_SITE_RUNNING')) {
            return;
        }

        $hook->add_definition(
            \assignfeedback_aif\local\ai_request_provider::class,
            \DI\create(\assignfeedback_aif\testing\fake_ai_request_provider::class),
        );
    }

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
     * students need to be informed about AI availability.
     *
     * For students on the submission page: uses get_ai_config from local_ai_manager
     * to determine AI availability. Shows a data sharing notice when AI is available,
     * a warning with the errormessage when AI is disabled, or nothing when AI is hidden.
     * For teachers on the grading overview: shows a spinner when adhoc tasks are pending.
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

        // Student submission page: show notice or warning when autogenerate is enabled.
        if ($PAGE->pagetype === 'mod-assign-view' && !has_capability('mod/assign:grade', $context)) {
            $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => (int) $cm->instance]);
            if ($aifconfig && !empty($aifconfig->autogenerate)) {
                $action = optional_param('action', '', PARAM_ALPHA);
                if ($action === 'editsubmission') {
                    self::render_submission_infobox($hook, $context);
                } else if (
                    !$DB->record_exists('assign_submission', [
                    'assignment' => (int) $cm->instance,
                    'userid' => $USER->id,
                    'status' => 'submitted',
                    'latest' => 1,
                    ])
                ) {
                    // Student has no submission yet — show the AI manager infobox.
                    self::render_submission_infobox($hook, $context);
                } else {
                    $notice = self::get_student_ai_notice($context);
                    if ($notice !== null) {
                        $hook->add_html($notice);
                    }
                }
            }
            return;
        }

        // Teacher grading overview: show feedback summary widget.
        if ($PAGE->pagetype !== 'mod-assign-view') {
            return;
        }

        if (!has_capability('mod/assign:grade', $context)) {
            return;
        }

        // Only show the widget if the AIF plugin is configured for this assignment.
        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => (int) $cm->instance]);
        if (!$aifconfig) {
            return;
        }

        // Check if there are submitted submissions at all.
        $hassubmissions = $DB->record_exists('assign_submission', [
            'assignment' => (int) $cm->instance,
            'latest' => 1,
            'status' => 'submitted',
        ]);
        if (!$hassubmissions) {
            return;
        }

        // Render the summary widget with pre-populated data. The template loads its controller.
        $summarydata = self::get_summary_data((int) $cm->instance);
        $templatecontext = array_merge(
            [
                'assignmentid' => (int) $cm->instance,
                'widgetid' => 'assignfeedback-aif-summary-widget-' . (int) $cm->instance,
                'widgetselector' => '#assignfeedback-aif-summary-widget-' . (int) $cm->instance,
            ],
            $summarydata
        );
        $html = $OUTPUT->render_from_template('assignfeedback_aif/feedback_summary_widget', $templatecontext);
        $hook->add_html($html);
    }

    /**
     * Calculate the feedback summary data for an assignment.
     *
     * Returns counts and systemic errors for the progress widget template.
     * This data is also available via the get_assignment_feedback_summary webservice,
     * but pre-populating it avoids a visible empty-shell delay on page load.
     *
     * @param int $assignmentid The assignment instance ID.
     * @return array Summary data with counts, bar widths and formatted details.
     */
    private static function get_summary_data(int $assignmentid): array {
        global $DB;

        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if (!$aif) {
            return self::empty_summary_template_context();
        }

        $totalsubmissions = $DB->count_records('assign_submission', [
            'assignment' => $assignmentid,
            'latest' => 1,
            'status' => 'submitted',
        ]);

        if ($totalsubmissions === 0) {
            return self::empty_summary_template_context();
        }

        // Count feedback records by status.
        $sql = "SELECT aiff.status, COUNT(*) AS cnt
                  FROM {assignfeedback_aif_feedback} aiff
                  JOIN {assign_submission} sub ON sub.id = aiff.submission
                 WHERE aiff.aif = :aifid
                   AND sub.latest = 1
                   AND sub.status = 'submitted'
              GROUP BY aiff.status";
        $statuscounts = $DB->get_records_sql($sql, ['aifid' => $aif->id]);

        $completed = 0;
        $errors = 0;
        $pending = 0;
        foreach ($statuscounts as $row) {
            switch ($row->status) {
                case 'completed':
                    $completed = (int) $row->cnt;
                    break;
                case 'error':
                    $errors = (int) $row->cnt;
                    break;
                case 'pending':
                    $pending = (int) $row->cnt;
                    break;
            }
        }

        $notstarted = max(0, $totalsubmissions - $completed - $errors - $pending);
        $haspending = task_manager::has_pending_tasks($assignmentid);

        // Only count notstarted as pending when there are actually tasks queued.
        $activepending = $pending + ($haspending ? $notstarted : 0);

        // Calculate bar widths as percentages.
        $barcompleted = round($completed / $totalsubmissions * 100, 1);
        $barpending = round($activepending / $totalsubmissions * 100, 1);
        $barerrors = round($errors / $totalsubmissions * 100, 1);

        // Format counts string.
        $countstext = $completed . ' / ' . $totalsubmissions;

        $pendinglabel = get_string('widgetpending', 'assignfeedback_aif');
        $errorslabel = get_string('widgeterrors', 'assignfeedback_aif');
        $completedlabel = get_string('widgetcompleted', 'assignfeedback_aif');

        // Build systemic error list shown below the progress bar.
        $systemicerrors = [];
        if ($errors > 0) {
            $sql = "SELECT aiff.errormessage, COUNT(*) AS cnt
                      FROM {assignfeedback_aif_feedback} aiff
                      JOIN {assign_submission} sub ON sub.id = aiff.submission
                     WHERE aiff.aif = :aifid
                       AND aiff.status = 'error'
                       AND aiff.errormessage IS NOT NULL
                       AND aiff.errormessage <> ''
                       AND sub.latest = 1
                       AND sub.status = 'submitted'
                  GROUP BY aiff.errormessage
                    HAVING COUNT(*) > 1
                  ORDER BY cnt DESC";
            $records = $DB->get_records_sql($sql, ['aifid' => $aif->id]);
            foreach ($records as $record) {
                $systemicerrors[] = [
                    'message' => $record->errormessage,
                    'counttext' => get_string('widgetsystemicerror', 'assignfeedback_aif', (int) $record->cnt),
                ];
            }
        }

        return [
            'showwidget' => true,
            'hassummary' => true,
            'countstext' => $countstext,
            'barcompleted' => $barcompleted,
            'barpending' => $barpending,
            'barerrors' => $barerrors,
            'hasdetails' => ($activepending > 0 || $errors > 0 || $completed > 0),
            'hasactivepending' => $activepending > 0,
            'activependingcount' => $activepending,
            'pendinglabel' => $pendinglabel,
            'haserrors' => $errors > 0,
            'errorscount' => $errors,
            'errorslabel' => $errorslabel,
            'hascompleted' => $completed > 0,
            'completedcount' => $completed,
            'completedlabel' => $completedlabel,
            'systemicerrors' => $systemicerrors,
            'hassystemicerrors' => !empty($systemicerrors),
        ];
    }

    /**
     * Return an empty template context for the feedback summary widget.
     *
     * @return array Empty summary context.
     */
    private static function empty_summary_template_context(): array {
        return [
            'showwidget' => false,
            'hassummary' => false,
            'countstext' => '',
            'barcompleted' => 0,
            'barpending' => 0,
            'barerrors' => 0,
            'hasdetails' => false,
            'hasactivepending' => false,
            'activependingcount' => 0,
            'pendinglabel' => '',
            'haserrors' => false,
            'errorscount' => 0,
            'errorslabel' => '',
            'hascompleted' => false,
            'completedcount' => 0,
            'completedlabel' => '',
            'systemicerrors' => [],
            'hassystemicerrors' => false,
        ];
    }

    /**
     * Render the local_ai_manager infobox on the edit submission page.
     *
     * Shows the data sharing notice ("entered data will be sent to an external AI system")
     * so students are aware before typing their submission.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The hook instance.
     * @param \context $context The module context.
     */
    private static function render_submission_infobox(
        \core\hook\output\before_footer_html_generation $hook,
        \context $context
    ): void {
        global $PAGE, $USER;

        if (get_config('assignfeedback_aif', 'backend') !== 'local_ai_manager') {
            return;
        }
        if (!class_exists('\local_ai_manager\ai_manager_utils')) {
            return;
        }

        $aiconfig = \local_ai_manager\ai_manager_utils::get_ai_config($USER, $context->id, null, ['feedback']);
        $status = $aiconfig['availability']['available'] ?? '';
        if ($status !== \local_ai_manager\ai_manager_utils::AVAILABILITY_AVAILABLE) {
            return;
        }

        $hook->add_html('<div data-aif="submission-infobox"></div>');
        $PAGE->requires->js_call_amd(
            'local_ai_manager/infobox',
            'renderInfoBox',
            ['assignfeedback_aif', $USER->id, '[data-aif="submission-infobox"]', ['feedback', 'itt']]
        );
    }

    /**
     * Get the AI notice HTML for a student on the submission page.
     *
     * Uses get_ai_config from local_ai_manager to determine the availability
     * status and returns the appropriate notification:
     * - available: data sharing info notice
     * - disabled: warning with the errormessage from ai_manager
     * - hidden: null (no notice, student should not know about AI)
     *
     * Falls back to the ai_request_provider for the core_ai_subsystem backend.
     *
     * @param \context $context The module context.
     * @return string|null The notification HTML, or null if nothing should be shown.
     */
    private static function get_student_ai_notice(\context $context): ?string {
        global $OUTPUT, $USER, $PAGE;

        $backend = get_config('assignfeedback_aif', 'backend') ?: 'core_ai_subsystem';

        if ($backend === 'local_ai_manager' && class_exists('\local_ai_manager\ai_manager_utils')) {
            $aiconfig = \local_ai_manager\ai_manager_utils::get_ai_config($USER, $context->id, null, ['feedback']);

            // Check general availability first.
            $generalstatus = $aiconfig['availability']['available'] ?? '';
            if ($generalstatus === \local_ai_manager\ai_manager_utils::AVAILABILITY_HIDDEN) {
                return null;
            }
            if ($generalstatus !== \local_ai_manager\ai_manager_utils::AVAILABILITY_AVAILABLE) {
                $message = $aiconfig['availability']['errormessage']
                    ?: get_string('ainavailable', 'assignfeedback_aif');
                return $OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING);
            }

            // General is available — check purpose-specific availability for 'feedback'.
            foreach ($aiconfig['purposes'] as $purposeconfig) {
                if ($purposeconfig['purpose'] !== 'feedback') {
                    continue;
                }
                if ($purposeconfig['available'] === \local_ai_manager\ai_manager_utils::AVAILABILITY_HIDDEN) {
                    return null;
                }
                if ($purposeconfig['available'] !== \local_ai_manager\ai_manager_utils::AVAILABILITY_AVAILABLE) {
                    $message = $purposeconfig['errormessage']
                        ?: get_string('ainavailable', 'assignfeedback_aif');
                    return $OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING);
                }
                break;
            }

            // AI is available: show data sharing notice via AI manager infobox widget.
            $PAGE->requires->js_call_amd(
                'local_ai_manager/infobox',
                'renderInfoBox',
                ['assignfeedback_aif', $USER->id, '[data-aif="student-ai-notice"]', ['feedback', 'itt']]
            );
            $PAGE->requires->js_call_amd(
                'local_ai_manager/userquota',
                'renderUserQuota',
                ['[data-aif="aiuserquota"]', ['feedback', 'itt']]
            );
            return '<div data-aif="student-ai-notice"></div><div data-aif="aiuserquota"></div>';
        }

        // Fallback for core_ai_subsystem backend.
        try {
            $provider = \core\di::get(\assignfeedback_aif\local\ai_request_provider::class);
            $reason = $provider->get_unavailability_reason('feedback', $context->id);
        } catch (\Exception $e) {
            $reason = get_string('ainavailable', 'assignfeedback_aif');
        }

        if ($reason === null) {
            return $OUTPUT->notification(
                get_string('studentsubmissionainotice', 'assignfeedback_aif'),
                \core\output\notification::NOTIFY_INFO
            );
        }
        return $OUTPUT->notification($reason, \core\output\notification::NOTIFY_WARNING);
    }
}
