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
 * Upgrade script for assignfeedback_aif.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_assignfeedback_aif_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026020601) {
        // Add autogenerate field to assignfeedback_aif table.
        $table = new xmldb_table('assignfeedback_aif');
        $field = new xmldb_field('autogenerate', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'prompt');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026020601, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026020605) {
        // Add feedbackformat field to assignfeedback_aif_feedback table.
        $table = new xmldb_table('assignfeedback_aif_feedback');
        $field = new xmldb_field('feedbackformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'feedback');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026020605, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026020901) {
        // Add resource cache table for extracted file content.
        $table = new xmldb_table('assignfeedback_aif_rescache');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('extractedcontent', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timelastaccessed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('contenthash', XMLDB_INDEX_UNIQUE, ['contenthash']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026020901, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026031801) {
        // Migrate assignfeedback_aif.assignment from cmid (course_modules.id) to assign.id.
        // The FK in install.xml already references assign.id, but the stored values were cmids.
        $sql = "UPDATE {assignfeedback_aif} aif
                   SET aif.assignment = (
                       SELECT cm.instance
                         FROM {course_modules} cm
                        WHERE cm.id = aif.assignment
                   )
                 WHERE EXISTS (
                       SELECT 1
                         FROM {course_modules} cm
                        WHERE cm.id = aif.assignment
                   )";
        $DB->execute($sql);

        upgrade_plugin_savepoint(true, 2026031801, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026031901) {
        // Change feedbackformat from FORMAT_HTML (1) to FORMAT_MARKDOWN (4) for AI-generated feedback.
        // AI responses are Markdown, not HTML. FORMAT_MARKDOWN ensures proper rendering.
        $DB->set_field('assignfeedback_aif_feedback', 'feedbackformat', FORMAT_MARKDOWN);

        upgrade_plugin_savepoint(true, 2026031901, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026033001) {
        // Clean up removed 'purpose' admin setting.
        unset_config('purpose', 'assignfeedback_aif');

        upgrade_plugin_savepoint(true, 2026033001, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026040100) {
        // Add timemodified field to assignfeedback_aif_feedback table.
        $table = new xmldb_table('assignfeedback_aif_feedback');
        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Initialize timemodified from timecreated for existing records.
        $DB->execute("UPDATE {assignfeedback_aif_feedback} SET timemodified = timecreated WHERE timemodified = 0");

        // Fix feedbackformat DEFAULT: upgrade step 2026020605 used DEFAULT=1 (FORMAT_HTML)
        // but install.xml had DEFAULT=4 (FORMAT_MARKDOWN). Align to FORMAT_HTML since the
        // adhoc task converts Markdown to HTML before storing.
        $field = new xmldb_field('feedbackformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'feedback');
        $dbman->change_field_default($table, $field);

        upgrade_plugin_savepoint(true, 2026040100, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026040102) {
        // Add skippedfiles field to store names of files that could not be analysed.
        $table = new xmldb_table('assignfeedback_aif_feedback');
        $field = new xmldb_field('skippedfiles', XMLDB_TYPE_TEXT, null, null, null, null, null, 'submission');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026040102, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026071600) {
        $table = new xmldb_table('assignfeedback_aif_feedback');

        // Step 1: Remove duplicates — keep only the newest record per (aif, submission).
        $sql = "SELECT aif, submission, MAX(id) AS keepid
                  FROM {assignfeedback_aif_feedback}
                 WHERE aif IS NOT NULL AND submission IS NOT NULL
              GROUP BY aif, submission
                HAVING COUNT(*) > 1";
        $duplicates = $DB->get_records_sql($sql);
        foreach ($duplicates as $dup) {
            $DB->delete_records_select(
                'assignfeedback_aif_feedback',
                'aif = :aif AND submission = :submission AND id <> :keepid',
                ['aif' => $dup->aif, 'submission' => $dup->submission, 'keepid' => $dup->keepid]
            );
        }

        // Step 2: Add status field.
        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'pending', 'feedbackformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Step 3: Add errormessage field.
        $field = new xmldb_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Step 4: Migrate existing records — completed records (non-empty feedback).
        $DB->execute(
            "UPDATE {assignfeedback_aif_feedback}
                SET status = 'completed'
              WHERE feedback IS NOT NULL AND feedback <> ''"
        );

        // Step 5: Migrate error records — those with literal '_error' in skippedfiles.
        $likesql = $DB->sql_like('skippedfiles', ':pattern');
        $errorrecords = $DB->get_records_select(
            'assignfeedback_aif_feedback',
            $likesql . " AND (feedback IS NULL OR feedback = '')",
            ['pattern' => '%' . $DB->sql_like_escape('_error') . '%']
        );
        foreach ($errorrecords as $rec) {
            $skipped = json_decode($rec->skippedfiles, true);
            $errormsg = '';
            if (is_array($skipped)) {
                foreach ($skipped as $entry) {
                    if (is_array($entry) && isset($entry['_error'])) {
                        $errormsg = $entry['_error'];
                        break;
                    }
                }
            }
            $DB->update_record('assignfeedback_aif_feedback', (object) [
                'id' => $rec->id,
                'status' => 'error',
                'errormessage' => $errormsg,
            ]);
        }

        // Step 6: Add UNIQUE INDEX on (aif, submission).
        $index = new xmldb_index('idx_aif_submission', XMLDB_INDEX_UNIQUE, ['aif', 'submission']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026071600, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026071701) {
        // Add useintroattachments field to config table (default 1 for backwards compatibility).
        $table = new xmldb_table('assignfeedback_aif');
        $field = new xmldb_field('useintroattachments', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'autogenerate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026071701, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026071702) {
        // Drop the rescache table — caching is now handled by local_ai_content.
        $table = new xmldb_table('assignfeedback_aif_rescache');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        unset_config('cachecleanupdelay', 'assignfeedback_aif');

        upgrade_plugin_savepoint(true, 2026071702, 'assignfeedback', 'aif');
    }

    if ($oldversion < 2026072700) {
        // Purge all queued process_feedback_adhoc tasks.
        //
        // Earlier versions could create duplicate tasks for the same
        // assignment/user combination because Moodle's built-in deduplication
        // also compares the task runner (userid). Those leftover tasks block
        // crash recovery and keep feedback records stuck in 'pending'.
        // Removing them is safe: teachers and students can retry generation,
        // and records left in 'pending' are picked up by crash recovery.
        $classname = \core\task\manager::get_canonical_class_name(
            \assignfeedback_aif\task\process_feedback_adhoc::class
        );
        $DB->delete_records('task_adhoc', ['classname' => $classname]);

        upgrade_plugin_savepoint(true, 2026072700, 'assignfeedback', 'aif');
    }

    return true;
}
