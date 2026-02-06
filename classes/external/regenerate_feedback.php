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

namespace assignfeedback_aif\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use assignfeedback_aif\task\process_feedback_rubric_adhoc;
use context_module;
use core\task\manager;

/**
 * External function to regenerate AI feedback for a submission.
 *
 * @package    assignfeedback_aif
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regenerate_feedback extends external_api {

    /**
     * Describes the parameters for regenerate_feedback.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'The assignment instance id'),
            'userid' => new external_value(PARAM_INT, 'The user id to regenerate feedback for'),
        ]);
    }

    /**
     * Regenerate AI feedback for a submission.
     *
     * @param int $assignmentid The assignment instance id.
     * @param int $userid The user id.
     * @return array Result with success status and message.
     */
    public static function execute(int $assignmentid, int $userid): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
            'userid' => $userid,
        ]);

        // Get the assignment.
        $assignment = $DB->get_record('assign', ['id' => $params['assignmentid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assignment->id, $assignment->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Validate context and capability.
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        // Queue the ad-hoc task for this single user.
        $task = new process_feedback_rubric_adhoc();
        $task->set_custom_data([
            'assignment' => $params['assignmentid'],
            'users' => [$params['userid']],
            'action' => 'generate',
        ]);
        manager::queue_adhoc_task($task, true);

        return [
            'success' => true,
            'message' => get_string('regenerate_queued', 'assignfeedback_aif'),
        ];
    }

    /**
     * Describes the return value for regenerate_feedback.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the task was queued successfully'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
