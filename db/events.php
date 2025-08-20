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

/**
 * Event observers for AI Assisted Feedback
 *
 * @package    assignfeedback_aif
 * @category   event
 * @copyright  2024 2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
        [
            'eventname'   => '\mod_assign\event\submission_created',
            'callback'    => '\assignfeedback_aif\event\observer::submission_created',
        ],
        [
            'eventname'   => '\mod_assign\event\submission_removed',
            'callback'    => '\assignfeedback_aif\event\observer::submission_removed',
        ]
    ];
