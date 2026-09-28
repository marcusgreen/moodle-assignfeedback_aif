# Rubric Grade Application

When an assignment is graded with a **rubric**, the AI already reasons about every criterion.
The opt-in setting **Apply the AI's rubric assessment to the grading form** writes the AI's
level and remark for each criterion directly into the assignment's rubric grading form.

## Preconditions

| Requirement | Why |
|-------------|-----|
| Grading method "Rubric" with a ready definition | Only rubric criteria and levels can be resolved |
| Marking workflow enabled | The applied grade is kept in state **In review**; a teacher must confirm it before release |
| Admin setting **Enable applying rubric assessments** | Site-wide switch, off by default. While off the per-assignment option is hidden and nothing is applied |
| Setting enabled per assignment | The feature is opt-in and off by default |

While marking workflow is disabled, the checkbox is shown greyed out. A tick that was already set is kept and becomes active again once marking workflow is enabled.

## How it works

1. **Prompt** — `aif::get_prompt()` appends a `=== STRUCTURED RUBRIC ASSESSMENT ===` section
   (built by `rubric_grade_applier::build_prompt_instructions()`). It lists every criterion with
   its level definitions and scores and asks for exactly one JSON code block:

   ```json
   {"rubric": [{"criterion": "...", "level": "...", "score": 2, "remark": "..."}]}
   ```

2. **Extract** — The adhoc task calls `rubric_grade_applier::extract()`. The JSON block (and a
   directly preceding "Rubric assessment" heading) is stripped from the stored feedback so
   students never see it.

3. **Resolve** — `rubric_grade_applier::resolve()` maps each entry to `criterionid` / `levelid`.
   Criterion names are compared case-insensitively ignoring punctuation. Levels are matched by
   definition text first, then by score. Every criterion must be matched exactly once; any
   mismatch aborts the whole application and only the text feedback is stored (logged with
   `mtrace`).

4. **Apply** — `rubric_grade_applier::apply()` uses the advanced grading API:
   `get_or_create_instance()`, `submit_and_get_grade()`, `assign::update_grade()` and sets the
   user flags to `ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW`. Because the grade is not released,
   `gradebook_item_update()` keeps it out of the gradebook.

The rater of the rubric instance is the teacher who triggered the generation. For automatic
generation on submission the site administrator is recorded as a neutral placeholder.

## Grading page refresh

On the grading page the "Generate AI feedback" button injects the new feedback into the editor
without reloading the page. Because the rubric assessment is written server-side, the rubric
widget would otherwise keep showing its old state until the next reload, and saving the form
would overwrite the AI assessment with the stale values.

`check_feedback_status` therefore returns the filling of the current grading instance
(criterion id, level id, remark) when all of the following hold:

- rubric application is enabled site-wide and for the assignment,
- the active grading method is a ready rubric,
- the instance was modified at or after the feedback text, i.e. by the same run.

The `assignfeedback_aif/rubricform` module then selects the matching level radios, mirrors the
`checked` state the rubric widget maintains, fills the remark fields and shows a toast. The
instance is the source of truth here, not the raw AI JSON, so the form shows exactly what was
stored after criterion and level matching. The automatic-generation spinner still reloads the
page, which picks up the rubric on its own.

## Safety rules

- Never runs without marking workflow.
- Never overwrites a grade whose workflow state is already "Ready for review", "Ready for
  release" or "Released". Grades in the states "Not marked", "In marking" and "In review" are
  overwritten and moved to "In review".
- Skips users whose grade is locked or overridden in the gradebook.
- Exceptions from the grading API are caught and logged; the feedback text is always kept.

## Tests

```bash
vendor/bin/phpunit public/mod/assign/feedback/aif/tests/rubric_grade_applier_test.php
```
