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
 * Class process_feedback_rubric
 *
 * @package    assignfeedback_aif
 * @copyright  2025 Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_feedback_rubric_adhoc  extends \core\task\adhoc_task {
    /**
     * Execute task.
     */
    public function execute() {
        global $DB;
        $aif = new \assignfeedback_aif\aif(\context_system::instance()->id);
        $data = $this->get_custom_data();
        $assignment = $data->assignment;
        $users = $data->users;
        $action = $data->action;
        foreach ($users as $userid) {
            $sql = "SELECT sub.id AS subid, cx.id AS contextid, aif.id AS aifid, aif.prompt AS prompt, a.id AS aid, olt.onlinetext AS onlinetext
            FROM {assign} a
            JOIN {course_modules} cm
            ON cm.instance = a.id and cm.course = a.course
            JOIN {context} cx
            ON cx.instanceid = cm.id
            JOIN {assignfeedback_aif} aif
            ON aif.assignment = cm.id
            JOIN {assign_submission} sub
            ON sub.assignment = a.id
            JOIN {assignsubmission_onlinetext} olt
            ON olt.assignment = a.id AND olt.submission = sub.id
            WHERE sub.status='submitted' AND contextlevel = 70 AND a.id = :aid AND sub.userid = :userid AND sub.latest = 1";
            $record = $DB->get_record_sql($sql, ['aid' => $assignment, 'userid' => $userid]);
            if ($action == 'generate') {
                if (empty($record)) {
                    continue;
                }
                $prompt =  $aif->get_prompt($record, 'rubric');
                if (empty($prompt)) {
                    continue;
                }
                $aifeedback =  $aif->perform_request($prompt);
                $data = (object) [
                'aif' => $record->aifid,
                'feedback' => $aifeedback,
                'timecreated' => time(),
                'submission' => $record->subid,
                ];
                $DB->insert_record('assignfeedback_aif_feedback', $data);
                mtrace($prompt);
            }
            if ($action == 'delete') {
                if ($record->subid) {
                    $DB->delete_records('assignfeedback_aif_feedback',
                        ['aif'=>$record->aifid, 'submission'=>$record->subid]);
                    mtrace("AI feedback deleted for assignment {$assignment} submission {$record->subid} ");
                }
            }
        }
    }
}
