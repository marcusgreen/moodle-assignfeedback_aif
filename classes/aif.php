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
require_once($CFG->libdir . '/filelib.php');
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

    /**
     * Get prompt for a given assignment submission
     *
     * @param \stdClass $assignment
     * @param string $gradingmethod
     * @return string
     */
    public function get_prompt(\stdClass $assignment, string $gradingmethod): string {
        global $DB;
        $prompt = '';
        mtrace("Assignment {$assignment->aid} submission {$assignment->subid} user {$assignment->userid}");
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
            foreach ($records as $record) {
                $levels = $DB->get_records('gradingform_rubric_levels', ['criterionid' => $record->id], 'score ASC');
                $definitions = array_map(function($level) { return $level->definition; }, $levels);
                $definition = '- ' . implode(' - ', $definitions);
                $prompt .= " " . $record->description . " " . $definition;
            }

            // Get prompt from text submissions.
            if ($onlinetext = $DB->get_field('assignsubmission_onlinetext',
                'onlinetext', ['submission' => $assignment->subid] )) {
                mtrace("Content from text submission added to the prompt.");
                $prompt .= " ".strip_tags($onlinetext);
            }
            // Get prompt from files submissions.
            if ($filetext = self::extract_text_files($assignment)) {
                $prompt .= " ".strip_tags($filetext);
            }
            $prompt = format_text($prompt);
            return $prompt;
        } else {
            $id = optional_param('id', 0, PARAM_INT);
            $prompt = $DB->get_record('assignfeedback_aif', ['assignment' => $id]);
            return $prompt;
        }
    }


    /**
     * This function will extract text from PDF files.
     *
     * @param object $assignment
     * @return string
     */
    protected static function extract_text_files($assignment) {
        global $CFG;
        $filetext = '';
        if (isset($CFG->filedir)) {
            $filedir = $CFG->filedir;
        } else {
            $filedir = $CFG->dataroot.'/filedir';
        }
        $fs = get_file_storage();
        $converter = new \core_files\converter();
        $contextid = $assignment->contextid;
        $component = 'assignsubmission_file';
        $filearea = 'submission_files';
        $itemid = $assignment->subid;
        $format = 'txt';
        if ($files = $fs->get_area_files($contextid, $component,
            $filearea, $itemid, 'itemid, filepath, filename', false)) {
            foreach($files as $file) {
                if ($file instanceof \stored_file) {
                    $loadfile = $file;
                    $mimetype = $file->get_mimetype();
                    if ($mimetype === "text/plain" || $mimetype === '') {
                        $loadfile = $file;
                    } else {
                        if (!$converter->can_convert_storedfile_to($file, $format)) {
                            mtrace("Site document converter do not support file to text conversion.");
                            break;
                        }
                        $conversion = $converter->start_conversion($file, $format);
                        mtrace("Start process to convert files to TXT");
                        if ($conversion->get('status') === \core_files\conversion::STATUS_COMPLETE) {
                            if (!$convertedfile = $conversion->get_destfile()) {
                                break;
                            }
                            $loadfile = $convertedfile;
                        }
                    }
                    $myfile = $loadfile->copy_content_to_temp();
                    $filetext = file_get_contents($myfile);
                    unlink($myfile);
                    mtrace("Content from file submissions added to the prompt.");
                }
            }
        }
        return $filetext;
    }


}
