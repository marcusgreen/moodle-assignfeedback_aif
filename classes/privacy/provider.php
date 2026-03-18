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
 * Privacy class for requesting user data.
 *
 * @package    assignfeedback_aif
 * @copyright  2025 Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aif\privacy;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use core_privacy\local\request\contextlist;
use mod_assign\privacy\assign_plugin_request_data;
use mod_assign\privacy\useridlist;

/**
 * Privacy class for requesting user data.
 *
 * @package    assignfeedback_aif
 * @copyright  2025 Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \mod_assign\privacy\assignfeedback_provider,
    \mod_assign\privacy\assignfeedback_user_provider {
    /**
     * Return meta data about this plugin.
     *
     * @param  collection $collection A list of information to add to.
     * @return collection Return the collection after adding to it.
     */
    public static function get_metadata(collection $collection): collection {
        $data = [
            'assignment' => 'privacy:metadata:assignmentid',
            'aitext' => 'privacy:metadata:aitext',
        ];
        $collection->add_database_table('assignfeedback_aif_feedback', $data, 'privacy:metadata:tablesummary');

        return $collection;
    }

    /**
     * No need to fill in this method as all information can be acquired from the assign_grades table in the mod assign
     * provider.
     *
     * @param  int $userid The user ID.
     * @param  contextlist $contextlist The context list.
     */
    public static function get_context_for_userid_within_feedback(int $userid, contextlist $contextlist) {
        // This uses the assign_grades table.
    }

    /**
     * This also does not need to be filled in as this is already collected in the mod assign provider.
     *
     * @param  useridlist $useridlist A list of user IDs
     */
    public static function get_student_user_ids(useridlist $useridlist) {
        // Not required.
    }

    /**
     * If you have tables that contain userids and you can generate entries in your tables without creating an
     * entry in the assign_grades table then please fill in this method.
     *
     * @param  \core_privacy\local\request\userlist $userlist The userlist object
     */
    public static function get_userids_from_context(\core_privacy\local\request\userlist $userlist) {
        // Not required.
    }

    /**
     * Export all user data for this plugin.
     *
     * @param  assign_plugin_request_data $exportdata Data used to determine which context and user to export and other useful
     * information to help with exporting.
     */
    public static function export_feedback_user_data(assign_plugin_request_data $exportdata) {
        global $DB;

        $assign = $exportdata->get_assign();
        $grade = $exportdata->get_pluginobject();
        $assignmentid = $assign->get_instance()->id;

        // Find the submission for this grade's user.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assignmentid,
            'userid' => $grade->userid,
            'latest' => 1,
        ]);

        if (!$submission) {
            return;
        }

        // Get the AIF feedback for this submission.
        $sql = "SELECT aiff.*
                  FROM {assignfeedback_aif} aif
                  JOIN {assignfeedback_aif_feedback} aiff ON aiff.aif = aif.id
                 WHERE aif.assignment = :assignmentid
                   AND aiff.submission = :submissionid";
        $feedback = $DB->get_record_sql($sql, [
            'assignmentid' => $assignmentid,
            'submissionid' => $submission->id,
        ]);

        if ($feedback && !empty($feedback->feedback)) {
            $currentpath = array_merge(
                $exportdata->get_subcontext(),
                [get_string('privacy:aipath', 'assignfeedback_aif')]
            );

            $data = (object) [
                'feedback' => format_text($feedback->feedback, $feedback->feedbackformat,
                    ['context' => $exportdata->get_context()]),
                'timecreated' => $feedback->timecreated ?
                    \core_privacy\local\request\transform::datetime($feedback->timecreated) : '',
            ];
            writer::with_context($exportdata->get_context())->export_data($currentpath, $data);
        }
    }

    /**
     * Any call to this method should delete all user data for the context defined in the deletion_criteria.
     *
     * @param  assign_plugin_request_data $requestdata Data useful for deleting user data from this sub-plugin.
     */
    public static function delete_feedback_for_context(assign_plugin_request_data $requestdata) {
        $assign = $requestdata->get_assign();
        $plugin = $assign->get_plugin_by_type('assignfeedback', 'aif');
        $plugin->delete_instance();
    }

    /**
     * Calling this function should delete all user data associated with this grade entry.
     *
     * @param  assign_plugin_request_data $requestdata Data useful for deleting user data.
     */
    public static function delete_feedback_for_grade(assign_plugin_request_data $requestdata) {
        global $DB;

        $grade = $requestdata->get_pluginobject();
        $assignmentid = $requestdata->get_assign()->get_instance()->id;

        // Find the submission for this grade's user.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assignmentid,
            'userid' => $grade->userid,
            'latest' => 1,
        ]);

        if (!$submission) {
            return;
        }

        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if (!$aif) {
            return;
        }

        $DB->delete_records('assignfeedback_aif_feedback', ['aif' => $aif->id, 'submission' => $submission->id]);
    }

    /**
     * Deletes all feedback for the grade ids / userids provided in a context.
     *
     * @param assign_plugin_request_data $deletedata A class that contains the relevant information required for deletion.
     */
    public static function delete_feedback_for_grades(assign_plugin_request_data $deletedata): void {
        global $DB;

        $gradeids = $deletedata->get_gradeids();
        if (empty($gradeids)) {
            return;
        }

        $assignmentid = $deletedata->get_assign()->get_instance()->id;

        $aif = $DB->get_record('assignfeedback_aif', ['assignment' => $assignmentid]);
        if (!$aif) {
            return;
        }

        // Get userids from the grades to be deleted.
        [$insql, $inparams] = $DB->get_in_or_equal($gradeids, SQL_PARAMS_NAMED);
        $userids = $DB->get_fieldset_sql("SELECT userid FROM {assign_grades} WHERE id $insql", $inparams);

        if (empty($userids)) {
            return;
        }

        // Get submission IDs for these users.
        [$uinsql, $uinparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $uinparams['assignmentid'] = $assignmentid;
        $submissionids = $DB->get_fieldset_sql(
            "SELECT id FROM {assign_submission} WHERE assignment = :assignmentid AND userid $uinsql AND latest = 1",
            $uinparams
        );

        if (empty($submissionids)) {
            return;
        }

        // Delete feedback only for these submissions.
        [$sinsql, $sinparams] = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED);
        $sinparams['aifid'] = $aif->id;
        $DB->delete_records_select('assignfeedback_aif_feedback', "aif = :aifid AND submission $sinsql", $sinparams);
    }
}
