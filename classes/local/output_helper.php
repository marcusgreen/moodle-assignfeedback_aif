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
 * Rendering helper for AI feedback output components.
 *
 * Extracts rendering logic from locallib.php so the main plugin class
 * remains a thin delegation layer over the assign_feedback_plugin API.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class output_helper {
    /** @var bool Whether the generating spinner has already been rendered on this page. */
    private static bool $spinnerrendered = false;

    /** @var bool Whether the retry JS module has been initialised. */
    private static bool $retryinitialised = false;

    /** @var bool Whether the warning box JS module has been registered. */
    private static bool $warningboxregistered = false;

    /**
     * Render local_ai_manager infobox and quota widgets into the grading form.
     *
     * Only adds elements when the local_ai_manager backend is configured.
     *
     * @param \MoodleQuickForm $mform The form to add elements to.
     * @param int $userid The current user ID.
     */
    public static function render_ai_manager_widgets(\MoodleQuickForm $mform, int $userid): void {
        if (get_config('assignfeedback_aif', 'backend') !== 'local_ai_manager') {
            return;
        }
        if (!class_exists('\local_ai_manager\ai_manager_utils')) {
            return;
        }
        global $PAGE;
        $mform->addElement('html', '<div data-aif="aiinfo"></div>');
        $mform->addElement('html', '<div data-aif="aiuserquota" class="mb-2"></div>');
        $PAGE->requires->js_call_amd(
            'local_ai_manager/infobox',
            'renderInfoBox',
            ['assignfeedback_aif', $userid, '[data-aif="aiinfo"]', ['feedback', 'itt']]
        );
        $PAGE->requires->js_call_amd(
            'local_ai_manager/userquota',
            'renderUserQuota',
            ['[data-aif="aiuserquota"]', ['feedback', 'itt']]
        );
    }

    /**
     * Render local_ai_manager infobox (data sharing notice) into a settings form.
     *
     * Only adds the infobox when the local_ai_manager backend is configured.
     * Unlike render_ai_manager_widgets(), this does not include the user quota
     * since the settings form does not trigger AI requests.
     *
     * @param \MoodleQuickForm $mform The form to add the infobox to.
     */
    public static function render_ai_manager_infobox(\MoodleQuickForm $mform): void {
        if (get_config('assignfeedback_aif', 'backend') !== 'local_ai_manager') {
            return;
        }
        if (!class_exists('\local_ai_manager\ai_manager_utils')) {
            return;
        }
        global $PAGE, $USER;
        $mform->addElement('html', '<div data-aif="aiinfo-settings"></div>');
        $PAGE->requires->js_call_amd(
            'local_ai_manager/infobox',
            'renderInfoBox',
            ['assignfeedback_aif', $USER->id, '[data-aif="aiinfo-settings"]', ['feedback', 'itt']]
        );
    }

    /**
     * Render an error notification with a retry button.
     *
     * Used in both view_summary() and view() when feedback generation failed.
     * The retry button triggers a new adhoc task via the retry_feedback webservice.
     *
     * @param string $errormsg The error message to display.
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return string HTML with error notification and retry button.
     */
    public static function render_error_with_retry(string $errormsg, int $assignmentid, int $userid): string {
        global $OUTPUT, $PAGE;
        $html = $OUTPUT->render_from_template('assignfeedback_aif/error_with_retry', [
            'errormsg' => $errormsg,
            'assignmentid' => $assignmentid,
            'userid' => $userid,
            'retrylabel' => get_string('retrygeneration', 'assignfeedback_aif'),
        ]);
        if (!self::$retryinitialised) {
            $PAGE->requires->js_call_amd('assignfeedback_aif/feedbackpoller', 'initRetryAll', []);
            self::$retryinitialised = true;
        }
        return $html;
    }

    /**
     * Render the ai_manager warning box about AI result quality.
     *
     * Only renders when the local_ai_manager backend is configured.
     * The JS AMD call is registered only once to prevent duplicate
     * warning boxes when multiple feedback views exist on a page.
     *
     * The warning box is rendered inside the view_summary content and then
     * repositioned via JS before the expand/collapse toggle so it remains
     * always visible regardless of the feedback collapse state.
     *
     * @return string HTML for the warning box container.
     */
    public static function render_warningbox(): string {
        if (get_config('assignfeedback_aif', 'backend') !== 'local_ai_manager') {
            return '';
        }
        if (!class_exists('\local_ai_manager\ai_manager_utils')) {
            return '';
        }

        if (!self::$warningboxregistered) {
            global $PAGE;
            $PAGE->requires->js_call_amd(
                'local_ai_manager/warningbox',
                'renderWarningBox',
                ['[data-aif="aiwarning"]']
            );
            $PAGE->requires->js_call_amd(
                'assignfeedback_aif/warningbox_position',
                'init',
                ['[data-aif="aiwarning"]']
            );
            self::$warningboxregistered = true;
        }
        global $OUTPUT;
        return $OUTPUT->render_from_template('assignfeedback_aif/warningbox', []);
    }

    /**
     * Render the stored progress bar and start polling for a running task.
     *
     * Used in the summary view when a task is actively running with stored progress.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @param int $progressid The stored_progress record ID.
     * @return string HTML with progress bar and JS initialisation.
     */
    public static function render_generating_progress(int $assignmentid, int $userid, int $progressid): string {
        global $OUTPUT, $PAGE;
        $PAGE->requires->js_call_amd(
            'assignfeedback_aif/feedbackpoller',
            'initWithProgress',
            [$assignmentid, $userid, $progressid]
        );
        self::$spinnerrendered = true;
        return $OUTPUT->render_from_template('assignfeedback_aif/feedback_generating', [
            'message' => get_string('feedbackgenerating', 'assignfeedback_aif'),
        ]);
    }

    /**
     * Render the spinner and start the polling JS module.
     *
     * @param int $assignmentid The assignment ID.
     * @param int $userid The user ID.
     * @return string HTML with spinner and JS initialisation.
     */
    public static function render_generating_spinner(int $assignmentid, int $userid): string {
        global $OUTPUT, $PAGE;
        $PAGE->requires->js_call_amd(
            'assignfeedback_aif/feedbackpoller',
            'init',
            [$assignmentid, $userid]
        );
        self::$spinnerrendered = true;
        return $OUTPUT->render_from_template('assignfeedback_aif/feedback_generating', [
            'message' => get_string('waitingforadhoctaskstart', 'assignfeedback_aif'),
        ]);
    }

    /**
     * Check whether a spinner has already been rendered on this page.
     *
     * Used by the before_footer hook to avoid duplicate spinners.
     *
     * @return bool True if the spinner was already rendered.
     */
    public static function is_spinner_rendered(): bool {
        return self::$spinnerrendered;
    }

    /**
     * Format the skipped files notice for a feedback record.
     *
     * Renders a warning notification listing files that were skipped
     * during AI feedback generation, with localised reason strings.
     *
     * @param \stdClass $record The feedback record containing skippedfiles JSON.
     * @return string HTML notification or empty string if no skipped files.
     */
    public static function format_skipped_files_notice(\stdClass $record): string {
        if (empty($record->skippedfiles)) {
            return '';
        }
        $skipped = json_decode($record->skippedfiles, true);
        if (empty($skipped)) {
            return '';
        }
        $filelist = [];
        foreach ($skipped as $entry) {
            if (is_array($entry) && isset($entry['filename'])) {
                $reasonkey = $entry['reason'] ?? 'skipreason_conversionnotsupported';
                $reasondata = $entry['reasondata'] ?? null;
                $reason = get_string($reasonkey, 'assignfeedback_aif', $reasondata);
                $filelist[] = s($entry['filename']) . ' (' . s($reason) . ')';
            } else {
                // Legacy format: plain filename string.
                $filelist[] = s($entry);
            }
        }
        global $OUTPUT;
        return $OUTPUT->notification(
            get_string('feedbackskippedfiles', 'assignfeedback_aif', implode(', ', $filelist)),
            \core\output\notification::NOTIFY_WARNING
        );
    }
}
