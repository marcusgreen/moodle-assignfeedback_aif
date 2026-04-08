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

namespace assignfeedback_aif\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function to get the expert mode prompt template.
 *
 * Returns the admin-configured prompt template for expert mode so that
 * the JS module can load it via AJAX instead of embedding it in a data attribute.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_expert_template extends external_api {
    /**
     * Describes the parameters for get_expert_template.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Get the expert mode prompt template.
     *
     * @return array The template text.
     */
    public static function execute(): array {
        $context = \core\context\system::instance();
        self::validate_context($context);

        $template = get_config('assignfeedback_aif', 'prompttemplate');
        if (empty($template)) {
            $template = get_string('defaultprompttemplate', 'assignfeedback_aif');
        }

        return ['template' => $template];
    }

    /**
     * Describes the return value for get_expert_template.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'template' => new external_value(PARAM_RAW, 'The expert mode prompt template text'),
        ]);
    }
}
