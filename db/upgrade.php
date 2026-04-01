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

    return true;
}
