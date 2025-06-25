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
class process_feedback_rubric  extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskprocessfeedbackrubric', 'assignfeedback_aif');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB;
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
        WHERE sub.status='submitted' AND contextlevel = 70";
        $assignments = $DB->get_records_sql($sql);
        $aif = new \assignfeedback_aif\aif(\context_system::instance()->id);
        foreach ($assignments as $assignment) {
            // If feedback exists then skip.
            $count = $DB->count_records('assignfeedback_aif_feedback',
            ['aif'=>$assignment->aifid, 'submission' => $assignment->subid]);
            if ($count > 0) {
                continue;
            }
            mtrace("Assignment {$assignment->aid} submission {$assignment->subid}");
            $rsql = "SELECT * FROM {grading_areas} ga
                JOIN {grading_definitions} gd ON gd.areaid = ga.id
                JOIN {gradingform_rubric_criteria} rc ON rc.definitionid = gd.id
                WHERE ga.contextid = :contextid AND ga.activemethod LIKE :gradingmethod AND ga.areaname = :areaname";
            $params = ['contextid' => $assignment->contextid,
            'gradingmethod' => 'rubric',
            'areaname' => 'submissions'];
            $records = $DB->get_records_sql($rsql, $params);
            // If it is not rubric then skip.
            if (empty($records)) {
                continue;
            }
            $prompt = $assignment->prompt . ': ';
            foreach ($records as $record) {
                $definition = $DB->get_field_sql("SELECT '- ' || string_agg(definition, ' - ')
                FROM {gradingform_rubric_levels} WHERE criterionid = :rcid",
                ['rcid' => $record->id]);
                $prompt .= " ". $record->description. " " . $definition;
            }
            xdebug_break();
            $prompt .= " ".strip_tags($assignment->onlinetext);
            $aifeedback =  $aif->perform_request($prompt);
            $data = (object) [
            'aif' => $assignment->aifid,
            'feedback' => $aifeedback,
            'timecreated' => time(),
            'submission' => $assignment->subid,
            ];

            $DB->insert_record('assignfeedback_aif_feedback', $data);
            mtrace($prompt);
        }
    }
}
