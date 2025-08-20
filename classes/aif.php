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

namespace assignfeedback_aif;

/**
 * Class aif
 *
 * @package    assignfeedback_aif
 * @copyright  2025 2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use \stdClass;
class aif {
    public  int $contextid;

    public function __construct($contextid) {
        $this->contextid = $contextid;
    }

    public function perform_request(string $prompt, string $purpose = 'feedback'): string {
        global $USER;
        $manager = \core\di::get(\core_ai\manager::class);
        $action = new \core_ai\aiactions\generate_text(
            contextid: $this->contextid,
            userid: $USER->id,
            prompttext: $prompt
        );
        $llmresponse = $manager->process_action($action);
        $responsedata = $llmresponse->get_response_data();
        return $responsedata['generatedcontent'];
    }

    public function get_prompt(stdClass $assignment, string $gradingmethod): string {
        global $DB;
        // If feedback exists then skip.
        $count = $DB->count_records('assignfeedback_aif_feedback',
        ['aif'=>$assignment->aifid, 'submission' => $assignment->subid]);
        if ($count > 0) {
            mtrace("Skipping as feedback exists");
            return '';
        }
        if ($gradingmethod == 'rubric') {
            $rsql = "SELECT * FROM {grading_areas} ga
                JOIN {grading_definitions} gd ON gd.areaid = ga.id
                JOIN {gradingform_rubric_criteria} rc ON rc.definitionid = gd.id
                WHERE ga.contextid = :contextid AND ga.activemethod LIKE :gradingmethod AND ga.areaname = :areaname";
            $params = ['contextid' => $assignment->contextid,
            'gradingmethod' => $gradingmethod,
            'areaname' => 'submissions'];
            $records = $DB->get_records_sql($rsql, $params);
            if (empty($records)) {
                return '';
            }
            mtrace("Assignment {$assignment->aid} submission {$assignment->subid}");
            $prompt = $assignment->prompt . ': ';
            foreach ($records as $record) {
                $levels = $DB->get_records('gradingform_rubric_levels', ['criterionid' => $record->id], 'score ASC');
                $definitions = array_map(function($level) { return $level->definition; }, $levels);
                $definition = '- ' . implode(' - ', $definitions);
                $prompt .= " " . $record->description . " " . $definition;
            }
            $prompt .= " ".strip_tags($assignment->onlinetext);
            return $prompt;
        } else {
            $id = optional_param('id', 0, PARAM_INT);
            $prompt = $DB->get_record('assignfeedback_aif', ['assignment' => $id]);
            return $prompt;
        }
    }
}
