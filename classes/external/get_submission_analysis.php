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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core\context\module as context_module;

/**
 * External function to analyse a submission's files before AI feedback generation.
 *
 * Returns lists of processable and unprocessable files so the teacher can
 * make an informed decision in the confirmation dialog.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_submission_analysis extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'The assignment instance id'),
            'userid' => new external_value(PARAM_INT, 'The user id whose submission to analyse'),
        ]);
    }

    /**
     * Analyse a submission's content sources and file convertibility.
     *
     * @param int $assignmentid The assignment instance id.
     * @param int $userid The user id.
     * @return array Analysis result with file lists.
     */
    public static function execute(int $assignmentid, int $userid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
            'userid' => $userid,
        ]);

        $assignment = $DB->get_record('assign', ['id' => $params['assignmentid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assignment->id, $assignment->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        // Get the latest submission.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $params['assignmentid'],
            'userid' => $params['userid'],
            'latest' => 1,
        ]);

        if (!$submission) {
            return [
                'hasonlinetext' => false,
                'processablefiles' => [],
                'skippedfiles' => [],
            ];
        }

        // Check for online text.
        $onlinetext = $DB->get_field('assignsubmission_onlinetext', 'onlinetext', ['submission' => $submission->id]);
        $hasonlinetext = !empty($onlinetext);

        // Analyse submitted files.
        $processable = [];
        $skipped = [];

        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'itemid, filepath, filename',
            false
        );

        $imagemimetypes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
        $converter = new \core_files\converter();

        foreach ($files as $file) {
            if (!$file instanceof \stored_file) {
                continue;
            }

            $filename = $file->get_filename();
            $mimetype = $file->get_mimetype();

            if ($mimetype === 'text/plain') {
                $processable[] = ['filename' => $filename, 'mimetype' => $mimetype];
                continue;
            }

            if (in_array($mimetype, $imagemimetypes)) {
                $processable[] = ['filename' => $filename, 'mimetype' => $mimetype];
                continue;
            }

            if ($mimetype === 'application/pdf') {
                $processable[] = ['filename' => $filename, 'mimetype' => $mimetype];
                continue;
            }

            if ($converter->can_convert_storedfile_to($file, 'txt')) {
                $processable[] = ['filename' => $filename, 'mimetype' => $mimetype];
            } else {
                $skipped[] = [
                    'filename' => $filename,
                    'mimetype' => $mimetype,
                    'reason' => get_string(
                        'skipreason_conversionnotsupported',
                        'assignfeedback_aif',
                        \assignfeedback_aif\aif::get_supported_file_extensions()
                    ),
                ];
            }
        }

        return [
            'hasonlinetext' => $hasonlinetext,
            'processablefiles' => $processable,
            'skippedfiles' => $skipped,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $filestructure = new external_single_structure([
            'filename' => new external_value(PARAM_TEXT, 'The file name'),
            'mimetype' => new external_value(PARAM_TEXT, 'The MIME type'),
        ]);

        $skippedfilestructure = new external_single_structure([
            'filename' => new external_value(PARAM_TEXT, 'The file name'),
            'mimetype' => new external_value(PARAM_TEXT, 'The MIME type'),
            'reason' => new external_value(PARAM_TEXT, 'The reason why the file cannot be processed'),
        ]);

        return new external_single_structure([
            'hasonlinetext' => new external_value(PARAM_BOOL, 'Whether the submission has online text'),
            'processablefiles' => new external_multiple_structure($filestructure, 'Files that can be processed'),
            'skippedfiles' => new external_multiple_structure($skippedfilestructure, 'Files that cannot be converted'),
        ]);
    }
}
