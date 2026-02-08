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

### Metadata Declaration

The plugin declares it stores the following data in `assignfeedback_aif_feedback`:

| Field | Privacy String | Description |
|-------|---------------|-------------|
| `assignment` | `privacy:metadata:assignmentid` | The assignment ID |
| `aitext` | `privacy:metadata:aitext` | The AI-generated feedback text |

### Data Export

The `export_feedback_user_data()` method is currently a no-op because the assignment module's
core provider already handles the grade-level export flow. The plugin integrates via the
standard assign feedback provider interface.

### Data Deletion

Three deletion methods are implemented:

#### delete_feedback_for_context

Deletes all AI feedback for an entire assignment context (e.g., when a course is deleted).

```php
public static function delete_feedback_for_context(assign_plugin_request_data $requestdata) {
    $assign = $requestdata->get_assign();
    $plugin = $assign->get_plugin_by_type('assignfeedback', 'aif');
    $plugin->delete_instance();
}
```

#### delete_feedback_for_grade

Deletes AI feedback associated with a specific grade entry (e.g., when a user's data is purged).

Removes all `assignfeedback_aif_feedback` records linked to the assignment's
`assignfeedback_aif` record, then removes the config record itself.

#### delete_feedback_for_grades

Deletes AI feedback for multiple grade IDs at once (batch privacy deletion).

### Data Sent to AI Providers

The following data may be sent to external AI providers:

| Data | When | Purpose |
|------|------|---------|
| Submission text | Always | Generating feedback |
| File content (extracted text) | File submissions | Generating feedback |
| Image data (base64) | Image submissions | Image analysis feedback |
| Rubric criteria | Rubric grading | Context-aware feedback |
| Assignment name | Always | Context for the AI |
| Disclaimer text | Translation enabled | Translating disclaimer |

> **Important:** No personally identifiable information (student name, email, etc.) is sent
> to the AI provider. Only submission content, assignment metadata, and rubric criteria are
> included in the prompt.

---

## Plugin Constants

Defined in `locallib.php`:

```php
define('ASSIGNFEEDBACK_AIF_COMPONENT', 'assignfeedback_aif');
define('ASSIGNFEEDBACK_AIF_FILEAREA', 'feedback');
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
