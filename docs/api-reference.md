# API Reference

This document covers the external web services, event system, cache definitions, and
privacy API implementation of the AI Assisted Feedback plugin.

## External API (Web Services)

### assignfeedback_aif_regenerate_feedback

Regenerates AI feedback for a single student's submission via AJAX.

**Class:** `\assignfeedback_aif\external\regenerate_feedback`
**Type:** `write`
**AJAX:** `true`
**Required capability:** `mod/assign:grade`

#### Parameters

| Name | Type | Description |
|------|------|-------------|
| `assignmentid` | INT | The assignment instance ID (`assign.id`) |
| `userid` | INT | The user ID to regenerate feedback for |

#### Return Value

```json
{
    "success": true,
    "message": "AI feedback regeneration has been queued. Please wait for the background task to complete."
}
```

| Field | Type | Description |
|-------|------|-------------|
| `success` | BOOL | Whether the task was queued successfully |
| `message` | TEXT | Status message for the user |

#### Security

1. Parameters are validated via `self::validate_parameters()`.
2. The assignment record and course module are loaded.
3. Context is validated via `self::validate_context()`.
4. `mod/assign:grade` capability is required in the module context.

#### Registration (db/services.php)

```php
$functions = [
    'assignfeedback_aif_regenerate_feedback' => [
        'classname'    => 'assignfeedback_aif\external\regenerate_feedback',
        'description'  => 'Regenerate AI feedback for a single submission',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/assign:grade',
    ],
];
```

#### JavaScript Client (AMD)

The external function is called from `amd/src/regenerate.js`:

```javascript
import Ajax from 'core/ajax';

const result = await Ajax.call([{
    methodname: 'assignfeedback_aif_regenerate_feedback',
    args: {
        assignmentid: assignmentId,
        userid: userId,
    },
}])[0];
```

---

## Event System

### Observed Events

The plugin observes two core assignment events. Registration is in `db/events.php`.

#### assessable_submitted

| Property | Value |
|----------|-------|
| Event class | `\mod_assign\event\assessable_submitted` |
| Callback | `\assignfeedback_aif\event\observer::assessable_submitted` |
| Purpose | Auto-generate feedback on submission |

**Behaviour:**
- Checks if the assignment has the AIF plugin enabled with `autogenerate = 1`.
- If yes, queues a `process_feedback_rubric_adhoc` task with `action = 'generate'`.
- If the plugin is not enabled or autogenerate is off, the event is silently ignored.

#### submission_removed

| Property | Value |
|----------|-------|
| Event class | `\mod_assign\event\submission_removed` |
| Callback | `\assignfeedback_aif\event\observer::submission_removed` |
| Purpose | Clean up feedback when submission is deleted |

**Behaviour:**
- Looks up the `assignfeedback_aif` record for the assignment.
- Deletes any `assignfeedback_aif_feedback` record matching the submission ID.

---

## Cache Definitions

### translations

**Component:** `assignfeedback_aif`
**Definition name:** `translations`

Stores AI-translated disclaimer texts to avoid repeated translation requests.

| Property | Value |
|----------|-------|
| Mode | `cache_store::MODE_APPLICATION` |
| Simple keys | `true` |
| Simple data | `true` |
| Static acceleration | `true` |
| Static acceleration size | 100 |

**Key format:** `lang_<languagecode>` (e.g., `lang_de`, `lang_fr`, `lang_es`)
**Value:** The translated disclaimer text string.

#### Registration (db/caches.php)

```php
$definitions = [
    'translations' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
    ],
];
```

#### Usage

```php
$cache = \cache::make('assignfeedback_aif', 'translations');
$key = 'lang_' . $currentlang;
$translated = $cache->get($key);

if ($translated === false) {
    // Translate via AI and store in cache.
    $translated = $this->translate_text($disclaimer, $languagename);
    $cache->set($key, $translated);
}
```

**Cache Invalidation:**
- Purge via *Site Administration → Development → Purge all caches*.
- Or programmatically: `cache_helper::purge_by_definition('assignfeedback_aif', 'translations')`.

---

## Privacy API (GDPR)

### Provider Class

**Class:** `\assignfeedback_aif\privacy\provider`

Implements:
- `\core_privacy\local\metadata\provider` — Declares stored metadata
- `\mod_assign\privacy\assignfeedback_provider` — Assignment-specific data handling
- `\mod_assign\privacy\assignfeedback_user_provider` — User-specific data operations

### Data Stored

AI feedback is stored per submission attempt in `assignfeedback_aif_feedback`: the feedback
text, its processing status (pending, completed, error), any error message, and timestamps.

When the apply-rubric-grades option is enabled, the AI's rubric assessment is written into the
assignment's rubric grading form. The plugin declares a subsystem link to `core_grading`
(`privacy:metadata:core_grading`); that data is exported and deleted through `mod_assign` and
`core_grading`, like any rubric filled in by a teacher.

