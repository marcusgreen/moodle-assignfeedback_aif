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
 * Settings for aif assign feedback plugin
 *
 * @package    assignfeedback_aif
 * @copyright  2024 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** @var admin_settingpage $settings */
$settings->add(new admin_setting_configcheckbox('assignfeedback_aif/default',
                   new lang_string('enabledbydefault', 'assignfeedback_aif'),
                   new lang_string('default_help', 'assignfeedback_aif'), 0));

$settings->add(new admin_setting_configtextarea('assignfeedback_aif/prompt',
                    get_string('prompt', 'assignfeedback_aif'),
                    get_string('prompt_text', 'assignfeedback_aif'),
                    get_string('prompt_setting', 'assignfeedback_aif'),
                    PARAM_RAW, 20, 3));

// AI Backend selection.
$backends = [
    'local_ai_manager' => get_string('localaimanager', 'assignfeedback_aif'),
    'core_ai_subsystem' => get_string('coreaisubsystem', 'assignfeedback_aif'),
];
$settings->add(new admin_setting_configselect(
    'assignfeedback_aif/backend',
    get_string('backends', 'assignfeedback_aif'),
    get_string('backends_text', 'assignfeedback_aif'),
    'core_ai_subsystem',
    $backends
));

// Purpose configuration for local_ai_manager.
$settings->add(new admin_setting_configtext(
    'assignfeedback_aif/purpose',
    get_string('purpose', 'assignfeedback_aif'),
    get_string('purpose_text', 'assignfeedback_aif'),
    'feedback',
    PARAM_ALPHANUMEXT
));

// Prompt template.
$settings->add(new admin_setting_configtextarea(
    'assignfeedback_aif/prompttemplate',
    get_string('prompttemplate', 'assignfeedback_aif'),
    get_string('prompttemplate_text', 'assignfeedback_aif'),
    get_string('defaultprompttemplate', 'assignfeedback_aif'),
    PARAM_RAW,
    80,
    15
));

// Disclaimer.
$settings->add(new admin_setting_configtext(
    'assignfeedback_aif/disclaimer',
    get_string('disclaimer', 'assignfeedback_aif'),
    get_string('disclaimer_text', 'assignfeedback_aif'),
    get_string('defaultdisclaimer', 'assignfeedback_aif'),
    PARAM_RAW
));

// Translate disclaimer to user language.
$settings->add(new admin_setting_configcheckbox(
    'assignfeedback_aif/translatedisclaimer',
    get_string('translatedisclaimer', 'assignfeedback_aif'),
    get_string('translatedisclaimer_text', 'assignfeedback_aif'),
    1
));

