# Task System

All AI feedback generation happens asynchronously via Moodle's Task API. This document
explains the task architecture, execution flow, and event-driven triggers.

## Overview

The plugin uses three task classes and two event observers:

```
┌─────────────────────────────────────────────────────────────┐
│                       Triggers                               │
│                                                              │
│  Student submits ──► Event Observer ──► Adhoc Task           │
│  Teacher batch   ──► locallib.php  ──► Adhoc Task           │
│  Regenerate btn  ──► External API  ──► Adhoc Task           │
│  Cron            ──► Scheduled Task (process_feedback)       │
│  Cron            ──► Scheduled Task (process_feedback_rubric)│
└─────────────────────────────────┬───────────────────────────┘
                                  │
                                  ▼
                         ┌────────────────┐
                         │   Cron Engine   │
                         │  (every minute) │
                         └───────┬────────┘
                                 │
                    ┌────────────┼────────────┐
                    ▼            ▼            ▼
             ┌──────────┐ ┌──────────┐ ┌──────────┐
             │ Adhoc    │ │ Scheduled│ │ Scheduled│
             │ Tasks    │ │ Task 1   │ │ Task 2   │
             └──────────┘ └──────────┘ └──────────┘
```

## Scheduled Tasks

### process_feedback

**Class:** `\assignfeedback_aif\task\process_feedback`
**Schedule:** Every minute (`* * * * *`)
**Purpose:** Generate AI feedback for simple text submissions without rubric integration.

**Execution Flow:**

1. Query all submitted assignments that:
   - Have the AIF plugin configured (`assignfeedback_aif` record exists)
   - Have a latest submitted submission
   - Do **not** have existing AI feedback (`NOT EXISTS` check)
2. For each submission:
   - Create an `aif` instance with the correct module context
   - Build the prompt using the template (online text only, no rubric)
   - Skip submissions with no text content
   - Call `perform_request()` on the configured AI backend
   - Append the disclaimer
   - Insert feedback into `assignfeedback_aif_feedback`

**SQL Query (simplified):**

```sql
SELECT aif.id, aif.prompt, olt.onlinetext, sub.id, cx.id, a.id, a.name
FROM assign a
JOIN course_modules cm ON cm.instance = a.id
JOIN context cx ON cx.instanceid = cm.id AND cx.contextlevel = 70
JOIN assignfeedback_aif aif ON aif.assignment = cm.id
JOIN assign_submission sub ON sub.assignment = a.id
LEFT JOIN assignsubmission_onlinetext olt ON olt.assignment = a.id AND olt.submission = sub.id
WHERE sub.status = 'submitted'
  AND sub.latest = 1
  AND NOT EXISTS (
      SELECT 1 FROM assignfeedback_aif_feedback aiff
      WHERE aiff.aif = aif.id AND aiff.submission = sub.id
  )
```

---

### process_feedback_rubric

**Class:** `\assignfeedback_aif\task\process_feedback_rubric`
**Schedule:** Every minute (`* * * * *`)
**Purpose:** Generate AI feedback with rubric criteria included in the prompt.

**Execution Flow:**

1. Query all submitted assignments (same pattern as `process_feedback`).
2. For each submission:
   - Create an `aif` instance with the submission's module context
   - Call `get_prompt()` which automatically:
     - Fetches online text
     - Extracts file content and images
     - Retrieves rubric criteria from grading definitions
     - Builds the complete prompt from the template
   - Call `perform_request()` with the full prompt and options (including image data)
   - Append the disclaimer
   - Insert feedback into `assignfeedback_aif_feedback`

**Key Difference from `process_feedback`:**
- Uses `get_prompt()` instead of `build_prompt_from_template()` directly
- Includes rubric criteria
- Supports file and image submissions
- Uses per-submission context (not system context)

---

## Adhoc Task

### process_feedback_rubric_adhoc

**Class:** `\assignfeedback_aif\task\process_feedback_rubric_adhoc`
**Purpose:** On-demand feedback generation or deletion, triggered by user actions.

**Custom Data:**

```php
$task->set_custom_data([
    'assignment' => $assignmentid,  // assign.id
    'users'      => [$userid1, $userid2, ...],  // Array of user IDs
    'action'     => 'generate',     // 'generate' or 'delete'
]);
```

**Actions:**

| Action | Description |
|--------|-------------|
| `generate` | Generate AI feedback for each user in the list |
| `delete` | Delete existing AI feedback for each user in the list |

