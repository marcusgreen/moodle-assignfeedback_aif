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

namespace assignfeedback_aif\task;

/**
 * Scheduled task for processing AI feedback with rubric grading.
 *
 * @package    assignfeedback_aif
 * @copyright  2025 Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_feedback_rubric extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskprocessfeedbackrubric', 'assignfeedback_aif');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute(): void {
        global $DB;

        $clock = \core\di::get(\core\clock::class);

        $sql = "SELECT sub.id AS subid,
                       cx.id AS contextid,
                       aif.id AS aifid,
                       aif.prompt AS prompt,
                       a.id AS aid,
                       a.name AS assignmentname,
                       olt.onlinetext AS onlinetext,
                       sub.userid
                  FROM {assign} a
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                  JOIN {context} cx ON cx.instanceid = cm.id AND cx.contextlevel = :contextlevel
                  JOIN {assignfeedback_aif} aif ON aif.assignment = cm.id
                  JOIN {assign_submission} sub ON sub.assignment = a.id
             LEFT JOIN {assignsubmission_onlinetext} olt ON olt.assignment = a.id AND olt.submission = sub.id
                 WHERE sub.status = :status
                   AND sub.latest = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM {assignfeedback_aif_feedback} aiff
                        WHERE aiff.aif = aif.id AND aiff.submission = sub.id
                   )";
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'status' => 'submitted',
        ];
        $records = $DB->get_records_sql($sql, $params);

        foreach ($records as $record) {
            if (empty($record)) {
                continue;
            }

            try {
                // Use the correct context for each submission.
                $aif = new \assignfeedback_aif\aif($record->contextid);
                $promptdata = $aif->get_prompt($record, 'rubric');
                if (empty($promptdata['prompt'])) {
                    continue;
                }

                $aifeedback = $aif->perform_request($promptdata['prompt'], null, $promptdata['options']);

                // Append disclaimer to feedback.
                $aifeedback = $aif->append_disclaimer($aifeedback);

                $data = (object) [
                    'aif' => $record->aifid,
                    'feedback' => $aifeedback,
                    'timecreated' => $clock->now()->getTimestamp(),
                    'submission' => $record->subid,
                ];
                $DB->insert_record('assignfeedback_aif_feedback', $data);

                mtrace("AI feedback generated for submission {$record->subid}");
            } catch (\Exception $e) {
                mtrace("Error processing submission {$record->subid}: " . $e->getMessage());
            }
        }
    }
}
