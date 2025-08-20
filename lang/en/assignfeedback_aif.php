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

/**
 * Plugin strings are defined here.
 *
 * @package     assignfeedback_aif *
 * @category    string
 * @copyright   2024 Marcus Green
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
$string['default_help'] = 'The plugin will be enabled by default when a new assignment is created';
$string['enabled'] = "Enabled";
$string['enabled_help'] = 'Enable the AI Feedback plugin';
$string['enabledbydefault'] = 'Enabled by default';
$string['file'] = 'Prompt file';
$string['file_help'] = 'Uploading prompts through a text file is a long term goal';
$string['pluginname'] = 'AI Assisted Feedback';
$string['prompt'] = 'Prompt';
$string['prompt_help'] = 'Prompt that will be sent to the remote LLM (E.G ChatGPT)';
$string['prompt_setting'] = 'Analyse the grammar in this text';
$string['prompt_text'] = 'The default prompt that will be added to a new instance';
$string['processfeedbactask'] = 'Process feedback task';
$string['processfeedbacktask'] = 'Taskprocessfeedback';
$string['taskprocessfeedbackrubric'] = 'Taskprocessfeedbackrubric';
$string['batchoperationconfirmgeneratefeedbackai'] = 'Generate AI feedback for one or all selected users?';
$string['batchoperationgeneratefeedbackai'] = 'Generate AI feedback';
$string['generatefeedbackai'] = 'Generate AI feedback';
$string['batchoperationconfirmdeletefeedbackai'] = 'Delete AI feedback for one or all selected users?';
$string['batchoperationdeletefeedbackai'] = 'Delete AI feedback';
$string['deletefeedbackai'] = 'Delete AI feedback';
$string['processfeedbackainotify'] = 'Please wait for the cron to run to process the AI Feedback';
$string['privacy:aipath'] = 'AI Feedback';
$string['privacy:metadata:assignmentid'] = 'Assignment ID';
$string['privacy:metadata:aitext'] = 'AI Feedback text.';
$string['privacy:metadata:tablesummary'] = 'This stores AI feedback made by the AI proivders as feedback for the student on their submission.';
