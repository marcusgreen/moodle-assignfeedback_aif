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

use assignfeedback_aif\local\ai_request_provider;
use core\exception\moodle_exception;
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
    protected int $contextid;

    /**
     * Constructor.
     *
     * The context ID is optional so the class can be resolved through the
     * dependency injection container (\core\di::get()), which requires a
     * parameterless construction. When resolved via DI, set the context ID
     * afterwards with {@see self::set_contextid()}.
     *
     * @param int $contextid The context ID.
     */
    public function __construct(int $contextid = 0) {
        $this->contextid = $contextid;
    }

    /**
     * Set the context ID used for AI requests.
     *
     * Needed when the instance is resolved through the DI container, which
     * cannot autowire the scalar context ID constructor argument.
     *
     * @param int $contextid The context ID.
     */
    public function set_contextid(int $contextid): void {
        $this->contextid = $contextid;
    }

    /**
     * Set the user ID for AI requests, switching the global $USER context if necessary.
     *
     * @param int|null $requestuserid The user ID to use for AI requests, null means use current $USER.
     */
    protected function setup_user(?int $requestuserid): void {
        global $USER;

        if (empty($requestuserid)) {
            \core\cron::setup_user();
            return;
        }

        // Only switch, when necessary.
        if (intval($USER->id) === $requestuserid) {
            return;
        }

        // Check if user exists.
        if (!$user = \core\user::get_user($requestuserid)) {
            return;
        }

        // If user is different and exists, switch to it.
        \core\cron::setup_user($user);
    }

    /**
     * Perform AI request using the configured backend.
     *
     * Uses the DI-injectable ai_request_provider. In tests, replace it
     * via \core\di::set(ai_request_provider::class, $mock).
     *
     * @param string $prompt The prompt to send to the AI.
     * @param int $userid The user the AI request is performed for.
     * @param string $purpose The purpose of the request (for local_ai_manager).
     * @param array $options Additional options (e.g., 'image' for ITT requests).
     * @return string The AI response.
     * @throws \moodle_exception If no valid user was given.
     */
    public function perform_request(string $prompt, int $userid, string $purpose = 'feedback', array $options = []): string {
        if ($userid <= 0) {
            throw new \moodle_exception('errornoactinguser', 'assignfeedback_aif');
        }

        $provider = \core\di::get(ai_request_provider::class);

        $backend = get_config('assignfeedback_aif', 'backend') ?: 'core_ai_subsystem';

        if ($backend === 'local_ai_manager') {
            return $provider->perform_request_local_ai_manager($prompt, $purpose, $this->contextid, $options);
        } else {
            return $provider->perform_request_core_ai($prompt, $this->contextid, $userid);
        }
    }

    /**
     * Build the full prompt using the template system.
     *
     * @param string $submission The student submission text.
     * @param string $rubric The rubric criteria text.
     * @param string $prompt The teacher's prompt/instructions.
     * @param string $assignmentname The assignment name.
     * @param string $description The assignment description (intro).
     * @param string $activityinstructions The activity instructions shown on the submission page.
     * @return string The complete prompt.
     */
    public function build_prompt_from_template(
        string $submission,
        string $rubric,
        string $prompt,
        string $assignmentname,
        string $description = '',
        string $activityinstructions = ''
    ): string {
        $language = $this->get_current_language_name();

        // Build conditional sections: only include headings when content exists.
        $descriptionsection = '';
        if (!empty(trim($description))) {
            $descriptionsection = "=== ASSIGNMENT DESCRIPTION ===\n" . $description;
        }
        $instructionssection = '';
        if (!empty(trim($activityinstructions))) {
            $instructionssection = "=== ACTIVITY INSTRUCTIONS ===\n" . $activityinstructions;
        }
        $rubricsection = '';
        if (!empty(trim($rubric))) {
            $rubricsection = "=== GRADING CRITERIA ===\n" . $rubric;
        }

        // Expert mode detection: if the teacher's prompt contains {{submission}},
        // it replaces the admin template entirely.
        $isexpertmode = str_contains($prompt, '{{submission}}');

        if ($isexpertmode) {
            // In expert mode, the teacher's prompt IS the complete template.
            $replacements = [
                '{{submission}}' => $submission,
                '{{rubric_section}}' => $rubricsection,
                '{{rubric}}' => $rubric,
                '{{assignmentname}}' => $assignmentname,
                '{{description}}' => $description,
                '{{description_section}}' => $descriptionsection,
                '{{activityinstructions}}' => $activityinstructions,
                '{{instructions_section}}' => $instructionssection,
                '{{language}}' => $language,
            ];
            return str_replace(array_keys($replacements), array_values($replacements), $prompt);
        }

        // Standard mode: inject the teacher's prompt into the admin template.
        // The template is expected to be configured via admin settings. The hardcoded
        // fallback matches the default defined in settings.php.
        $template = get_config('assignfeedback_aif', 'prompttemplate');
        if (empty($template)) {
            $template = 'You are an experienced teacher. Provide feedback on: {{submission}}';
        }

        $replacements = [
            '{{submission}}' => $submission,
            '{{rubric_section}}' => $rubricsection,
            '{{rubric}}' => $rubric,
            '{{prompt}}' => $prompt,
            '{{assignmentname}}' => $assignmentname,
            '{{description}}' => $description,
            '{{description_section}}' => $descriptionsection,
            '{{activityinstructions}}' => $activityinstructions,
            '{{instructions_section}}' => $instructionssection,
            '{{language}}' => $language,
        ];

        $result = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Remove template sections that have empty content (e.g. no description or instructions).
        // Matches section headings followed only by whitespace until the next heading or end.
        $result = preg_replace('/^=== [A-Z ]+===\n\s*(?=\n=== |$)/m', '', $result);

        // Clean up excessive blank lines left after removing empty sections.
        $result = preg_replace('/\n{3,}/', "\n\n", $result);

        return $result;
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

        return $feedback . "\n\n" . $disclaimer;
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
     * Determine which submission plugins relevant for AI feedback are enabled for an assignment.
     *
     * Switching submission types leaves orphaned data of the disabled plugins in the
     * database, which must not be taken into account for AI feedback.
     *
     * @param \assign $assign The assign instance.
     * @return array Associative array with keys 'onlinetext' and 'file', each mapping to a bool
     *  indicating whether the respective submission plugin is enabled.
     */
    public static function get_enabled_submission_plugins(\assign $assign): array {
        $onlinetextplugin = $assign->get_submission_plugin_by_type('onlinetext');
        $fileplugin = $assign->get_submission_plugin_by_type('file');
        return [
            'onlinetext' => $onlinetextplugin ? $onlinetextplugin->is_enabled() : false,
            'file' => $fileplugin ? $fileplugin->is_enabled() : false,
        ];
    }

    /**
     * Get prompt for a given assignment submission.
     *
     * Extracts text from all submitted content (online text, documents, images, PDFs)
     * and builds a combined prompt. Images and PDFs are converted to text via AI (ITT purpose)
     * before being included in the final prompt. When both online text and files are present,
     * the submission content is structured with section labels so the LLM can distinguish sources.
     *
     * @param stdClass $assignment The assignment data object.
     * @param string $gradingmethod The grading method (e.g., 'rubric').
     * @param int $actinguserid The id of the user that is being used to perform the AI request for extracting text
     *  from documents and images.
     * @return array Array with 'prompt' string, 'options' array, and 'skippedfiles' array.
     */
    public function get_prompt(stdClass $assignment, string $gradingmethod, int $actinguserid): array {
        global $DB;

        mtrace("Assignment {$assignment->aid} submission {$assignment->subid} user {$assignment->userid}");

        $rubrictext = '';
        $teacherprompt = $assignment->prompt ?? '';
        $assignrecord = $DB->get_record('assign', ['id' => $assignment->aid], 'name, intro, introformat, activity, activityformat');
        $assignmentname = $assignrecord ? $assignrecord->name : '';
        $description = '';
        $activityinstructions = '';
        if ($assignrecord) {
            if (!empty($assignrecord->intro)) {
                $description = html_to_text(format_text(
                    $assignrecord->intro,
                    $assignrecord->introformat,
                    ['filter' => false]
                ));
            }
            if (!empty($assignrecord->activity)) {
                $activityinstructions = html_to_text(format_text(
                    $assignrecord->activity,
                    $assignrecord->activityformat,
                    ['filter' => false]
                ));
            }
        }
        $options = [];

        if ($gradingmethod === 'rubric') {
            $rubrictext = $this->get_rubric_text($assignment);
        }

        // Determine which submission plugins are currently enabled for this assignment.
        // Orphaned data of disabled submission types must be ignored (MBS-10855).
        $cm = get_coursemodule_from_instance('assign', $assignment->aid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $context = \core\context\module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);
        $enabledplugins = self::get_enabled_submission_plugins($assign);
        $onlinetextenabled = $enabledplugins['onlinetext'];
        $fileenabled = $enabledplugins['file'];

        // Get submission text from online text.
        $onlinetextrecord = $onlinetextenabled ? $DB->get_record(
            'assignsubmission_onlinetext',
            ['submission' => $assignment->subid],
            'onlinetext, onlineformat'
        ) : false;
        $onlinetext = '';
        if ($onlinetextrecord && !empty($onlinetextrecord->onlinetext)) {
            // Width 0 disables wordwrap and preserves indentation in code submissions.
            $onlinetext = html_to_text(format_text(
                $onlinetextrecord->onlinetext,
                $onlinetextrecord->onlineformat,
                ['filter' => false]
            ), 0);
            // Html_to_text converts spaces in <pre> blocks to non-breaking spaces.
            $onlinetext = str_replace("\xc2\xa0", ' ', $onlinetext);
            mtrace("Content from text submission added to the prompt.");
        }

        // Get submission content from files (all files converted to text).
        $fileresult = $fileenabled
            ? $this->extract_content_from_files($assignment, $actinguserid)
            : ['text' => '', 'processedfiles' => [], 'skippedfiles' => []];
        $filetext = $fileresult['text'];

        // Log unconvertible files so it's visible in the task output.
        if (!empty($fileresult['skippedfiles'])) {
            foreach ($fileresult['skippedfiles'] as $skipped) {
                mtrace("WARNING: File '{$skipped['filename']}' could not be converted and was excluded from AI analysis"
                    . " (reason: {$skipped['reason']}).");
            }
        }

        // Structure the submission content based on available sources.
        $submissiontext = $this->build_structured_submission($onlinetext ?: '', $filetext, $fileresult);

        if (empty(trim($submissiontext))) {
            mtrace("No submission text found");
            return ['prompt' => '', 'options' => [], 'skippedfiles' => $fileresult['skippedfiles']];
        }

        // Extract content from assignment additional files (introattachments).
        // Teachers often use these to provide detailed instructions or rubric sheets.
        // Only included when the useintroattachments setting is enabled.
        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => $assignment->aid]);
        if (!empty($aifconfig->useintroattachments)) {
            $introattachmenttext = $this->extract_introattachment_content($assignment, $actinguserid);
            if (!empty($introattachmenttext)) {
                $description .= "\n\n" . get_string('introattachmentsheading', 'assignfeedback_aif')
                    . "\n" . $introattachmenttext;
                mtrace("Content from assignment additional files included in prompt.");
            }
        }

        // Use the template system to build the full prompt.
        $prompt = $this->build_prompt_from_template(
            $submissiontext,
            $rubrictext,
            $teacherprompt,
            $assignmentname,
            $description,
            $activityinstructions
        );

        return ['prompt' => $prompt, 'options' => $options, 'skippedfiles' => $fileresult['skippedfiles']];
    }

    /**
     * Build structured submission text with labels when multiple sources exist.
     *
     * When both online text and file content are present, adds section labels
     * so the LLM can distinguish the different sources.
     *
     * @param string $onlinetext The student's online text submission.
     * @param string $filetext The extracted text from submitted files.
     * @param array $fileresult The file extraction result including metadata.
     * @return string The structured submission text.
     */
    private function build_structured_submission(string $onlinetext, string $filetext, array $fileresult): string {
        $hasonline = !empty(trim($onlinetext));
        $hasfiles = !empty(trim($filetext));

        if ($hasonline && $hasfiles) {
            // Both sources: label them clearly for the LLM.
            $parts = [];
            $parts[] = "[Online text submission]\n" . $onlinetext;
            $parts[] = "[Submitted files]\n" . $filetext;
            // Note skipped files for AI context.
            if (!empty($fileresult['skippedfiles'])) {
                $skippednames = array_column($fileresult['skippedfiles'], 'filename');
                $parts[] = "[Note: The following files could not be analysed and are not included: "
                    . implode(', ', $skippednames) . "]";
            }
            return implode("\n\n", $parts);
        }

        if ($hasonline) {
            return $onlinetext;
        }

        if ($hasfiles) {
            $text = $filetext;
            if (!empty($fileresult['skippedfiles'])) {
                $skippednames = array_column($fileresult['skippedfiles'], 'filename');
                $text .= "\n\n[Note: The following files could not be analysed and are not included: "
                    . implode(', ', $skippednames) . "]";
            }
            return $text;
        }

        return '';
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
     * Get a formatted list of all file extensions supported by the plugin.
     *
     * Delegates to local_ai_content's extractor which knows about all supported
     * backends and converters.
     *
     * @return string Comma-separated list of uppercase file extensions (e.g. "DOC, DOCX, GIF, JPEG, PDF, PNG, TXT, WEBP").
     */
    public static function get_supported_file_extensions(): string {
        $extractor = \core\di::get(\local_ai_content\document_extractor::class);
        return $extractor->get_supported_extensions();
    }

    /**
     * Extract text content from all submitted files.
     *
     * Delegates per-file extraction to local_ai_content's extractor service
     * which handles caching, AI backend calls, and document conversion.
     *
     * @param stdClass $assignment The assignment data object.
     * @param int $actinguserid The user all extraction requests are performed for.
     * @return array Associative array with 'text' (combined text), 'processedfiles' (list of names),
     *               and 'skippedfiles' (list of arrays with 'filename' and 'reason' keys).
     */
    protected function extract_content_from_files(stdClass $assignment, int $actinguserid): array {
        $fs = get_file_storage();
        $contextid = $assignment->contextid;
        $component = 'assignsubmission_file';
        $filearea = 'submission_files';
        $itemid = $assignment->subid;

        $files = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'itemid, filepath, filename', false);
        if (!$files) {
            return ['text' => '', 'processedfiles' => [], 'skippedfiles' => []];
        }

        $extractor = \core\di::get(\local_ai_content\document_extractor::class);
        $alltext = '';
        $processedfiles = [];
        $skippedfiles = [];

        foreach ($files as $file) {
            if (!$file instanceof \stored_file) {
                continue;
            }

            $filename = $file->get_filename();

            if (!$extractor->is_file_supported($file)) {
                $skippedfiles[] = [
                    'filename' => $filename,
                    'reason' => 'skipreason_conversionnotsupported',
                    'reasondata' => self::get_supported_file_extensions(),
                ];
                mtrace("File '{$filename}' is not supported - skipping.");
                continue;
            }

            try {
                $text = $extractor->extract_text_from_file(
                    $file,
                    $contextid,
                    $actinguserid,
                    'assignfeedback_aif'
                );
                if (!empty($text)) {
                    $alltext .= $text . "\n";
                    $processedfiles[] = $filename;
                    mtrace("Text extracted from '{$filename}'.");
                } else {
                    $skippedfiles[] = ['filename' => $filename, 'reason' => 'skipreason_nocontent'];
                }
            } catch (\Exception $e) {
                mtrace("Failed to extract text from '{$filename}': " . $e->getMessage());
                $skippedfiles[] = [
                    'filename' => $filename,
                    'reason' => 'skipreason_extractionfailed',
                    'errormessage' => $e->getMessage(),
                ];
            }
        }

        return [
            'text' => trim($alltext),
            'processedfiles' => $processedfiles,
            'skippedfiles' => $skippedfiles,
        ];
    }

    /**
     * Extract text content from assignment introattachment files.
     *
     * These are the "Additional files" uploaded by the teacher in the assignment settings.
     * Teachers often use these to provide detailed task descriptions or grading criteria.
     * Delegates per-file extraction to local_ai_content's extractor service.
     *
     * @param stdClass $assignment The assignment data object.
     * @param int $actinguserid The user the extraction requests are performed for when the file has no owner.
     * @return string The combined extracted text from introattachment files.
     * @throws \moodle_exception If extraction fails for any file.
     */
    protected function extract_introattachment_content(stdClass $assignment, int $actinguserid): string {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $assignment->contextid,
            'mod_assign',
            'introattachment',
            0,
            'filepath, filename',
            false
        );

        if (!$files) {
            return '';
        }

        $extractor = \core\di::get(\local_ai_content\document_extractor::class);
        $alltext = '';
        $firsterror = null;

        foreach ($files as $file) {
            if (!$file instanceof \stored_file) {
                continue;
            }

            $filename = $file->get_filename();

            if (!$extractor->is_file_supported($file)) {
                continue;
            }

            try {
                // Prefer the file owner (teacher) for ITT requests so that ToS checks and
                // quota are attributed to the author of the material. Restored or system
                // generated files may have no owner, in which case the acting user is used
                // so that an AI request is never performed without a user behind it.
                $fileowner = (int) $file->get_userid();
                $text = $extractor->extract_text_from_file(
                    $file,
                    $assignment->contextid,
                    $fileowner > 0 ? $fileowner : $actinguserid,
                    'assignfeedback_aif'
                );
                if (!empty($text)) {
                    $alltext .= "[{$filename}]\n" . $text . "\n";
                }
            } catch (\Exception $e) {
                mtrace("Failed to extract text from introattachment file '{$filename}': " . $e->getMessage());
                if ($firsterror === null) {
                    $firsterror = $e;
                }
            }
        }

        // If any AI requests failed, throw the first error so the caller
        // can report the actual AI backend error message to the user.
        if ($firsterror !== null) {
            throw new moodle_exception(
                'failedtoextractintroattachmentfiles',
                'assignfeedback_aif',
                '',
                $firsterror->getMessage()
            );
        }

        return trim($alltext);
    }
}
