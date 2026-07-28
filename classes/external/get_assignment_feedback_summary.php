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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core\context\module as context_module;

/**
 * External function to get an assignment-wide AI feedback summary.
 *
 * Returns counts of completed, pending, error and not-started feedback
 * records, plus systemic error messages that affect multiple submissions.
 * Used by the teacher-facing progress widget on the assignment view page.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assignment_feedback_summary extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'The assignment instance id'),
        ]);
    }

    /**
     * Get assignment-wide feedback generation summary.
     *
     * @param int $assignmentid The assignment instance id.
     * @return array Summary with counts and systemic errors.
     */
    public static function execute(int $assignmentid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
        ]);

        $assignment = $DB->get_record('assign', ['id' => $params['assignmentid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assignment->id, $assignment->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        // Check if the AIF plugin is configured for this assignment.
        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $params['assignmentid']]);
        if (!$aif) {
            return self::empty_summary();
        }

        // Count submitted submissions (latest=1, status=submitted).
        $totalsubmissions = $DB->count_records('assign_submission', [
            'assignment' => $params['assignmentid'],
            'latest' => 1,
            'status' => 'submitted',
        ]);

        if ($totalsubmissions === 0) {
            return self::empty_summary();
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

        $notstarted = $totalsubmissions - $completed - $errors - $pending;
        if ($notstarted < 0) {
            $notstarted = 0;
        }

        // Systemic errors: GROUP BY errormessage HAVING COUNT(*) > 1.
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
                    'count' => (int) $record->cnt,
                ];
            }
        }

        // Check if any adhoc tasks are still queued.
        $haspending = \assignfeedback_aif\local\task_manager::has_pending_tasks(
            (int) $params['assignmentid']
        );

        return [
            'totalsubmissions' => $totalsubmissions,
            'completed' => $completed,
            'errors' => $errors,
            'pending' => $pending + ($haspending && $pending === 0 ? 0 : 0),
            'notstarted' => $notstarted,
            'haspending' => $haspending,
            'systemicerrors' => $systemicerrors,
        ];
    }

    /**
     * Return an empty summary structure.
     *
     * @return array Empty summary.
     */
    private static function empty_summary(): array {
        return [
            'totalsubmissions' => 0,
            'completed' => 0,
            'errors' => 0,
            'pending' => 0,
            'notstarted' => 0,
            'haspending' => false,
            'systemicerrors' => [],
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'totalsubmissions' => new external_value(PARAM_INT, 'Total submitted submissions'),
            'completed' => new external_value(PARAM_INT, 'Submissions with completed AI feedback'),
            'errors' => new external_value(PARAM_INT, 'Submissions with failed AI feedback'),
            'pending' => new external_value(PARAM_INT, 'Submissions with pending AI feedback'),
            'notstarted' => new external_value(PARAM_INT, 'Submissions without any AI feedback record'),
            'haspending' => new external_value(PARAM_BOOL, 'Whether adhoc tasks are still queued'),
            'systemicerrors' => new external_multiple_structure(
                new external_single_structure([
                    'message' => new external_value(PARAM_RAW, 'The error message'),
                    'count' => new external_value(PARAM_INT, 'Number of submissions affected'),
                ]),
                'Systemic errors affecting multiple submissions',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
