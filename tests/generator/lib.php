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
 * Data generator for assignfeedback_aif.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignfeedback_aif_generator extends component_generator_base {
    /**
     * Create a pre-existing AI feedback record for testing.
     *
     * @param array $data Must contain 'assignment' (name) and 'userid'.
     *                     Optional: 'feedback', 'error'.
     */
    public function create_feedback(array $data): void {
        global $DB;

        $assign = $DB->get_record('assign', ['name' => $data['assignment']], '*', MUST_EXIST);

        // Ensure AIF config exists.
        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assign->id]);
        if (!$aif) {
            $clock = \core\di::get(\core\clock::class);
            $aifid = $DB->insert_record('assignfeedback_aif', [
                'assignment' => $assign->id,
                'prompt' => 'Test prompt',
                'autogenerate' => 0,
                'timecreated' => $clock->now()->getTimestamp(),
            ]);
            $aif = $DB->get_record('assignfeedback_aif', ['id' => $aifid]);
        }

        // Get the latest submission for the user.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $data['userid'],
            'latest' => 1,
        ], '*', MUST_EXIST);

        $clock = \core\di::get(\core\clock::class);
        $feedbacktext = $data['feedback'] ?? 'Pre-existing AI feedback for testing.';
        $skippedfiles = null;

        // If error is specified, create an error feedback record.
        if (!empty($data['error'])) {
            $feedbacktext = '';
            $skippedfiles = json_encode([['_error' => $data['error']]]);
        }

        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aif->id,
            'feedback' => $feedbacktext,
            'feedbackformat' => FORMAT_HTML,
            'timecreated' => $clock->now()->getTimestamp(),
            'submission' => $submission->id,
            'skippedfiles' => $skippedfiles,
        ]);
    }
}
