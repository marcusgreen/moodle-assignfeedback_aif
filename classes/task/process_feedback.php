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
 * A scheduled task for assignfeedback_aif.
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
      /**
       * Equivalent cli
          SELECT  *
          FROM phpu6_assign a
          JOIN phpu6_course_modules cm
          ON cm.instance = a.id
          JOIN phpu6_assignfeedback_aif aif
          ON aif.assignment = cm.id
          JOIN phpu6_assign_submission sub
          ON sub.assignment = a.id
          JOIN phpu6_assignsubmission_onlinetext olt
          ON olt.assignment = a.id
          WHERE sub.status='submitted'\G;

       *
       */

        global $DB;
         $sql = "SELECT aif.id AS aifid, aif.prompt AS prompt,olt.onlinetext AS onlinetext, sub.id AS subid
                 FROM {assign} a
                 JOIN {course_modules} cm
                 ON cm.instance = a.id
                 JOIN {assignfeedback_aif} aif
                 ON aif.assignment = cm.id
                 JOIN {assign_submission} sub
                 ON sub.assignment = a.id
                 JOIN {assignsubmission_onlinetext} olt
                 ON olt.assignment = a.id
                 WHERE sub.status='submitted'";
        $assignments = $DB->get_records_sql($sql);
        $aif = new \assignfeedback_aif\aif(\context_system::instance()->id);
        foreach ($assignments as $assignment) {
          $prompt = $assignment->prompt . ' '.$assignment->onlinetext;
          $aifeedback =  $aif->perform_request($prompt);
          $data = (object) [
            'aif' => $assignment->aifid,
            'feedback' => $aifeedback,
            'timecreated' => time(),
            'submission' => $assignment->subid,
          ];
          $DB->insert_record('assignfeedback_aif_feedback', $data);
        }

    }
}
