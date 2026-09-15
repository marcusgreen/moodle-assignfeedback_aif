# Rubric Grade Application

When an assignment is graded with a **rubric**, the AI already reasons about every criterion.
The opt-in setting **Apply the AI's rubric assessment to the grading form** removes the duplicate
work of retyping the suggested levels into the rubric by hand.

## Preconditions

| Requirement | Why |
|-------------|-----|
| Grading method "Rubric" with a ready definition | Only rubric criteria and levels can be resolved |
| Marking workflow enabled | The applied grade is kept in state **In review**; a teacher must confirm it before release |
| Setting enabled per assignment | The feature is strictly opt-in and off by default |

The checkbox is hidden in the assignment form while marking workflow is disabled.

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

## Safety rules

- Never runs without marking workflow.
- Never overwrites a grade whose workflow state is already "Ready for review", "Ready for
  release" or "Released".
- Skips users whose grade is locked or overridden in the gradebook.
- Exceptions from the grading API are caught and logged; the feedback text is always kept.

## Tests

```bash
vendor/bin/phpunit public/mod/assign/feedback/aif/tests/rubric_grade_applier_test.php
```
