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
 * Tests for the get_submission_analysis external function.
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2026 Fabian Barbuia, ISB Bayern
 * @author     Fabian Barbuia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aif\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../tests/generator.php');
require_once(__DIR__ . '/../generator_trait.php');

/**
 * Tests for the get_submission_analysis external function.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 Fabian Barbuia, ISB Bayern
 * @author     Fabian Barbuia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_aif\external\get_submission_analysis
 */
final class get_submission_analysis_test extends \advanced_testcase {
    use \assignfeedback_aif\aif_test_helper;

    /**
     * Online text submitted before switching to file submission must be ignored.
     *
     * When the teacher switches the submission type after the student has submitted online
     * text, mod_assign keeps the orphaned assignsubmission_onlinetext row. The analysis must
     * not report it, because the onlinetext submission plugin is no longer enabled.
     */
    public function test_orphaned_onlinetext_is_ignored_after_switching_type(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment([
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 0,
        ]);
        $this->create_and_submit($env, 'My online text answer');

        // The orphaned onlinetext row exists in the database.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $this->assertTrue($DB->record_exists('assignsubmission_onlinetext', ['submission' => $submission->id]));

        // Teacher switches the submission type: disable online text, enable file submission.
        $this->set_submission_plugin_enabled($env, 'onlinetext', false);
        $this->set_submission_plugin_enabled($env, 'file', true);

        $this->setUser($env->teacher);
        $result = get_submission_analysis::execute($env->assign->id, $env->student->id);
        $result = \core_external\external_api::clean_returnvalue(get_submission_analysis::execute_returns(), $result);

        $this->assertFalse($result['hasonlinetext']);
        $this->assertEmpty($result['processablefiles']);
        $this->assertEmpty($result['skippedfiles']);
    }

    /**
     * A submitted file is reported while the file submission plugin is enabled and ignored once it is disabled.
     */
    public function test_file_reported_only_while_plugin_enabled(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment([
            'assignsubmission_onlinetext_enabled' => 0,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
        ]);
        $this->add_file_submission($env, 'submission.txt');

        $this->setUser($env->teacher);
        $result = get_submission_analysis::execute($env->assign->id, $env->student->id);
        $result = \core_external\external_api::clean_returnvalue(get_submission_analysis::execute_returns(), $result);

        $this->assertFalse($result['hasonlinetext']);
        $this->assertCount(1, $result['processablefiles']);
        $this->assertEquals('submission.txt', $result['processablefiles'][0]['filename']);
        $this->assertEmpty($result['skippedfiles']);

        // Disabling the file submission type must hide the orphaned file from the analysis.
        $this->set_submission_plugin_enabled($env, 'file', false);

        $result = get_submission_analysis::execute($env->assign->id, $env->student->id);
        $result = \core_external\external_api::clean_returnvalue(get_submission_analysis::execute_returns(), $result);

        $this->assertFalse($result['hasonlinetext']);
        $this->assertEmpty($result['processablefiles']);
        $this->assertEmpty($result['skippedfiles']);
    }

    /**
     * A user without a submission yields an empty analysis result.
     */
    public function test_no_submission_returns_empty_analysis(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();

        $this->setUser($env->teacher);
        $result = get_submission_analysis::execute($env->assign->id, $env->student->id);
        $result = \core_external\external_api::clean_returnvalue(get_submission_analysis::execute_returns(), $result);

        $this->assertFalse($result['hasonlinetext']);
        $this->assertEmpty($result['processablefiles']);
        $this->assertEmpty($result['skippedfiles']);
    }
}
