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
            xdebug_break();
            global $USER;

        }
}
