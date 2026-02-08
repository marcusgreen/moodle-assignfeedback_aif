# Prompt Template System

The prompt template system allows site administrators to define a structured template that
controls how AI requests are assembled. The template uses placeholders that are dynamically
replaced with actual data at feedback generation time.

## How It Works

When AI feedback is generated, the plugin:

1. Loads the prompt template from admin settings (`assignfeedback_aif/prompttemplate`).
2. Collects data: submission text, rubric criteria, teacher prompt, assignment name, language.
3. Replaces each `{{placeholder}}` with the corresponding value.
4. Sends the completed prompt to the configured AI backend.

## Placeholders

| Placeholder | Source | Description |
|-------------|--------|-------------|
| `{{submission}}` | Student submission | Online text + extracted file content. For image-only submissions, this becomes `[Image submission - see attached image]`. |
| `{{rubric}}` | Grading definition | Rubric criteria with level definitions, formatted as a bullet list. Empty string if no rubric is configured. |
| `{{prompt}}` | Assignment settings | The teacher's per-assignment prompt text entered in the assignment editing form. |
| `{{assignmentname}}` | Assignment record | The name/title of the assignment. |
| `{{language}}` | User preference | The user's current Moodle language name in English (e.g., "English", "German", "French"). |

## Default Template

The default template is designed as a structured prompt with clear sections:

```
=== ROLE ===
You are an experienced teacher providing constructive feedback on student submissions.

=== ASSIGNMENT ===
{{assignmentname}}

=== GRADING CRITERIA ===
{{rubric}}

=== TEACHER INSTRUCTIONS ===
{{prompt}}

=== STUDENT SUBMISSION ===
{{submission}}

=== OUTPUT INSTRUCTIONS ===
Provide detailed, constructive feedback that helps the student improve.
Focus on both strengths and areas for improvement.
Be encouraging but honest.

=== LANGUAGE ===
Respond in {{language}}.
```

## Custom Template Examples

### Minimal Template

A simple template that focuses on essentials:

```
Assignment: {{assignmentname}}

Teacher instructions: {{prompt}}

{{rubric}}

Student submission:
{{submission}}

Respond in {{language}}.
```

### Structured Feedback Template

Forces the AI to use a specific output format:

```
You are an experienced educator. Analyse the following student submission and provide
structured feedback.

Assignment: {{assignmentname}}

Grading rubric:
{{rubric}}

Teacher's focus areas: {{prompt}}

--- STUDENT SUBMISSION ---
{{submission}}
--- END SUBMISSION ---

Provide your feedback in the following format:

## Strengths
- List 2-3 specific strengths

## Areas for Improvement
- List 2-3 specific areas with actionable suggestions

## Summary
A brief overall assessment (2-3 sentences).

Write your response in {{language}}.
```

### Code Review Template

For programming assignments:

```
You are a senior software developer conducting a code review.

Assignment: {{assignmentname}}

Assessment criteria:
{{rubric}}

Specific instructions: {{prompt}}

--- CODE SUBMISSION ---
{{submission}}
--- END CODE ---

Review the code and provide feedback on:
1. **Correctness** — Does the code work as intended?
2. **Style** — Is the code clean and readable?
3. **Design** — Are data structures and algorithms appropriate?
4. **Edge Cases** — Are potential issues handled?

For each issue found, provide:
- The specific location in the code
- What the issue is
- A suggested fix

Respond in {{language}}.
```

### Scientific Writing Template

For lab reports and research papers:

```
You are a professor reviewing a scientific paper submission.

Paper title / Assignment: {{assignmentname}}

Evaluation criteria:
{{rubric}}

Focus areas: {{prompt}}

--- SUBMISSION ---
{{submission}}
--- END SUBMISSION ---

Evaluate the submission against these scientific writing standards:

1. **Abstract/Introduction** — Is the research question clearly stated?
2. **Methodology** — Is the approach well-described and reproducible?
3. **Results** — Are findings presented clearly with appropriate data?
4. **Discussion** — Are results interpreted correctly?
5. **References** — Are sources properly cited?
6. **Writing Quality** — Is the text clear, concise, and well-structured?

Be constructive and specific. Reference particular passages when possible.

Language: {{language}}
```

## Placeholder Substitution Details

### `{{submission}}`

The submission text is assembled from multiple sources:

1. **Online text** — Directly from `assignsubmission_onlinetext`.
2. **File submissions** — Text extracted via Moodle's document converter:
   - `.txt` files are read directly.
   - PDF, DOCX, ODT are converted to text (requires a document converter plugin).
   - Images (PNG, JPEG, WebP, GIF) are encoded as base64 and sent separately as image data.
3. If no text content is found but an image exists, the placeholder becomes
   `[Image submission - see attached image]`.

### `{{rubric}}`

When the assignment uses rubric grading, criteria are formatted as:

```
- Criterion Name: Level 1 Definition | Level 2 Definition | Level 3 Definition
- Another Criterion: Poor | Acceptable | Good | Excellent
```

Levels are ordered by score (ascending). If no rubric is configured, this placeholder
is replaced with an empty string.

The rubric data is extracted from Moodle's grading API tables:
- `grading_areas` — Identifies the rubric for this assignment context
- `grading_definitions` — The rubric definition
- `gradingform_rubric_criteria` — Individual criteria descriptions
- `gradingform_rubric_levels` — Level definitions and scores

### `{{prompt}}`

The per-assignment teacher prompt. This is the text entered in the "Prompt" textarea when
editing the assignment settings. Falls back to the site-wide default prompt if empty.

### `{{assignmentname}}`

The `name` field from the `assign` table. This gives the AI context about the assignment's
topic.

### `{{language}}`

The current user's language, resolved using Moodle's string manager:

```php
$sm = get_string_manager();
$languages = $sm->get_list_of_languages();
$currentlang = current_language();
```

This returns the full language name (e.g., "English", "Deutsch", "Français") rather than
the language code.

## Template Best Practices

### Do

- **Structure the template** with clear sections — AI models respond better to organised prompts.
- **Include the language placeholder** — Essential for multilingual sites.
- **Specify the output format** — Tell the AI how to structure its response.
- **Set a role** — "You are an experienced teacher..." guides the AI's tone and approach.
- **Keep the submission at the end** — Some AI models give more attention to the beginning and
  end of prompts.

### Don't

- **Don't repeat rubric criteria in the prompt** — They're already included via `{{rubric}}`.
- **Don't hardcode a language** — Use `{{language}}` for multilingual support.
- **Don't make the template too long** — Every token in the template costs AI processing time
  and money.
- **Don't include sensitive instructions** — Students may see the feedback; avoid internal
  grading notes.

## Technical Implementation

The template substitution happens in `aif::build_prompt_from_template()`:

```php
public function build_prompt_from_template(
    string $submissiontext,
    string $rubrictext,
    string $teacherprompt,
    string $assignmentname
): string {
    $template = get_config('assignfeedback_aif', 'prompttemplate');

    $replacements = [
        '{{submission}}' => $submissiontext,
        '{{rubric}}'     => $rubrictext,
        '{{prompt}}'     => $teacherprompt,
        '{{assignmentname}}' => $assignmentname,
        '{{language}}'   => $this->get_current_language_name(),
    ];

    return str_replace(
        array_keys($replacements),
        array_values($replacements),
        $template
    );
}
```

If no template is configured, the method falls back to a simple concatenation of the
submission text and teacher prompt.
