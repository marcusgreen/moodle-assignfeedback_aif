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

namespace assignfeedback_aif\event;

/**
 * Event observer
 *
 * @package    assignfeedback_aif
 * @copyright  2024 2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

        /**
         * Listen to events and queue the submission for processing.
         * @param \mod_assign\event\submission_created $event
         */
        public static function submission_created(\mod_assign\event\submission_created $event) {
            self::somefunc($event);
        }

        public static function somefunc($event) {
            global $USER;
            $grade = '3.14';
            $teachercommenttext = 'This is FABULOUS!.';
            $data = new \stdClass();
            $data->attemptnumber = 1;
            $data->grade = $grade;
            $data->assignfeedbackcomments_editor = ['text' => $teachercommenttext, 'format' => FORMAT_MOODLE];
            $assign = $event->get_assign();
            $aif = $assign->get_feedback_plugins()[0];
            //$prompt = $aif->get_prompt();

            //$assign->save_grade($USER->id, $data);
        }

        /**
         * Listen to events and queue the submission for processing.
         * @param \mod_assign\event\submission_removed $event
         */
        public static function submission_removed(\mod_assign\event\submission_removed $event) {
            global $DB;
            $sql = "SELECT aif.id AS aifid FROM {assign} a
                JOIN {course_modules} cm ON cm.instance = a.id and cm.course = a.course
                JOIN {context} cx ON cx.instanceid = cm.id
                JOIN {assignfeedback_aif} aif ON aif.assignment = cm.id
                WHERE a.id = :aid";
            $param = ['aid' => $event->get_assign()->get_instance()->id];
            $aif = $DB->get_field_sql($sql, $param);
            $DB->delete_records('assignfeedback_aif_feedback', [
                'submission' => $event->other['submissionid'],
                'aif' => $aif
            ]);
        }
}
