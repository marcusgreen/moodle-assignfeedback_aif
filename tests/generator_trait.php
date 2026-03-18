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

/**
 * Shared test helper methods for assignfeedback_aif tests.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait aif_test_helper {

    /**
     * Create a test environment with course, teacher, student and assign instance.
     *
     * @param array $assignparams Additional assign instance parameters.
     * @return \stdClass Environment object with course, teacher, student, assign, cm, context, assignobj.
     */
    private function create_test_environment(array $assignparams = []): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $defaults = [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_aif_enabled' => 1,
            'assignfeedback_aif_prompt' => 'Default test prompt',
        ];
        $params = array_merge($defaults, $assignparams);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $assign = $generator->create_instance($params);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $context = \core\context\module::instance($cm->id);
        $assignobj = new \mod_assign_testable_assign($context, $cm, $course);

        return (object) [
            'course' => $course,
            'teacher' => $teacher,
            'student' => $student,
            'assign' => $assign,
            'cm' => $cm,
            'context' => $context,
            'assignobj' => $assignobj,
        ];
    }

    /**
     * Create and submit a student submission with online text.
     *
     * @param \stdClass $env The test environment.
     * @param string $text The submission text.
     */
    private function create_and_submit(\stdClass $env, string $text = 'Test submission'): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $this->setUser($env->student);

        $submissiondata = [
            'cmid' => $env->cm->id,
            'userid' => $env->student->id,
            'onlinetext' => $text,
        ];
        $generator->create_submission($submissiondata);

        $sink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $env->student->id], []);
        $sink->close();
    }

    /**
     * Create an AIF configuration record for the assignment.
     *
     * Updates the existing config if one was already created by save_settings,
     * otherwise creates a new record.
     *
     * @param \stdClass $env The test environment.
     * @param string $prompt The prompt text.
     * @param int $autogenerate Whether to auto-generate feedback.
     * @return int The AIF config record ID.
     */
    private function create_aif_config(\stdClass $env, string $prompt = 'Test prompt', int $autogenerate = 0): int {
        global $DB;
        $clock = \core\di::get(\core\clock::class);

        // Update the existing config created by save_settings, or create a new one.
        $existing = $DB->get_record('assignfeedback_aif', ['assignment' => $env->assign->id]);
        if ($existing) {
            $existing->prompt = $prompt;
            $existing->autogenerate = $autogenerate;
            $DB->update_record('assignfeedback_aif', $existing);
            return $existing->id;
        }

        return $DB->insert_record('assignfeedback_aif', [
            'assignment' => $env->assign->id,
            'prompt' => $prompt,
            'autogenerate' => $autogenerate,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);
    }
}
