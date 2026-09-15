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

namespace assignfeedback_aif\local;

use assignfeedback_aif\aif;

/**
 * Applies AI-suggested rubric levels to the advanced grading form.
 *
 * When the per-assignment setting "Apply rubric grades" is enabled and the
 * assignment uses the rubric grading method, the prompt asks the model to
 * append a machine readable JSON block. This class:
 *
 * 1. Builds the prompt instructions listing the exact criteria and levels.
 * 2. Extracts and strips the JSON block from the returned feedback.
 * 3. Resolves criterion/level names to database ids. Any mismatch aborts
 *    the whole application (feedback-only fallback).
 * 4. Writes the filling through the advanced grading API and keeps the
 *    grade in the "In review" marking workflow state so a teacher confirms
 *    every grade before release.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 Jack
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rubric_grade_applier {
    /** @var string Key of the JSON object requested from the model. */
    public const JSON_KEY = 'rubric';

    /** @var string Markdown code fence delimiter. */
    private const FENCE = '```'; // phpcs:ignore Squiz.Strings.EchoedStrings, moodle.Strings.ForbiddenStrings

    /**
     * Load the active rubric criteria and levels for a grading context.
     *
     * @param int $contextid The module context id.
     * @return array Criteria keyed by id: ['description' => string, 'levels' => [levelid => ['definition', 'score']]].
     */
    public static function load_criteria(int $contextid): array {
        global $DB;

        $sql = "SELECT rc.id, rc.description, rc.sortorder
                  FROM {grading_areas} ga
                  JOIN {grading_definitions} gd ON gd.areaid = ga.id
                  JOIN {gradingform_rubric_criteria} rc ON rc.definitionid = gd.id
                 WHERE ga.contextid = :contextid
                   AND ga.activemethod = :method
                   AND ga.areaname = :areaname
              ORDER BY rc.sortorder ASC";
        $params = ['contextid' => $contextid, 'method' => aif::GRADING_METHOD_RUBRIC, 'areaname' => 'submissions'];

        $criteria = [];
        foreach ($DB->get_records_sql($sql, $params) as $criterion) {
            $levels = [];
            $records = $DB->get_records('gradingform_rubric_levels', ['criterionid' => $criterion->id], 'score ASC');
            foreach ($records as $level) {
                $levels[(int) $level->id] = [
                    'definition' => self::plain($level->definition),
                    'score' => (float) $level->score,
                ];
            }
            $criteria[(int) $criterion->id] = [
                'description' => self::plain($criterion->description),
                'levels' => $levels,
            ];
        }
        return $criteria;
    }

    /**
     * Build the prompt section asking the model for a structured JSON block.
     *
     * @param array $criteria Criteria as returned by {@see load_criteria()}.
     * @return string Prompt text, empty when there are no criteria.
     */
    public static function build_prompt_instructions(array $criteria): string {
        if (empty($criteria)) {
            return '';
        }

        $lines = [];
        foreach ($criteria as $criterion) {
            $levels = array_map(
                fn(array $level) => $level['definition'] . ' (' . self::format_score($level['score']) . ')',
                $criterion['levels']
            );
            $lines[] = '- "' . $criterion['description'] . '": ' . implode(' | ', $levels);
        }

        $example = json_encode([
            self::JSON_KEY => [
                [
                    'criterion' => '<exact criterion name>',
                    'level' => '<exact level definition>',
                    'score' => '<score of that level>',
                    'remark' => '<one or two sentences of justification>',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "\n\n=== STRUCTURED RUBRIC ASSESSMENT ===\n"
            . get_string('rubricjsoninstructions', 'assignfeedback_aif') . "\n"
            . implode("\n", $lines) . "\n\n"
            . self::FENCE . "json\n" . $example . "\n" . self::FENCE . "\n";
    }

    /**
     * Extract the structured rubric block from the model response.
     *
     * The block is removed from the feedback so students never see it.
     *
     * @param string $feedback The raw model response.
     * @return array ['feedback' => string, 'assessment' => array|null] where assessment is the list of criterion entries.
     */
    public static function extract(string $feedback): array {
        $assessment = null;
        // Prefer a fenced block (captured whole, not per-brace, so multi-criterion
        // JSON isn't truncated at the first "}" inside the array), fall back to a
        // bare JSON object containing the rubric key.
        $patterns = [
            '/' . self::FENCE . '(?:json)?\s*(.*?)\s*' . self::FENCE . '/is',
            '/(\{.*"' . self::JSON_KEY . '"\s*:\s*\[.*\]\s*\})/is',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $feedback, $matches)) {
                continue;
            }
            $decoded = json_decode(trim($matches[1]), true);
            if (!is_array($decoded) || !isset($decoded[self::JSON_KEY]) || !is_array($decoded[self::JSON_KEY])) {
                // Not the block we're looking for (or malformed); try the next pattern.
                continue;
            }
            $assessment = $decoded[self::JSON_KEY];
            // Strip the block (and a directly preceding heading) now that it decoded successfully.
            $feedback = str_replace($matches[0], '', $feedback);
            $feedback = preg_replace('/\n#{1,6}[^\n]*(rubric|assessment)[^\n]*\n\s*$/i', "\n", $feedback);
            break;
        }
        return ['feedback' => rtrim($feedback) . "\n", 'assessment' => $assessment];
    }

    /**
     * Resolve the model's assessment to rubric criterion and level ids.
     *
     * Every criterion must be matched exactly once and every level must be
     * identifiable, otherwise null is returned and no grade is applied.
     *
     * @param array $assessment List of ['criterion', 'level', 'score', 'remark'] entries.
     * @param array $criteria Criteria as returned by {@see load_criteria()}.
     * @return array|null Advanced grading form data ['criteria' => [id => ['levelid', 'remark', 'remarkformat']]].
     */
    public static function resolve(array $assessment, array $criteria): ?array {
        if (empty($criteria) || count($assessment) !== count($criteria)) {
            return null;
        }

        $filling = [];
        foreach ($assessment as $entry) {
            if (!is_array($entry) || !isset($entry['criterion'])) {
                return null;
            }
            $criterionid = self::match_criterion((string) $entry['criterion'], $criteria);
            if ($criterionid === null || isset($filling[$criterionid])) {
                return null;
            }
            $levelid = self::match_level($entry, $criteria[$criterionid]['levels']);
            if ($levelid === null) {
                return null;
            }
            $filling[$criterionid] = [
                'levelid' => $levelid,
                'remark' => isset($entry['remark']) ? trim((string) $entry['remark']) : '',
                'remarkformat' => FORMAT_PLAIN,
            ];
        }

        return ['criteria' => $filling];
    }

    /**
     * Apply the resolved filling to the user's grade via the advanced grading API.
     *
     * Only runs when marking workflow is enabled so the grade stays "In review".
     * Existing teacher gradings (workflow state beyond "In review") are never
     * overwritten.
     *
     * @param \assign $assign The assignment instance.
     * @param int $userid The graded student.
     * @param int $graderid The user recorded as grader (rater of the rubric instance).
     * @param array $formdata Advanced grading form data from {@see resolve()}.
     * @return string|null Null on success, otherwise a language string key describing why it was skipped.
     */
    public static function apply(\assign $assign, int $userid, int $graderid, array $formdata): ?string {
        if (empty($assign->get_instance()->markingworkflow)) {
            return 'rubricapplyskipped_noworkflow';
        }
        if ($assign->grading_disabled($userid)) {
            return 'rubricapplyskipped_gradingdisabled';
        }

        $gradingmanager = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
        if ($gradingmanager->get_active_method() !== aif::GRADING_METHOD_RUBRIC) {
            return 'rubricapplyskipped_norubric';
        }
        $controller = $gradingmanager->get_controller(aif::GRADING_METHOD_RUBRIC);
        if (!$controller->is_form_available()) {
            return 'rubricapplyskipped_norubric';
        }

        $grade = $assign->get_user_grade($userid, true);
        $protected = [
            ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW,
            ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE,
            ASSIGN_MARKING_WORKFLOW_STATE_RELEASED,
        ];
        $flags = $assign->get_user_flags($userid, false);
        if ($flags && in_array($flags->workflowstate, $protected, true)) {
            return 'rubricapplyskipped_alreadygraded';
        }

        $grademenu = make_grades_menu($assign->get_instance()->grade);
        $controller->set_grade_range($grademenu, $assign->get_instance()->grade > 0);

        $instance = $controller->get_or_create_instance(0, $graderid, $grade->id);
        $grade->grade = $instance->submit_and_get_grade($formdata, $grade->id);
        $grade->grader = $graderid;
        $assign->update_grade($grade);

        // The workflow state lives in the user flags; keep the grade "In review".
        $flags = $assign->get_user_flags($userid, true);
        $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW;
        $assign->update_user_flags($flags);

        return null;
    }

    /**
     * Find the criterion whose description matches the given name.
     *
     * @param string $name Criterion name from the model.
     * @param array $criteria Criteria as returned by {@see load_criteria()}.
     * @return int|null The criterion id or null.
     */
    private static function match_criterion(string $name, array $criteria): ?int {
        $needle = self::normalise($name);
        if ($needle === '') {
            return null;
        }
        foreach ($criteria as $id => $criterion) {
            if (self::normalise($criterion['description']) === $needle) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Find the level referenced by an assessment entry.
     *
     * Matches the level definition text first, then falls back to the score.
     * Both must be unambiguous.
     *
     * @param array $entry The assessment entry.
     * @param array $levels Levels of the matched criterion.
     * @return int|null The level id or null.
     */
    private static function match_level(array $entry, array $levels): ?int {
        if (isset($entry['level']) && is_scalar($entry['level'])) {
            $needle = self::normalise((string) $entry['level']);
            $found = [];
            foreach ($levels as $id => $level) {
                if ($needle !== '' && self::normalise($level['definition']) === $needle) {
                    $found[] = $id;
                }
            }
            if (count($found) === 1) {
                return $found[0];
            }
        }
        if (isset($entry['score']) && is_numeric($entry['score'])) {
            $score = (float) $entry['score'];
            $found = [];
            foreach ($levels as $id => $level) {
                if (abs($level['score'] - $score) < 0.0001) {
                    $found[] = $id;
                }
            }
            if (count($found) === 1) {
                return $found[0];
            }
        }
        return null;
    }

    /**
     * Normalise text for tolerant comparison (case, whitespace, punctuation).
     *
     * @param string $text The text.
     * @return string The normalised text.
     */
    private static function normalise(string $text): string {
        $text = \core_text::strtolower(self::plain($text));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Convert possibly HTML formatted rubric text to a single plain line.
     *
     * @param string|null $text The text.
     * @return string Plain text.
     */
    private static function plain(?string $text): string {
        $text = html_to_text($text ?? '', 0, false);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Format a level score for the prompt.
     *
     * @param float $score The score.
     * @return string The score without trailing zeros.
     */
    private static function format_score(float $score): string {
        return rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.');
    }
}
