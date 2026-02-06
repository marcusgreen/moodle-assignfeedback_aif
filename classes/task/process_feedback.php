<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace assignfeedback_aif\task;

defined('MOODLE_INTERNAL') || die();

/**
 * A scheduled task for processing AI feedback.
 *
 * @package     assignfeedback_aif
 * @copyright   2024 Marcus Green
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_feedback extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('processfeedbacktask', 'assignfeedback_aif');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB;

        $sql = "SELECT aif.id AS aifid,
                       aif.prompt AS prompt,
                       olt.onlinetext AS onlinetext,
                       sub.id AS subid,
                       cx.id AS contextid,
                       a.id AS aid,
                       a.name AS assignmentname
                FROM {assign} a
                JOIN {course_modules} cm ON cm.instance = a.id
                JOIN {context} cx ON cx.instanceid = cm.id AND cx.contextlevel = 70
                JOIN {assignfeedback_aif} aif ON aif.assignment = cm.id
                JOIN {assign_submission} sub ON sub.assignment = a.id
                LEFT JOIN {assignsubmission_onlinetext} olt ON olt.assignment = a.id AND olt.submission = sub.id
                WHERE sub.status = 'submitted'
                  AND sub.latest = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM {assignfeedback_aif_feedback} aiff
                      WHERE aiff.aif = aif.id AND aiff.submission = sub.id
                  )";

        $submissions = $DB->get_records_sql($sql);

        foreach ($submissions as $submission) {
            try {
                $aif = new \assignfeedback_aif\aif($submission->contextid);

                // Build prompt using template.
                $prompt = $aif->build_prompt_from_template(
                    $submission->onlinetext ?? '',
                    '', // No rubric in scheduled task.
                    $submission->prompt ?? '',
                    $submission->assignmentname ?? ''
                );

                if (empty(trim(strip_tags($submission->onlinetext ?? '')))) {
                    mtrace("Skipping submission {$submission->subid}: No text content.");
                    continue;
                }

                $aifeedback = $aif->perform_request($prompt);

                // Append disclaimer.
                $aifeedback = $aif->append_disclaimer($aifeedback);

                $data = (object) [
                    'aif' => $submission->aifid,
                    'feedback' => $aifeedback,
                    'timecreated' => time(),
                    'submission' => $submission->subid,
                ];
                $DB->insert_record('assignfeedback_aif_feedback', $data);

                mtrace("AI feedback generated for submission {$submission->subid}");
            } catch (\Exception $e) {
                mtrace("Error processing submission {$submission->subid}: " . $e->getMessage());
            }
        }
    }
}
