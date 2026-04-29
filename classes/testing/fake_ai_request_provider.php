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

namespace assignfeedback_aif\testing;

use assignfeedback_aif\local\ai_request_provider;

/**
 * Fake AI request provider for behat testing.
 *
 * Replaces the real ai_request_provider via DI when BEHAT_SITE_RUNNING is
 * defined and behat_mock_enabled config is set. Returns configurable mock
 * responses stored in plugin config, so it works across all PHP processes
 * (web requests, CLI cron, adhoc tasks).
 *
 * Mock behaviour is controlled via set_config():
 * - behat_mock_enabled: '1' to activate (set via DI hook check).
 * - behat_mock_response: The text to return from AI requests.
 * - behat_mock_error: If set, throw a moodle_exception with this message.
 * - behat_mock_unavailable: If set, return this as unavailability reason.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_ai_request_provider extends ai_request_provider {
    /**
     * Default mock response when none is configured.
     */
    private const DEFAULT_RESPONSE = '## AI Feedback (Mock)' . "\n\n"
        . 'This is a mock AI feedback response for testing purposes.' . "\n\n"
        . '**Strengths:**' . "\n"
        . '- Good structure and organisation' . "\n"
        . '- Clear argumentation' . "\n\n"
        . '**Areas for improvement:**' . "\n"
        . '- Consider adding more examples' . "\n"
        . '- Expand the conclusion';

    /**
     * Check if the AI backend is available for the given purpose.
     *
     * @param string $purpose The purpose to check.
     * @param int $contextid The context ID.
     * @return bool True if available.
     */
    public function is_available(string $purpose, int $contextid): bool {
        return $this->get_unavailability_reason($purpose, $contextid) === null;
    }

    /**
     * Get the reason why the AI backend is unavailable.
     *
     * Returns the configured unavailability message, or null if available.
     *
     * @param string $purpose The purpose to check.
     * @param int $contextid The context ID.
     * @return string|null Error message if unavailable, null if available.
     */
    public function get_unavailability_reason(string $purpose, int $contextid): ?string {
        $unavailable = get_config('assignfeedback_aif', 'behat_mock_unavailable');
        if (!empty($unavailable)) {
            return $unavailable;
        }
        return null;
    }

    /**
     * Perform a mock AI request using local_ai_manager backend.
     *
     * Returns the configured mock response or throws the configured error.
     *
     * @param string $prompt The prompt text.
     * @param string $purpose The purpose identifier.
     * @param int $contextid The context ID.
     * @param array $options Additional options.
     * @return string The mock AI response text.
     * @throws \moodle_exception If behat_mock_error is configured.
     */
    public function perform_request_local_ai_manager(
        string $prompt,
        string $purpose,
        int $contextid,
        array $options = []
    ): string {
        return $this->get_mock_response($prompt);
    }

    /**
     * Perform a mock AI request using Core AI subsystem.
     *
     * Returns the configured mock response or throws the configured error.
     *
     * @param string $prompt The prompt text.
     * @param int $contextid The context ID.
     * @param int $userid The user ID.
     * @return string The mock AI response text.
     * @throws \moodle_exception If behat_mock_error is configured.
     */
    public function perform_request_core_ai(string $prompt, int $contextid, int $userid): string {
        return $this->get_mock_response($prompt);
    }

    /**
     * Get the mock response, checking for configured error first.
     *
     * @param string $prompt The prompt that was sent (logged for debugging).
     * @return string The mock response text.
     * @throws \moodle_exception If an error response is configured.
     */
    private function get_mock_response(string $prompt): string {
        // Check if an error should be simulated.
        $error = get_config('assignfeedback_aif', 'behat_mock_error');
        if (!empty($error)) {
            throw new \moodle_exception('err_retrievingfeedback', 'assignfeedback_aif', '', $error);
        }

        // Return the configured mock response or the default.
        $response = get_config('assignfeedback_aif', 'behat_mock_response');
        if ($response !== false && $response !== '') {
            return $response;
        }

        return self::DEFAULT_RESPONSE;
    }
}