### Data Export

`export_feedback_user_data()` exports the AI feedback for the attempt each grade belongs to,
under an "AI Feedback" subpath of the assignment context. Because `mod_assign` calls it once per
grade, feedback for every attempt is exported, not only the latest.

### Data Deletion

| Method | Deletes |
|--------|---------|
| `delete_feedback_for_context` | All AI feedback for the assignment (e.g. when a course is deleted) |
| `delete_feedback_for_grade` | AI feedback for the attempt the grade belongs to |
| `delete_feedback_for_grades` | AI feedback for every attempt of the users whose grades are being deleted |

### Data Sent to AI Providers

To generate feedback, the plugin sends a prompt to the configured AI backend:

| Data | When | Purpose |
|------|------|---------|
| Submission text | Always | Generating feedback |
| File content (extracted text) | File submissions | Generating feedback |
| Image data (base64) | Image submissions | Image analysis feedback |
| Rubric criteria and levels | Rubric grading | Context-aware feedback |
| Teacher's prompt | Always | Instructions for the AI |
| Assignment name | Always | Context for the AI |
| User's language name | Always | Response language |
| Disclaimer text | Translation enabled | Translating disclaimer |

Student names, email addresses and other profile fields are not included in the prompt. The
submission itself may still contain personal data.

### AI Request Logs

Each request is made on behalf of a user: the student for automatic generation on submission,
the teacher for manual or bulk generation. The backend logs the request against that user:

- **local_ai_manager**: requests and responses are stored in `local_ai_manager_request_log`
  with `component = 'assignfeedback_aif'`. The plugin declares this table in its metadata
  (`privacy:metadata:local_ai_manager_request_log`). Export and deletion are handled by
  local_ai_manager's own privacy provider, which covers entries from all components.
- **Core AI subsystem**: the plugin declares a subsystem link to `core_ai`
  (`privacy:metadata:core_ai`). Requests and responses are stored and handled by `core_ai`.

The AI provider itself (for example OpenAI or a self-hosted model) is outside Moodle. Site
administrators should check its data processing terms before enabling the plugin.

---

## Plugin Constants

Defined in `locallib.php`:

```php
define('ASSIGNFEEDBACK_AIF_COMPONENT', 'assignfeedback_aif');
define('ASSIGNFEEDBACK_AIF_FILEAREA', 'assignfeedback_aif_feedback');
```

These are used for file storage operations (editor files).

---

## Database Queries

### get_feedbackaif (5-Table JOIN)

The main query for retrieving feedback, used throughout the plugin:

```sql
SELECT aiff.*
FROM {assign} a
JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
JOIN {assignfeedback_aif} aif ON aif.assignment = cm.id
JOIN {assignfeedback_aif_feedback} aiff ON aiff.aif = aif.id
JOIN {assign_submission} sub ON sub.assignment = a.id AND aiff.submission = sub.id
WHERE a.id = :assignment
  AND sub.userid = :userid
  AND sub.latest = 1
ORDER BY aiff.id
```

This query joins through:
1. `assign` — The assignment instance
2. `course_modules` — To map `assign.id` to `course_modules.id` (since `assignfeedback_aif.assignment` references `course_modules.id`)
3. `assignfeedback_aif` — The plugin configuration
4. `assignfeedback_aif_feedback` — The actual feedback
5. `assign_submission` — To filter by user and latest submission

### Submission Record Query (Adhoc Task)

Used by `process_feedback_rubric_adhoc` to fetch submission data:

```sql
SELECT sub.id AS subid, cx.id AS contextid, aif.id AS aifid,
       aif.prompt AS prompt, a.id AS aid, a.name AS assignmentname, sub.userid
FROM {assign} a
JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
JOIN {context} cx ON cx.instanceid = cm.id
JOIN {assignfeedback_aif} aif ON aif.assignment = cm.id
JOIN {assign_submission} sub ON sub.assignment = a.id
WHERE sub.status = 'submitted'
  AND cx.contextlevel = 70
  AND a.id = :aid
  AND sub.userid = :userid
  AND sub.latest = 1
```

---

## Import/Export Support

The plugin implements `get_editor_fields()`, `get_editor_text()`, and `set_editor_text()`
for integration with Moodle's assignment import/export features:

| Method | Purpose |
|--------|---------|
| `get_editor_fields()` | Returns `['aif' => 'AI Assisted Feedback']` as an importable field |
| `get_editor_text('aif', $gradeid)` | Returns the stored feedback text for a grade |
| `set_editor_text('aif', $value, $gradeid)` | Updates the feedback text for a grade |

This allows AI feedback to be included in assignment grading worksheets (download/upload).
