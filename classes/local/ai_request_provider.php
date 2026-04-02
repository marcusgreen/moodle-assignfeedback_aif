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

namespace assignfeedback_aif\local;

/**
 * Wrapper for AI requests to enable dependency injection and testability.
 *
 * In production, this delegates to the configured AI backend (local_ai_manager or core_ai).
 * In tests, this class can be replaced via \core\di::set() with a mock.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_request_provider {
    /**
     * Check if the AI backend is available for the given purpose.
     *
     * @param string $purpose The purpose to check (e.g., 'feedback', 'itt').
     * @param int $contextid The context ID.
     * @return bool True if the AI backend is available.
     */
    public function is_available(string $purpose, int $contextid): bool {
        return $this->get_unavailability_reason($purpose, $contextid) === null;
    }

    /**
     * Get the reason why the AI backend is unavailable.
     *
     * Returns a human-readable error message if the backend is not available,
     * or null if the backend is available and ready.
     *
     * @param string $purpose The purpose to check (e.g., 'feedback', 'itt').
     * @param int $contextid The context ID.
     * @return string|null Error message if unavailable, null if available.
     */
    public function get_unavailability_reason(string $purpose, int $contextid): ?string {
        $backend = get_config('assignfeedback_aif', 'backend') ?: 'core_ai_subsystem';

        if ($backend === 'local_ai_manager') {
            return $this->get_unavailability_reason_local_ai_manager($purpose, $contextid);
        }

        // Core AI subsystem: check if there is at least one enabled provider
        // configured for the generate_text action.
        if (!class_exists('\core_ai\manager')) {
            return get_string('ainavailable', 'assignfeedback_aif');
        }
        $manager = \core\di::get(\core_ai\manager::class);
        if (!$manager->is_action_available(\core_ai\aiactions\generate_text::class)) {
            return get_string('ainavailable', 'assignfeedback_aif');
        }

        return null;
    }

    /**
     * Get the reason why the local_ai_manager backend is unavailable.
     *
     * @param string $purpose The purpose to check.
     * @param int $contextid The context ID.
     * @return string|null Error message if unavailable, null if available.
     */
    private function get_unavailability_reason_local_ai_manager(string $purpose, int $contextid): ?string {
        if (!class_exists('\local_ai_manager\ai_manager_utils')) {
            return get_string('ainavailable', 'assignfeedback_aif');
        }

        global $USER;
        $aiconfig = \local_ai_manager\ai_manager_utils::get_ai_config($USER, $contextid, null, [$purpose]);

        if (
            empty($aiconfig['availability']) ||
            $aiconfig['availability']['available'] !== \local_ai_manager\ai_manager_utils::AVAILABILITY_AVAILABLE
        ) {
            return $aiconfig['availability']['errormessage'] ?? get_string('ainavailable', 'assignfeedback_aif');
        }

        // Check specific purpose availability.
        foreach ($aiconfig['purposes'] as $purposeconfig) {
            if ($purposeconfig['purpose'] === $purpose) {
                if ($purposeconfig['available'] === \local_ai_manager\ai_manager_utils::AVAILABILITY_AVAILABLE) {
                    return null;
                }
                return $purposeconfig['errormessage'] ?? get_string('ainavailable', 'assignfeedback_aif');
            }
        }

        return get_string('ainavailable', 'assignfeedback_aif');
    }

    /**
     * Perform an AI request using local_ai_manager.
     *
     * @param string $prompt The prompt text.
     * @param string $purpose The purpose identifier.
     * @param int $contextid The context ID.
     * @param array $options Additional options (e.g., 'image' for base64 data).
     * @return string The AI response text.
     * @throws \moodle_exception If the request fails or the backend is not available.
     */
    public function perform_request_local_ai_manager(
        string $prompt,
        string $purpose,
        int $contextid,
        array $options = []
    ): string {
        if (!class_exists('\local_ai_manager\manager')) {
            throw new \moodle_exception('err_retrievingfeedback_checkconfig', 'assignfeedback_aif');
        }

        $manager = new \local_ai_manager\manager($purpose);
        $llmresponse = $manager->perform_request($prompt, 'assignfeedback_aif', $contextid, $options);

        if ($llmresponse->get_code() !== 200) {
            throw new \moodle_exception(
                'err_retrievingfeedback',
                'assignfeedback_aif',
                '',
                $llmresponse->get_errormessage(),
                $llmresponse->get_debuginfo()
            );
        }

        return $llmresponse->get_content();
    }

    /**
     * Perform an AI request using the Moodle Core AI subsystem.
     *
     * @param string $prompt The prompt text.
     * @param int $contextid The context ID.
     * @param int $userid The user ID.
     * @return string The AI response text.
     * @throws \moodle_exception If the request fails.
     */
    public function perform_request_core_ai(string $prompt, int $contextid, int $userid): string {
        $manager = \core\di::get(\core_ai\manager::class);
        $action = new \core_ai\aiactions\generate_text(
            contextid: $contextid,
            userid: $userid,
            prompttext: $prompt
        );

        $llmresponse = $manager->process_action($action);
        $responsedata = $llmresponse->get_response_data();

        if (
            is_null($responsedata) || !is_array($responsedata) ||
            !array_key_exists('generatedcontent', $responsedata) ||
            is_null($responsedata['generatedcontent'])
        ) {
            throw new \moodle_exception('err_retrievingfeedback_checkconfig', 'assignfeedback_aif');
        }

        return $responsedata['generatedcontent'];
    }
}
