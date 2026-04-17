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
 * Tests for reset_userdata integration and get_file_areas.
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aif;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../tests/generator.php');
require_once(__DIR__ . '/generator_trait.php');

/**
 * Tests that course reset cleans up all plugin data via mod_assign's reset_userdata.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assign_feedback_aif
 */
final class reset_userdata_test extends \advanced_testcase {
    use aif_test_helper;

    /**
     * Test get_file_areas returns the expected feedback file area.
     *
     * @covers \assign_feedback_aif::get_file_areas
     */
    public function test_get_file_areas_returns_feedback_area(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $plugin = $this->get_aif_plugin($env->assignobj);

        $areas = $plugin->get_file_areas();

        $this->assertIsArray($areas);
        $this->assertArrayHasKey(\assign_feedback_aif::FILEAREA, $areas);
        $this->assertSame('assignfeedback_aif_feedback', \assign_feedback_aif::FILEAREA);
        // Human readable label must be non-empty.
        $this->assertNotEmpty($areas[\assign_feedback_aif::FILEAREA]);
    }

    /**
     * Test that reset_userdata with reset_assign_submissions removes all AIF data.
     *
     * Covers the integration point: mod_assign iterates feedback plugins and
     * relies on get_file_areas() + delete_instance(). Both must work together
     * to fully purge per-assignment config, per-submission feedback and files.
     *
     * @covers \assign_feedback_aif::get_file_areas
     * @covers \assign_feedback_aif::delete_instance
     */
    public function test_reset_userdata_removes_all_plugin_data(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env);
        $aifid = $this->create_aif_config($env, 'Prompt to be wiped', 1);

        // Insert a feedback record for the student's submission.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $this->assertNotFalse($submission);
        $clock = \core\di::get(\core\clock::class);
        $feedbackid = $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'submission' => $submission->id,
            'feedback' => 'Some AI feedback that must be deleted',
            'feedbackformat' => FORMAT_HTML,
            'timecreated' => $clock->now()->getTimestamp(),
            'timemodified' => $clock->now()->getTimestamp(),
        ]);

        // Store a file in the feedback editor filearea for this grade.
        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $env->context->id,
            'component' => 'assignfeedback_aif',
            'filearea' => 'assignfeedback_aif_feedback',
            'itemid' => $grade->id,
            'filepath' => '/',
            'filename' => 'attached.txt',
        ];
        $fs->create_file_from_string($filerecord, 'AI feedback attachment');

        // Sanity: data exists before reset.
        $this->assertTrue($DB->record_exists('assignfeedback_aif', ['id' => $aifid]));
        $this->assertTrue($DB->record_exists('assignfeedback_aif_feedback', ['id' => $feedbackid]));
        $this->assertNotEmpty($fs->get_area_files(
            $env->context->id,
            'assignfeedback_aif',
            'assignfeedback_aif_feedback',
            $grade->id,
            'sortorder',
            false
        ));

        // Trigger mod_assign's reset_userdata with submissions reset enabled.
        $data = new \stdClass();
        $data->courseid = $env->course->id;
        $data->reset_assign_submissions = 1;
        $data->reset_gradebook_grades = 1;
        $data->timeshift = 0;
        $env->assignobj->reset_userdata($data);

        // All per-assignment config rows removed.
        $this->assertFalse($DB->record_exists('assignfeedback_aif', ['assignment' => $env->assign->id]));
        // All per-submission feedback rows removed.
        $this->assertFalse($DB->record_exists('assignfeedback_aif_feedback', ['id' => $feedbackid]));
        // File area for the feedback editor is empty.
        $this->assertEmpty($fs->get_area_files(
            $env->context->id,
            'assignfeedback_aif',
            'assignfeedback_aif_feedback',
            $grade->id,
            'sortorder',
            false
        ));
    }

    /**
     * Test reset_userdata without reset_assign_submissions keeps plugin data intact.
     *
     * @covers \assign_feedback_aif::get_file_areas
     * @covers \assign_feedback_aif::delete_instance
     */
    public function test_reset_userdata_without_submission_flag_keeps_data(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $aifid = $this->create_aif_config($env, 'Kept prompt', 0);

        $data = new \stdClass();
        $data->courseid = $env->course->id;
        $data->reset_assign_submissions = 0;
        $data->reset_gradebook_grades = 0;
        $data->timeshift = 0;
        $env->assignobj->reset_userdata($data);

        $this->assertTrue($DB->record_exists('assignfeedback_aif', ['id' => $aifid]));
    }

    /**
     * Get the AIF feedback plugin from an assign instance.
     *
     * @param \mod_assign_testable_assign $assignobj The testable assign instance.
     * @return \assign_feedback_aif The AIF feedback plugin.
     */
    private function get_aif_plugin(\mod_assign_testable_assign $assignobj): \assign_feedback_aif {
        $plugins = $assignobj->get_feedback_plugins();
        foreach ($plugins as $plugin) {
            if ($plugin->get_type() === 'aif') {
                return $plugin;
            }
        }
        $this->fail('AIF feedback plugin not found on assignment.');
    }
}
