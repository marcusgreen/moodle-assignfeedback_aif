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
            get_string('pluginname', 'assignfeedback_aif')
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
     * Show a generating spinner on the assign view page when AI feedback tasks are pending.
     *
     * Hooks into before_footer to inject a notification banner with spinner and
     * a polling script that reloads the page once all pending tasks are complete.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The hook instance.
     */
    public static function before_footer(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $DB, $OUTPUT;

        // Only act on the assign view page.
        if ($PAGE->pagetype !== 'mod-assign-view') {
            return;
        }

        // Get the assignment ID from the course module context.
        $context = $PAGE->context;
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        // Check capability — only show to graders.
        if (!has_capability('mod/assign:grade', $context)) {
            return;
        }

        $cm = get_coursemodule_from_id('assign', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        // Check if there are pending adhoc tasks for this assignment.
        $classname = '\\assignfeedback_aif\\task\\process_feedback_adhoc';
        $sql = "SELECT id FROM {task_adhoc} WHERE classname = :classname AND " .
               $DB->sql_like('customdata', ':pattern');
        $pending = $DB->record_exists_sql($sql, [
            'classname' => $classname,
            'pattern' => '%"assignment":' . (int) $cm->instance . '%',
        ]);

        if (!$pending) {
            return;
        }

        // Skip if view_summary() already rendered a spinner for this page.
        if (\assign_feedback_aif::is_spinner_rendered()) {
            return;
        }

        // Render the spinner notification and start the poller.
        $html = $OUTPUT->render_from_template('assignfeedback_aif/feedback_generating', []);
        $hook->add_html($html);

        $PAGE->requires->js_call_amd(
            'assignfeedback_aif/feedbackpoller',
            'init',
            [(int) $cm->instance, 0]
        );
    }
}
