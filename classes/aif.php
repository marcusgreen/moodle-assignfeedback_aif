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
use assignfeedback_editpdf\pdf;
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
     * @param int $contextid The context ID.
     */
    public function __construct(int $contextid) {
        $this->contextid = $contextid;
    }

    /**
     * Perform AI request using the configured backend.
     *
     * Uses the DI-injectable ai_request_provider. In tests, replace it
     * via \core\di::set(ai_request_provider::class, $mock).
     *
     * @param string $prompt The prompt to send to the AI.
     * @param string|null $purpose The purpose of the request (for local_ai_manager). Defaults to 'feedback'.
     * @param array $options Additional options (e.g., 'image' for ITT requests).
     * @param int $userid The user to attribute the AI request to. Defaults to current $USER.
     * @return string The AI response.
     * @throws \moodle_exception
     */
    public function perform_request(string $prompt, ?string $purpose = null, array $options = [], int $userid = 0): string {
        if ($userid === 0) {
            global $USER;
            $userid = $USER->id;
        }

        $provider = \core\di::get(ai_request_provider::class);

        if ($purpose === null) {
            $purpose = 'feedback';
        }

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

        // Expert mode detection: if the teacher's prompt contains {{submission}},
        // it replaces the admin template entirely.
        $isexpertmode = str_contains($prompt, '{{submission}}');

        if ($isexpertmode) {
            // In expert mode, the teacher's prompt IS the complete template.
            // Build the rubric section: full section with heading if rubric exists, empty otherwise.
            $rubricsection = '';
            if (!empty(trim($rubric))) {
                $rubricsection = "=== GRADING CRITERIA ===\n" . $rubric;
            }

            $replacements = [
                '{{submission}}' => $submission,
                '{{rubric_section}}' => $rubricsection,
                '{{rubric}}' => $rubric,
                '{{assignmentname}}' => $assignmentname,
                '{{description}}' => $description,
                '{{activityinstructions}}' => $activityinstructions,
                '{{language}}' => $language,
            ];
            return str_replace(array_keys($replacements), array_values($replacements), $prompt);
        }

        // Standard mode: inject the teacher's prompt into the admin template.
        $template = get_config('assignfeedback_aif', 'prompttemplate');
        if (empty($template)) {
            $template = get_string('defaultprompttemplate', 'assignfeedback_aif');
        }

        // Build the rubric section: full section with heading if rubric exists, empty otherwise.
        $rubricsection = '';
        if (!empty(trim($rubric))) {
            $rubricsection = "=== GRADING CRITERIA ===\n" . $rubric;
        }

        $replacements = [
            '{{submission}}' => $submission,
            '{{rubric_section}}' => $rubricsection,
            '{{rubric}}' => $rubric,
            '{{prompt}}' => $prompt,
            '{{assignmentname}}' => $assignmentname,
            '{{description}}' => $description,
            '{{activityinstructions}}' => $activityinstructions,
            '{{language}}' => $language,
        ];

        $result = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Remove template sections that have empty content (e.g. no description or instructions).
        $result = preg_replace('/^=== [A-Z ]+===\n\s*\n/m', '', $result);

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
     * Get prompt for a given assignment submission.
     *
     * Extracts text from all submitted content (online text, documents, images, PDFs)
     * and builds a combined prompt. Images and PDFs are converted to text via AI (ITT purpose)
     * before being included in the final prompt.
     *
     * @param stdClass $assignment The assignment data object.
     * @param string $gradingmethod The grading method (e.g., 'rubric').
     * @return array Array with 'prompt' string and 'options' array.
     */
    public function get_prompt(stdClass $assignment, string $gradingmethod): array {
        global $DB;

        mtrace("Assignment {$assignment->aid} submission {$assignment->subid} user {$assignment->userid}");

        $rubrictext = '';
        $submissiontext = '';
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

        // Get submission text from online text.
        if ($onlinetext = $DB->get_field('assignsubmission_onlinetext', 'onlinetext', ['submission' => $assignment->subid])) {
            mtrace("Content from text submission added to the prompt.");
            $submissiontext .= $onlinetext;
        }

        // Get submission content from files (all files converted to text).
        $filetext = $this->extract_content_from_files($assignment);
        if (!empty($filetext)) {
            $submissiontext .= ' ' . $filetext;
        }

        if (empty($submissiontext)) {
            mtrace("No submission text found");
            return ['prompt' => '', 'options' => []];
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
     * Image MIME types that can be sent to AI for text extraction.
     */
    private const IMAGE_MIMETYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * Extract text content from all submitted files.
     *
     * All file types are converted to text:
     * - Text files: read directly.
     * - Documents (PDF, DOCX...): converted via core_files converter or PDF-to-images + ITT.
     * - Images (PNG, JPEG, WebP, GIF): converted to text via AI ITT (image-to-text) requests.
     * - PDFs: each page rendered as image, then converted to text via ITT.
     *
     * Results are cached by content hash to avoid repeated expensive AI calls.
     *
     * @param stdClass $assignment The assignment data object.
     * @return string The combined extracted text from all files.
     */
    protected function extract_content_from_files(stdClass $assignment): string {
        $fs = get_file_storage();
        $contextid = $assignment->contextid;
        $component = 'assignsubmission_file';
        $filearea = 'submission_files';
        $itemid = $assignment->subid;

        $files = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'itemid, filepath, filename', false);
        if (!$files) {
            return '';
        }

        $alltext = '';

        foreach ($files as $file) {
            if (!$file instanceof \stored_file) {
                continue;
            }

            $mimetype = $file->get_mimetype();
            $filename = $file->get_filename();

            // Plain text files: read directly.
            if ($mimetype === 'text/plain') {
                $tempfile = $file->copy_content_to_temp();
                $alltext .= file_get_contents($tempfile) . "\n";
                unlink($tempfile);
                mtrace("Text content from '{$filename}' added to the prompt.");
                continue;
            }

            // Images: convert to text via ITT.
            if (in_array($mimetype, self::IMAGE_MIMETYPES)) {
                $extractedtext = $this->extract_content_from_image($file);
                if (!empty($extractedtext)) {
                    $alltext .= $extractedtext . "\n";
                    mtrace("Text extracted from image '{$filename}' via ITT.");
                }
                continue;
            }

            // PDFs: render pages as images, then extract text via ITT.
            if ($mimetype === 'application/pdf') {
                $extractedtext = $this->extract_content_from_pdf($file);
                if (!empty($extractedtext)) {
                    $alltext .= $extractedtext . "\n";
                    mtrace("Text extracted from PDF '{$filename}' via page-by-page ITT.");
                }
                continue;
            }

            // Other document types: try core_files converter (e.g., DOCX → TXT).
            $extractedtext = $this->extract_content_via_converter($file);
            if (!empty($extractedtext)) {
                $alltext .= $extractedtext . "\n";
                mtrace("Content from file '{$filename}' converted and added to the prompt.");
            }
        }

        return trim($alltext);
    }

    /**
     * Extract text from an image file using AI image-to-text (ITT).
     *
     * Results are cached by content hash to avoid repeated AI calls.
     *
     * @param \stored_file $file The image file.
     * @return string The extracted text, or empty string on failure.
     */
    protected function extract_content_from_image(\stored_file $file): string {
        // Check cache first.
        $cached = $this->get_from_cache($file->get_contenthash());
        if ($cached !== null) {
            mtrace("Using cached content for '{$file->get_filename()}'.");
            return $cached;
        }

        $encodedimage = 'data:' . $file->get_mimetype() . ';base64,' . base64_encode($file->get_content());

        try {
            $content = $this->retrieve_text_from_ai($encodedimage);
            $this->store_to_cache($file->get_contenthash(), $content);
            return $content;
        } catch (\Exception $e) {
            mtrace("Failed to extract text from image '{$file->get_filename()}': " . $e->getMessage());
            return '';
        }
    }

    /**
     * Extract text from a PDF by rendering each page as an image and sending to ITT.
     *
     * Uses ghostscript/pdftoppm (via assignfeedback_editpdf) to render pages.
     * Results are cached by content hash to avoid repeated AI calls.
     *
     * @param \stored_file $file The PDF file.
     * @return string The combined extracted text from all pages.
     */
    protected function extract_content_from_pdf(\stored_file $file): string {
        // Check cache first.
        $cached = $this->get_from_cache($file->get_contenthash());
        if ($cached !== null) {
            mtrace("Using cached content for PDF '{$file->get_filename()}'.");
            return $cached;
        }

        try {
            $encodedimages = $this->convert_pdf_to_images($file);
        } catch (\Exception $e) {
            mtrace("Failed to convert PDF '{$file->get_filename()}' to images: " . $e->getMessage());
            // Fallback: try core_files converter.
            return $this->extract_content_via_converter($file);
        }

        $content = '';
        $pagenum = 0;
        foreach ($encodedimages as $encodedimage) {
            $pagenum++;
            try {
                $pagetext = $this->retrieve_text_from_ai($encodedimage);
                $content .= $pagetext . "\n";
                mtrace("Extracted text from PDF page {$pagenum}/{" . count($encodedimages) . "}.");
            } catch (\Exception $e) {
                mtrace("Failed to extract text from PDF page {$pagenum}: " . $e->getMessage());
            }
        }

        $content = trim($content);
        if (!empty($content)) {
            $this->store_to_cache($file->get_contenthash(), $content);
        }

        return $content;
    }

    /**
     * Convert a PDF file into an array of base64-encoded page images.
     *
     * Uses assignfeedback_editpdf's PDF class (ghostscript/pdftoppm) to render pages.
     *
     * @param \stored_file $file The PDF file.
     * @return string[] Array of base64-encoded data URLs, one per page.
     * @throws \moodle_exception If the PDF cannot be processed.
     */
    protected function convert_pdf_to_images(\stored_file $file): array {
        $tmpdir = \make_request_directory();
        $tmpfilename = 'assignfeedback_aif_tmp_' . uniqid() . '.pdf';
        file_put_contents($tmpdir . '/' . $tmpfilename, $file->get_content());

        $pdf = new pdf();
        $pdf->set_image_folder($tmpdir);
        $pdf->set_pdf($tmpdir . '/' . $tmpfilename);
        $images = $pdf->get_images();

        $imagearray = [];
        foreach ($images as $image) {
            $imagepath = $tmpdir . '/' . $image;
            $imagecontent = file_get_contents($imagepath);
            $imagemime = mime_content_type($imagepath);
            $imagearray[] = 'data:' . $imagemime . ';base64,' . base64_encode($imagecontent);
        }

        return $imagearray;
    }

    /**
     * Send an encoded image to the AI backend for text extraction (ITT purpose).
     *
     * @param string $encodedimage Base64-encoded data URL of the image.
     * @return string The extracted text from the AI response.
     * @throws \moodle_exception If the AI request fails.
     */
    protected function retrieve_text_from_ai(string $encodedimage): string {
        $imageprompt = 'Return the text that is written on the image/document. '
            . 'Do not wrap any explanatory text around. Return only the bare content.';

        return $this->perform_request($imageprompt, 'itt', ['image' => $encodedimage]);
    }

    /**
     * Extract text from a file using the core_files converter (e.g., DOCX to TXT).
     *
     * @param \stored_file $file The file to convert.
     * @return string The extracted text, or empty string if conversion fails.
     */
    protected function extract_content_via_converter(\stored_file $file): string {
        $converter = new \core_files\converter();
        $format = 'txt';

        if (!$converter->can_convert_storedfile_to($file, $format)) {
            mtrace("Site document converter does not support conversion for: {$file->get_mimetype()}");
            return '';
        }

        $conversion = $converter->start_conversion($file, $format);
        mtrace("Start process to convert file to TXT");

        if ($conversion->get('status') !== \core_files\conversion::STATUS_COMPLETE) {
            return '';
        }

        $convertedfile = $conversion->get_destfile();
        if (!$convertedfile) {
            return '';
        }

        $tempfile = $convertedfile->copy_content_to_temp();
        $text = file_get_contents($tempfile);
        unlink($tempfile);

        return $text;
    }

    /**
     * Get cached extracted content for a file by its content hash.
     *
     * @param string $contenthash The SHA1 content hash of the file.
     * @return string|null The cached content, or null if not found.
     */
    protected function get_from_cache(string $contenthash): ?string {
        global $DB;

        $record = $DB->get_record('assignfeedback_aif_rescache', ['contenthash' => $contenthash]);
        if (!$record) {
            return null;
        }

        // Update last accessed time.
        $clock = \core\di::get(\core\clock::class);
        $record->timelastaccessed = $clock->now()->getTimestamp();
        $DB->update_record('assignfeedback_aif_rescache', $record);

        return $record->extractedcontent;
    }

    /**
     * Store extracted content in the cache indexed by content hash.
     *
     * @param string $contenthash The SHA1 content hash of the file.
     * @param string $extractedcontent The extracted text content.
     */
    protected function store_to_cache(string $contenthash, string $extractedcontent): void {
        global $DB;

        $clock = \core\di::get(\core\clock::class);
        $now = $clock->now()->getTimestamp();

        $existing = $DB->get_record('assignfeedback_aif_rescache', ['contenthash' => $contenthash]);
        if ($existing) {
            $existing->extractedcontent = $extractedcontent;
            $existing->timemodified = $now;
            $existing->timelastaccessed = $now;
            $DB->update_record('assignfeedback_aif_rescache', $existing);
            return;
        }

        $record = new stdClass();
        $record->contenthash = $contenthash;
        $record->extractedcontent = $extractedcontent;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->timelastaccessed = $now;
        $DB->insert_record('assignfeedback_aif_rescache', $record);
    }
}
