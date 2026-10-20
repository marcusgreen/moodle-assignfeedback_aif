# Changelog

## Unreleased

### Fixed
- Privacy provider exports and deletes AI feedback for every assignment attempt, not only the
  latest. Earlier attempts' feedback was never exported, and deletion left it behind. (#33)

## 1.1.0 (2026-10-16)

### Added
- Opt-in application of the AI's rubric assessment to the advanced grading form. When an
  assignment uses rubric grading with marking workflow, the AI's chosen level and remark for
  each criterion are written into the rubric, and the grade stays "In review" until a teacher
  releases it. See `docs/rubric-grade-application.md`. (#19)
- Rubric level scores are included in the AI prompt, formatted with the site's decimal
  separator. (#26)

### Changed
- AI requests, content extraction and quota are attributed to a single acting user: the
  student for automatic generation, the teacher for manual or bulk generation. A pending task
  is requeued for the user who actually triggers generation. (MBS-10988)
- Disabled submission plugins are ignored in the AI prompt and the submission analysis.
  (MBS-10855)

### Fixed
- Method signatures of `get_editor_text()` and `set_editor_text()` for Moodle 5.3 and
  PHP 8.3. (#31)
- Duplicate feedback record cleanup. (MBS-10979)
- Queue message shown for single-submission grading. (MBS-10973)
- Error handling in the analysis confirmation dialog. (MBS-10988)

## 1.0.0 (2026-07-28)

- Refactored to use `local_ai_content` for content extraction and caching.
