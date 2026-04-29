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
 * Tests for the assignfeedback_aif backup and restore subplugin.
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aif;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(__DIR__ . '/../../../tests/generator.php');
require_once(__DIR__ . '/generator_trait.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests for the backup and restore of the AI feedback subplugin.
 *
 * Performs a full course backup containing an assign with AIF data and
 * restores it into a fresh course to verify that config, per-submission
 * feedback and editor files round-trip with proper ID remapping.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_assignfeedback_aif_subplugin
 * @covers     \restore_assignfeedback_aif_subplugin
 */
final class backup_restore_test extends \advanced_testcase {
    use aif_test_helper;

    /**
     * Test that an assignment with AIF data backs up and restores correctly.
     */
    public function test_backup_and_restore_roundtrip(): void {
        global $CFG, $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Backup file logger off so files can be cleaned up cross-platform.
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        // Seed environment with an assignment, submission and AI feedback.
        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student submission text');
        $aifid = $this->create_aif_config($env, 'Round-trip prompt text', 1);

        // Insert a feedback record and attach a file in the editor filearea.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $this->assertNotFalse($submission);

        $this->setUser($env->teacher);
        $grade = $env->assignobj->get_user_grade($env->student->id, true);

        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'submission' => $submission->id,
            'feedback' => 'Original AI feedback text',
            'feedbackformat' => FORMAT_HTML,
            'timecreated' => $clock->now()->getTimestamp(),
            'timemodified' => $clock->now()->getTimestamp(),
            'skippedfiles' => null,
        ]);

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $env->context->id,
            'component' => 'assignfeedback_aif',
            'filearea' => 'assignfeedback_aif_feedback',
            'itemid' => $grade->id,
            'filepath' => '/',
            'filename' => 'ai-attachment.txt',
        ];
        $fs->create_file_from_string($filerecord, 'File attached to AI feedback');

        // Perform full course backup.
        $this->setAdminUser();
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $env->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Restore into a new course.
        $newcourseid = \restore_dbops::create_new_course(
            $env->course->fullname . ' restored',
            $env->course->shortname . '_r',
            $env->course->category
        );
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        // Locate the restored assign instance.
        $newassigns = $DB->get_records('assign', ['course' => $newcourseid]);
        $this->assertCount(1, $newassigns);
        $newassign = reset($newassigns);
        $this->assertNotEquals($env->assign->id, $newassign->id, 'Restored assign must have a different id.');

        // The per-assignment config must be restored exactly once.
        $newconfigs = $DB->get_records('assignfeedback_aif', ['assignment' => $newassign->id]);
        $this->assertCount(1, $newconfigs, 'Config must be deduplicated on restore.');
        $newconfig = reset($newconfigs);
        $this->assertSame('Round-trip prompt text', $newconfig->prompt);
        $this->assertEquals(1, (int) $newconfig->autogenerate);

        // The feedback record must be restored and linked to the new submission.
        $newsubmission = $DB->get_record('assign_submission', [
            'assignment' => $newassign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $this->assertNotFalse($newsubmission, 'Student submission must be restored.');

        $newfeedbacks = $DB->get_records('assignfeedback_aif_feedback', [
            'aif' => $newconfig->id,
            'submission' => $newsubmission->id,
        ]);
        $this->assertCount(1, $newfeedbacks);
        $newfeedback = reset($newfeedbacks);
        $this->assertSame('Original AI feedback text', $newfeedback->feedback);
        $this->assertEquals(FORMAT_HTML, (int) $newfeedback->feedbackformat);

        // Editor files must be restored for the new grade.
        $newcm = get_coursemodule_from_instance('assign', $newassign->id);
        $newcontext = \core\context\module::instance($newcm->id);
        $newcourse = $DB->get_record('course', ['id' => $newcourseid], '*', MUST_EXIST);
        $newassignobj = new \mod_assign_testable_assign($newcontext, $newcm, $newcourse);
        $this->setUser($env->teacher);
        $newgrade = $newassignobj->get_user_grade($env->student->id, false);
        $this->assertNotFalse($newgrade, 'Grade must be restored.');

        $restoredfiles = $fs->get_area_files(
            $newcontext->id,
            'assignfeedback_aif',
            'assignfeedback_aif_feedback',
            $newgrade->id,
            'sortorder',
            false
        );
        $this->assertCount(1, $restoredfiles, 'Editor file must round-trip.');
        $restoredfile = reset($restoredfiles);
        $this->assertSame('ai-attachment.txt', $restoredfile->get_filename());
        $this->assertSame('File attached to AI feedback', $restoredfile->get_content());
    }
}
