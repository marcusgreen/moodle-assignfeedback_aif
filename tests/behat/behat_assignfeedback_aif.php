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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\PyStringNode;

/**
 * Behat step definitions for assignfeedback_aif.
 *
 * Provides steps to configure the fake AI request provider for integration
 * testing. The fake provider is automatically injected via DI hook when
 * BEHAT_SITE_RUNNING is defined (see hook_callbacks::configure_di).
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_assignfeedback_aif extends behat_base {
    /**
     * Configure the fake AI provider to return a specific response.
     *
     * @Given /^the AI feedback mock returns "(?P<response>[^"]*)"$/
     *
     * @param string $response The mock response text.
     */
    public function the_ai_feedback_mock_returns(string $response): void {
        set_config('behat_mock_response', $response, 'assignfeedback_aif');
        unset_config('behat_mock_error', 'assignfeedback_aif');
        unset_config('behat_mock_unavailable', 'assignfeedback_aif');
    }

    /**
     * Configure the fake AI provider to return a multiline response.
     *
     * @Given /^the AI feedback mock returns:$/
     *
     * @param PyStringNode $response The multiline mock response text.
     */
    public function the_ai_feedback_mock_returns_multiline(PyStringNode $response): void {
        set_config('behat_mock_response', $response->getRaw(), 'assignfeedback_aif');
        unset_config('behat_mock_error', 'assignfeedback_aif');
        unset_config('behat_mock_unavailable', 'assignfeedback_aif');
    }

    /**
     * Configure the fake AI provider to throw an error.
     *
     * @Given /^the AI feedback mock returns an error "(?P<error>[^"]*)"$/
     *
     * @param string $error The error message.
     */
    public function the_ai_feedback_mock_returns_an_error(string $error): void {
        set_config('behat_mock_error', $error, 'assignfeedback_aif');
        unset_config('behat_mock_response', 'assignfeedback_aif');
        unset_config('behat_mock_unavailable', 'assignfeedback_aif');
    }

    /**
     * Configure the fake AI provider to report as unavailable.
     *
     * @Given /^the AI feedback mock is unavailable with "(?P<reason>[^"]*)"$/
     *
     * @param string $reason The unavailability reason.
     */
    public function the_ai_feedback_mock_is_unavailable(string $reason): void {
        set_config('behat_mock_unavailable', $reason, 'assignfeedback_aif');
        unset_config('behat_mock_error', 'assignfeedback_aif');
        unset_config('behat_mock_response', 'assignfeedback_aif');
    }

    /**
     * Reset the fake AI provider to default behaviour.
     *
     * @Given /^the AI feedback mock is reset$/
     */
    public function the_ai_feedback_mock_is_reset(): void {
        unset_config('behat_mock_response', 'assignfeedback_aif');
        unset_config('behat_mock_error', 'assignfeedback_aif');
        unset_config('behat_mock_unavailable', 'assignfeedback_aif');
    }

    /**
     * Assert that AI feedback exists for a student in an assignment.
     *
     * @Then /^AI feedback should exist for "(?P<username>[^"]*)" in "(?P<assignment>[^"]*)"$/
     *
     * @param string $username The student's username.
     * @param string $assignmentname The assignment name.
     */
    public function ai_feedback_should_exist_for_in(string $username, string $assignmentname): void {
        global $DB;
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $assign = $DB->get_record('assign', ['name' => $assignmentname], '*', MUST_EXIST);
        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assign->id], '*', MUST_EXIST);
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $user->id,
            'latest' => 1,
        ], '*', MUST_EXIST);

        $feedback = $DB->get_record('assignfeedback_aif_feedback', [
            'aif' => $aif->id,
            'submission' => $submission->id,
        ]);

        if (!$feedback || empty($feedback->feedback)) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "No AI feedback found for user '{$username}' in assignment '{$assignmentname}'",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that AI feedback does NOT exist for a student in an assignment.
     *
     * @Then /^AI feedback should not exist for "(?P<username>[^"]*)" in "(?P<assignment>[^"]*)"$/
     *
     * @param string $username The student's username.
     * @param string $assignmentname The assignment name.
     */
    public function ai_feedback_should_not_exist_for_in(string $username, string $assignmentname): void {
        global $DB;
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $assign = $DB->get_record('assign', ['name' => $assignmentname], '*', MUST_EXIST);
        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assign->id]);

        if (!$aif) {
            // No AIF config means no feedback possible.
            return;
        }

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $user->id,
            'latest' => 1,
        ]);

        if (!$submission) {
            return;
        }

        $feedback = $DB->get_record('assignfeedback_aif_feedback', [
            'aif' => $aif->id,
            'submission' => $submission->id,
        ]);

        if ($feedback && !empty($feedback->feedback)) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "AI feedback unexpectedly found for user '{$username}' in assignment '{$assignmentname}'",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that AI feedback contains an error for a student in an assignment.
     *
     * @Then /^AI feedback should have an error for "(?P<username>[^"]*)" in "(?P<assignment>[^"]*)"$/
     *
     * @param string $username The student's username.
     * @param string $assignmentname The assignment name.
     */
    public function ai_feedback_should_have_error_for_in(string $username, string $assignmentname): void {
        global $DB;
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $assign = $DB->get_record('assign', ['name' => $assignmentname], '*', MUST_EXIST);
        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assign->id], '*', MUST_EXIST);
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $user->id,
            'latest' => 1,
        ], '*', MUST_EXIST);

        $feedback = $DB->get_record('assignfeedback_aif_feedback', [
            'aif' => $aif->id,
            'submission' => $submission->id,
        ]);

        if (!$feedback) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "No AI feedback record found for user '{$username}' in assignment '{$assignmentname}'",
                $this->getSession()
            );
        }

        $skipped = json_decode($feedback->skippedfiles ?? '', true);
        $haserror = false;
        if (is_array($skipped)) {
            foreach ($skipped as $entry) {
                if (is_array($entry) && isset($entry['_error'])) {
                    $haserror = true;
                    break;
                }
            }
        }

        if (!$haserror) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "AI feedback for user '{$username}' in assignment '{$assignmentname}' has no error marker",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that AI feedback does NOT contain an error for a student.
     *
     * @Then /^AI feedback should not have an error for "(?P<username>[^"]*)" in "(?P<assignment>[^"]*)"$/
     *
     * @param string $username The student's username.
     * @param string $assignmentname The assignment name.
     */
    public function ai_feedback_should_not_have_error_for_in(string $username, string $assignmentname): void {
        global $DB;
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $assign = $DB->get_record('assign', ['name' => $assignmentname], '*', MUST_EXIST);
        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assign->id], '*', MUST_EXIST);
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $user->id,
            'latest' => 1,
        ], '*', MUST_EXIST);

        $feedback = $DB->get_record('assignfeedback_aif_feedback', [
            'aif' => $aif->id,
            'submission' => $submission->id,
        ]);

        if (!$feedback) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "No AI feedback record found for user '{$username}' in assignment '{$assignmentname}'",
                $this->getSession()
            );
        }

        $skipped = json_decode($feedback->skippedfiles ?? '', true);
        if (is_array($skipped)) {
            foreach ($skipped as $entry) {
                if (is_array($entry) && isset($entry['_error'])) {
                    throw new \Behat\Mink\Exception\ExpectationException(
                        "AI feedback for user '{$username}' in assignment '{$assignmentname}' "
                        . "unexpectedly has error: " . $entry['_error'],
                        $this->getSession()
                    );
                }
            }
        }
    }

    /**
     * Assert the number of AI feedback records for a student in an assignment.
     *
     * @Then /^there should be (\d+) AI feedback records? for "(?P<username>[^"]*)" in "(?P<assignment>[^"]*)"$/
     *
     * @param int $count Expected count.
     * @param string $username The student's username.
     * @param string $assignmentname The assignment name.
     */
    public function there_should_be_n_feedback_records(int $count, string $username, string $assignmentname): void {
        global $DB;
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $assign = $DB->get_record('assign', ['name' => $assignmentname], '*', MUST_EXIST);
        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assign->id]);

        if (!$aif) {
            $actual = 0;
        } else {
            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assign->id,
                'userid' => $user->id,
                'latest' => 1,
            ]);
            if (!$submission) {
                $actual = 0;
            } else {
                $actual = $DB->count_records('assignfeedback_aif_feedback', [
                    'aif' => $aif->id,
                    'submission' => $submission->id,
                ]);
            }
        }

        if ((int) $actual !== (int) $count) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "Expected {$count} AI feedback record(s) for '{$username}' in '{$assignmentname}', found {$actual}",
                $this->getSession()
            );
        }
    }
}
