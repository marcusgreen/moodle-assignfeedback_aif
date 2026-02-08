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

namespace assignfeedback_aif;

defined('MOODLE_INTERNAL') || die();

use cache;
use stdClass;

/**
 * Class aif - Main AI Feedback handler.
 *
 * @package    assignfeedback_aif
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aif {
    /** @var int The context ID for AI requests. */
    public int $contextid;

    /**
     * Constructor.
     *
     * @param int $contextid The context ID.
     */
    public function __construct(int $contextid) {
        $this->contextid = $contextid;
    }

    /**
     * Perform AI request using the configured backend.
     *
     * @param string $prompt The prompt to send to the AI.
     * @param string|null $purpose The purpose of the request (for local_ai_manager). If null, uses config.
     * @param array $options Additional options (e.g., 'image' for base64 encoded image).
     * @return string The AI response.
     * @throws \moodle_exception
     */
    public function perform_request(string $prompt, ?string $purpose = null, array $options = []): string {
        global $USER;

        // Skip in test environments.
        if (defined('BEHAT_SITE_RUNNING') || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return "AI Feedback";
        }

        // Get purpose from config if not provided.
        if ($purpose === null) {
            $purpose = get_config('assignfeedback_aif', 'purpose') ?: 'feedback';
        }

        $backend = get_config('assignfeedback_aif', 'backend') ?: 'core_ai_subsystem';

        if ($backend === 'local_ai_manager') {
            return $this->perform_request_local_ai_manager($prompt, $purpose, $options);
        } else {
            return $this->perform_request_core_ai($prompt);
        }
    }

    /**
     * Perform request using local_ai_manager.
     *
     * @param string $prompt The prompt text.
     * @param string $purpose The purpose identifier.
     * @param array $options Additional options (e.g., 'image').
     * @return string The AI response.
     * @throws \moodle_exception
     */
    private function perform_request_local_ai_manager(string $prompt, string $purpose, array $options = []): string {
        if (!class_exists('\local_ai_manager\manager')) {
            throw new \moodle_exception('err_retrievingfeedback_checkconfig', 'assignfeedback_aif');
        }

        $manager = new \local_ai_manager\manager($purpose);
        $llmresponse = $manager->perform_request($prompt, 'assignfeedback_aif', $this->contextid, $options);

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
     * Perform request using Moodle Core AI subsystem (4.5+).
     *
     * @param string $prompt The prompt text.
     * @return string The AI response.
     * @throws \moodle_exception
     */
    private function perform_request_core_ai(string $prompt): string {
        global $USER;

        $manager = \core\di::get(\core_ai\manager::class);
        $action = new \core_ai\aiactions\generate_text(
            contextid: $this->contextid,
            userid: $USER->id,
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

    /**
     * Build the full prompt using the template system.
     *
     * @param string $submission The student submission text.
     * @param string $rubric The rubric criteria text.
     * @param string $prompt The teacher's prompt/instructions.
     * @param string $assignmentname The assignment name.
     * @return string The complete prompt.
     */
    public function build_prompt_from_template(
        string $submission,
        string $rubric,
        string $prompt,
        string $assignmentname
    ): string {
        $language = $this->get_current_language_name();

        // Expert mode detection: if the teacher's prompt contains {{submission}},
        // it replaces the admin template entirely.
        $isexpertmode = strpos($prompt, '{{submission}}') !== false;

        if ($isexpertmode) {
            // In expert mode, the teacher's prompt IS the complete template.
            $replacements = [
                '{{submission}}' => strip_tags($submission),
                '{{rubric}}' => strip_tags($rubric),
                '{{assignmentname}}' => strip_tags($assignmentname),
                '{{language}}' => $language,
            ];
            return str_replace(array_keys($replacements), array_values($replacements), $prompt);
        }

        // Standard mode: inject the teacher's prompt into the admin template.
        $template = get_config('assignfeedback_aif', 'prompttemplate');
        if (empty($template)) {
            $template = get_string('defaultprompttemplate', 'assignfeedback_aif');
        }

        $replacements = [
            '{{submission}}' => strip_tags($submission),
            '{{rubric}}' => strip_tags($rubric),
            '{{prompt}}' => strip_tags($prompt),
            '{{assignmentname}}' => strip_tags($assignmentname),
            '{{language}}' => $language,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Append the disclaimer to feedback text.
     *
     * In practice mode (autogenerate without marking workflow), a different
     * disclaimer is used to indicate the feedback was not reviewed by a teacher.
     *
     * @param string $feedback The AI-generated feedback.
     * @param bool $ispractice Whether this is practice mode (no teacher review).
     * @return string The feedback with disclaimer appended.
     */
    public function append_disclaimer(string $feedback, bool $ispractice = false): string {
        if ($ispractice) {
            $disclaimer = get_config('assignfeedback_aif', 'practicedisclaimer');
            if (empty($disclaimer)) {
                $disclaimer = get_string('defaultpracticedisclaimer', 'assignfeedback_aif');
            }
        } else {
            $disclaimer = get_config('assignfeedback_aif', 'disclaimer');
            if (empty($disclaimer)) {
                $disclaimer = get_string('defaultdisclaimer', 'assignfeedback_aif');
            }
        }

        $translatedisclaimer = get_config('assignfeedback_aif', 'translatedisclaimer');
        if ($translatedisclaimer && current_language() !== 'en') {
            $disclaimer = $this->translate_text($disclaimer);
        }

        return $feedback . "\n\n" . $disclaimer;
    }

    /**
     * Translate text to the current user's language using AI.
     *
     * @param string $text The text to translate.
     * @return string The translated text.
     */
    private function translate_text(string $text): string {
        $language = current_language();

        // Check cache first.
        $cache = cache::make('assignfeedback_aif', 'translations');
        $cachekey = $language . '_' . md5($text);

        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        try {
            $languagename = $this->get_current_language_name();
            $translationprompt = 'Translate the following text to ' . $languagename . '. ' .
                'Return only the translated text, nothing else: "' . $text . '"';

            $translation = $this->perform_request($translationprompt, 'translate');
            $translation = trim($translation, '"\'');

            // Cache the translation.
            $cache->set($cachekey, $translation);

            return $translation;
        } catch (\Exception $e) {
            debugging('Translation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $text;
        }
    }

    /**
     * Get the human-readable name of the current language.
     *
     * Uses Moodle's string manager to resolve the language name.
     *
     * @return string The language name (e.g., "German", "English").
     */
    private function get_current_language_name(): string {
        $langcode = current_language();
        $stringmanager = get_string_manager();
        $languages = $stringmanager->get_list_of_languages();

        if (isset($languages[$langcode])) {
            return $languages[$langcode];
        }

        // Try prefix match (e.g., 'de_du' -> 'de').
        $prefix = substr($langcode, 0, 2);
        if (isset($languages[$prefix])) {
            return $languages[$prefix];
        }

        return 'English';
    }

    /**
     * Get prompt for a given assignment submission.
     *
     * @param stdClass $assignment The assignment data object.
     * @param string $gradingmethod The grading method (e.g., 'rubric').
     * @return array Array with 'prompt' string and 'options' array (may contain 'image' key).
     */
    public function get_prompt(stdClass $assignment, string $gradingmethod): array {
        global $DB;

        mtrace("Assignment {$assignment->aid} submission {$assignment->subid} user {$assignment->userid}");

        $rubrictext = '';
        $submissiontext = '';
        $teacherprompt = $assignment->prompt ?? '';
        $assignmentname = $DB->get_field('assign', 'name', ['id' => $assignment->aid]) ?: '';
        $options = [];

        if ($gradingmethod === 'rubric') {
            $rubrictext = $this->get_rubric_text($assignment);
        }

        // Get submission text from online text.
        if ($onlinetext = $DB->get_field('assignsubmission_onlinetext', 'onlinetext', ['submission' => $assignment->subid])) {
            mtrace("Content from text submission added to the prompt.");
            $submissiontext .= strip_tags($onlinetext);
        }

        // Get submission content from files (text or images).
        $fileresult = self::extract_content_from_files($assignment);
        if (!empty($fileresult['text'])) {
            $submissiontext .= ' ' . strip_tags($fileresult['text']);
        }
        if (!empty($fileresult['image'])) {
            $options['image'] = $fileresult['image'];
            mtrace("Image file encoded as base64 for AI analysis.");
        }

        // If no text but we have an image, use a default description prompt.
        if (empty($submissiontext) && empty($options['image'])) {
            mtrace("No submission text or image found");
            return ['prompt' => '', 'options' => []];
        }

        // If we only have an image, add a placeholder for submission text.
        if (empty($submissiontext) && !empty($options['image'])) {
            $submissiontext = '[Image submission - see attached image]';
        }

        // Use the template system to build the full prompt.
        $prompt = $this->build_prompt_from_template(
            $submissiontext,
            $rubrictext,
            $teacherprompt,
            $assignmentname
        );

        return ['prompt' => $prompt, 'options' => $options];
    }

    /**
     * Extract rubric criteria as text.
     *
     * @param stdClass $assignment The assignment data object.
     * @return string The rubric criteria text.
     */
    private function get_rubric_text(stdClass $assignment): string {
        global $DB;

        $rsql = "SELECT rc.id, rc.description FROM {grading_areas} ga
            JOIN {grading_definitions} gd ON gd.areaid = ga.id
            JOIN {gradingform_rubric_criteria} rc ON rc.definitionid = gd.id
            WHERE ga.contextid = :contextid
            AND ga.activemethod = :gradingmethod
            AND ga.areaname = :areaname";

        $params = [
            'contextid' => $assignment->contextid,
            'gradingmethod' => 'rubric',
            'areaname' => 'submissions',
        ];

        $records = $DB->get_records_sql($rsql, $params);
        if (empty($records)) {
            return '';
        }

        $rubrictext = '';
        foreach ($records as $record) {
            $levels = $DB->get_records('gradingform_rubric_levels', ['criterionid' => $record->id], 'score ASC');
            $definitions = array_map(function ($level) {
                return $level->definition;
            }, $levels);
            $definition = implode(' | ', $definitions);
            $rubrictext .= "- " . $record->description . ": " . $definition . "\n";
        }

        return $rubrictext;
    }

    /**
     * Image MIME types that can be sent to AI for analysis.
     */
    private const IMAGE_MIMETYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * Extract content from submitted files (text and images).
     *
     * @param stdClass $assignment The assignment data object.
     * @return array Array with 'text' (string) and 'image' (base64 data URL or null).
     */
    protected static function extract_content_from_files(stdClass $assignment): array {
        global $CFG;

        $result = ['text' => '', 'image' => null];
        $fs = get_file_storage();
        $converter = new \core_files\converter();
        $contextid = $assignment->contextid;
        $component = 'assignsubmission_file';
        $filearea = 'submission_files';
        $itemid = $assignment->subid;
        $format = 'txt';

        $files = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'itemid, filepath, filename', false);
        if (!$files) {
            return $result;
        }

        foreach ($files as $file) {
            if (!$file instanceof \stored_file) {
                continue;
            }

            $mimetype = $file->get_mimetype();

            // Check if it's an image file - encode as base64.
            if (in_array($mimetype, self::IMAGE_MIMETYPES)) {
                // Only use the first image found (AI APIs typically only support one image per request).
                if ($result['image'] === null) {
                    $content = $file->get_content();
                    $base64 = base64_encode($content);
                    $result['image'] = 'data:' . $mimetype . ';base64,' . $base64;
                    mtrace("Image file '{$file->get_filename()}' encoded as base64 for AI analysis.");
                } else {
                    mtrace("Skipping additional image '{$file->get_filename()}' - only first image is used.");
                }
                continue;
            }

            // Try to extract text from non-image files.
            $loadfile = $file;

            if ($mimetype !== 'text/plain' && $mimetype !== '') {
                if (!$converter->can_convert_storedfile_to($file, $format)) {
                    mtrace("Site document converter does not support conversion for: {$mimetype}");
                    continue;
                }

                $conversion = $converter->start_conversion($file, $format);
                mtrace("Start process to convert file to TXT");

                if ($conversion->get('status') === \core_files\conversion::STATUS_COMPLETE) {
                    $convertedfile = $conversion->get_destfile();
                    if (!$convertedfile) {
                        continue;
                    }
                    $loadfile = $convertedfile;
                } else {
                    continue;
                }
            }

            $tempfile = $loadfile->copy_content_to_temp();
            $result['text'] .= file_get_contents($tempfile) . "\n";
            unlink($tempfile);
            mtrace("Content from file '{$file->get_filename()}' added to the prompt.");
        }

        return $result;
    }
}
