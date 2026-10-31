# Changelog

## Unreleased

### Added
- Site-wide setting to enable applying rubric assessments. (#34)
- Grading page shows the AI's rubric assessment without a reload. (#34)

### Changed
- Requires Moodle 5.0. Supports 5.0 to 5.2. Marked stable.
- German strings removed; translations now come through AMOS.
- AI debug info goes to the task log, not the feedback.
- `feedback_utils::get_feedbackaif()` and `save_feedback()` take a submission ID. (#25)

### Fixed
- AI feedback for earlier attempts is kept and shown. Thanks to Richard Aljaste. (#24, #25)
- Privacy export and delete cover all attempts. (#33)
- Privacy metadata lists all stored AI data.
- Web service grading without AI feedback data works again. Thanks to Peter Mayer. (MBS-11605, #36)
- AI error messages are escaped before display.
- Students see the AI notice before they submit, with either backend, and only one notice
  is shown. Thanks to Richard Aljaste. (#14, #38)
- Default prompt tells the AI to write `\lt` and `\gt` in LaTeX, so `<` and `>` no longer
  cause MathJax "Misplaced &" errors. Thanks to Stefan Hanauska. (MBS-11586, #37)

## 1.1.0 (2026-10-16)

### Added
- Opt-in application of the AI's rubric assessment to the advanced grading form. When an
  assignment uses rubric grading with marking workflow, the AI's chosen level and remark for
  each criterion are written into the rubric, and the grade stays "In review" until a teacher
  releases it. See `docs/rubric-grade-application.md`. (#19)
- Rubric level scores are included in the AI prompt, formatted with the site's decimal
  separator. Thanks to Richard Aljaste. (#26)

### Changed
- AI requests, content extraction and quota are attributed to a single acting user: the
  student for automatic generation, the teacher for manual or bulk generation. A pending task
  is requeued for the user who actually triggers generation. Thanks to Philipp Memmel.
  (MBS-10988)
- Disabled submission plugins are ignored in the AI prompt and the submission analysis.
  Thanks to Fabian Barbuia, and thanks to Philipp Memmel. (MBS-10855)

### Fixed
- Method signatures of `get_editor_text()` and `set_editor_text()` for Moodle 5.3 and
  PHP 8.3. (#31)
- Duplicate feedback record cleanup. Thanks to Philipp Memmel. (MBS-10979)
- Queue message shown for single-submission grading. Thanks to Philipp Memmel. (MBS-10973)
- Error handling in the analysis confirmation dialog. Thanks to Philipp Memmel. (MBS-10988)

## 1.0.0 (2026-07-28)

- Refactored to use `local_ai_content` for content extraction and caching. Thanks to Andreas
  Wagner, and thanks to Philipp Memmel. (MBS-10886)