**Execution Flow (generate):**

1. For each user in the `users` array:
   - Fetch submission record via a JOIN query
   - Create an `aif` instance with the submission's context
   - Call `get_prompt()` to build the full prompt (with rubric + files + images)
   - Detect image presence → use `itt` purpose for image analysis
   - Call `perform_request()` with appropriate purpose and options
   - Append disclaimer
   - Insert feedback into database

**Execution Flow (delete):**

1. For each user in the `users` array:
   - Fetch submission record
   - Delete the `assignfeedback_aif_feedback` record matching the submission

**Triggered By:**

| Source | Action | Code Location |
|--------|--------|---------------|
| Batch "Generate AI feedback" | generate | `locallib.php::process_feedbackaif()` |
| Batch "Delete AI feedback" | delete | `locallib.php::process_feedbackaif()` |
| Regenerate button (AJAX) | generate | `external/regenerate_feedback.php::execute()` |
| Auto-generate on submission | generate | `event/observer.php::queue_feedback_generation()` |

**Deduplication:** The task is queued with `queue_adhoc_task($task, true)` — the second
parameter `true` means existing identical tasks will not be duplicated.

---

## Event Observers

### assessable_submitted

**Event:** `\mod_assign\event\assessable_submitted`
**Callback:** `\assignfeedback_aif\event\observer::assessable_submitted`

**Flow:**

1. Event fires when a student submits an assignment.
2. Observer retrieves the assignment instance.
3. Checks if `autogenerate` is enabled in the `assignfeedback_aif` config for this assignment.
4. Checks if the AIF feedback plugin is enabled for this assignment.
5. If both conditions are met, queues an adhoc task with `action = 'generate'`.

**Conditions for task queuing:**
- `assignfeedback_aif` record exists for the assignment's course module
- `autogenerate` field is `1`
- The `aif` feedback plugin is enabled in the assignment's plugin list

### submission_removed

**Event:** `\mod_assign\event\submission_removed`
**Callback:** `\assignfeedback_aif\event\observer::submission_removed`

**Flow:**

1. Event fires when a submission is removed.
2. Observer finds the `assignfeedback_aif` record for the assignment.
3. Deletes the `assignfeedback_aif_feedback` record matching the submission ID and AIF ID.

This ensures orphaned feedback records are cleaned up when submissions are removed.

---

## Event Registration

Events are registered in `db/events.php`:

```php
$observers = [
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => '\assignfeedback_aif\event\observer::assessable_submitted',
    ],
    [
        'eventname' => '\mod_assign\event\submission_removed',
        'callback'  => '\assignfeedback_aif\event\observer::submission_removed',
    ],
];
```

## Task Registration

Tasks are registered in `db/tasks.php`:

```php
$tasks = [
    [
        'classname'  => 'assignfeedback_aif\task\process_feedback',
        'blocking'   => 0,
        'minute'     => '*',
        'hour'       => '*',
        'day'        => '*',
        'month'      => '*',
        'dayofweek'  => '*',
    ],
    [
        'classname'  => 'assignfeedback_aif\task\process_feedback_rubric',
        'blocking'   => 0,
        'minute'     => '*',
        'hour'       => '*',
        'day'        => '*',
        'month'      => '*',
        'dayofweek'  => '*',
    ],
];
```

Both tasks run every minute and are non-blocking, allowing other tasks to execute concurrently.

## Task Overlap

The scheduled tasks and the adhoc task may both try to process the same submission. This is
handled by the `NOT EXISTS` check in the scheduled task queries — once the adhoc task has
created a feedback record, the scheduled tasks will skip that submission.

For the adhoc task's `generate` action, new feedback is always inserted (it does not check
for existing feedback). This is intentional: the adhoc task is used for regeneration scenarios
where replacing existing feedback is desired.

## Monitoring

### Task Logs

View task execution history and errors:

**Site Administration → Server → Task logs**

Filter by `assignfeedback_aif` to see only this plugin's task output.

### Scheduled Task Status

View scheduled task configuration and last run times:

**Site Administration → Server → Scheduled tasks**

Search for "AI feedback" to find both scheduled tasks.

### Cron Output

Tasks output progress via `mtrace()`:

```
AI feedback generated for submission 42
AI feedback generated for assignment 5 submission 43
Skipping submission 44: No text content.
Error processing submission 45: API rate limit exceeded
AI feedback deleted for assignment 5 submission 46
```
