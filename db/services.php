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
 * External functions and service definitions for assignfeedback_aif.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'assignfeedback_aif_regenerate_feedback' => [
        'classname' => 'assignfeedback_aif\external\regenerate_feedback',
        'description' => 'Regenerate AI feedback for a single submission',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/assign:grade',
    ],
    'assignfeedback_aif_retry_feedback' => [
        'classname' => 'assignfeedback_aif\external\retry_feedback',
        'description' => 'Retry a failed AI feedback generation for a submission',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],
    'assignfeedback_aif_check_feedback_status' => [
        'classname' => 'assignfeedback_aif\external\check_feedback_status',
        'description' => 'Check whether AI feedback exists for a submission',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],
    'assignfeedback_aif_get_submission_analysis' => [
        'classname' => 'assignfeedback_aif\external\get_submission_analysis',
        'description' => 'Analyse submission files for convertibility before AI feedback generation',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/assign:grade',
    ],
    'assignfeedback_aif_get_expert_template' => [
        'classname' => 'assignfeedback_aif\external\get_expert_template',
        'description' => 'Get the expert mode prompt template for AI feedback',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],
];
