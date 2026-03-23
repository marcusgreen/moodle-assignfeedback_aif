# Architecture Overview

## Component Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        assignfeedback_aif                               │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌──────────────┐    ┌────────────────┐    ┌──────────────────────┐    │
│  │ locallib.php  │    │  settings.php  │    │  amd/src/            │    │
│  │ (Plugin Core) │    │  (Admin UI)    │    │  regenerate.js       │    │
│  └──────┬───────┘    └────────────────┘    │  (AJAX Regenerate)   │    │
│         │                                   └──────────┬───────────┘    │
│         │                                              │                │
│  ┌──────▼───────────────────────────────────────────────▼──────────┐    │
│  │                      classes/aif.php                             │    │
│  │              (AI Request Handler & Prompt Builder)               │    │
│  │                                                                  │    │
│  │  ┌─────────────────┐  ┌──────────────────┐  ┌───────────────┐  │    │
│  │  │ perform_request  │  │ build_prompt_    │  │ append_       │  │    │
│  │  │ ()               │  │ from_template()  │  │ disclaimer()  │  │    │
│  │  └────────┬─────────┘  └──────────────────┘  └───────────────┘  │    │
│  │           │                                                      │    │
│  │     ┌─────┴──────────┐                                          │    │
│  │     ▼                ▼                                          │    │
│  │ ┌────────────┐  ┌──────────────┐                                │    │
│  │ │ core_ai    │  │ local_ai_    │                                │    │
│  │ │ subsystem  │  │ manager      │                                │    │
│  │ └────────────┘  └──────────────┘                                │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                         │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                     Task System                                  │   │
│  │  ┌──────────────────┐  ┌────────────────────┐  ┌────────────┐  │   │
│  │  │ process_feedback  │  │ process_feedback_  │  │ process_   │  │   │
│  │  │ (scheduled)       │  │ rubric (scheduled) │  │ rubric_    │  │   │
│  │  │                   │  │                    │  │ adhoc      │  │   │
│  │  └──────────────────┘  └────────────────────┘  └────────────┘  │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                         │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                     Event System                                 │   │
│  │  ┌──────────────────────────────────────────────────────────┐   │   │
│  │  │ observer.php                                              │   │   │
│  │  │  • assessable_submitted → queue auto-generate task        │   │   │
│  │  │  • submission_removed   → delete associated feedback      │   │   │
│  │  └──────────────────────────────────────────────────────────┘   │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                         │
│  ┌──────────────────────┐  ┌────────────────┐  ┌───────────────────┐   │
│  │ external/             │  │ privacy/        │  │ db/               │   │
│  │ regenerate_feedback   │  │ provider.php    │  │ install.xml       │   │
│  │ (Web Service)         │  │ (GDPR)          │  │ services.php etc. │   │
│  └──────────────────────┘  └────────────────┘  └───────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
```

## Class Structure

### `assign_feedback_aif` (locallib.php)

The main plugin class extending `assign_feedback_plugin`. This is the entry point for all
Moodle assignment integration.

**Responsibilities:**
- Render assignment settings form (prompt, autogenerate checkbox)
- Render grading form (feedback editor, regenerate button)
- Save/load feedback data
- Provide batch grading operations
- Format feedback for display (summary, full view, gradebook)
- Handle plugin deletion and cleanup

**Key Methods:**

| Method | Purpose |
|--------|---------|
| `get_settings()` | Adds prompt textarea, autogenerate checkbox, and file manager to the assignment settings form |
| `get_form_elements_for_user()` | Renders the feedback editor and regenerate button on the grading page |
| `save_settings()` | Persists assignment-level prompt and autogenerate config to `assignfeedback_aif` table |
| `save()` | Saves teacher-edited feedback to `assignfeedback_aif_feedback` table |
| `get_grading_batch_operation_details()` | Registers "Generate AI feedback" and "Delete AI feedback" batch operations |
| `grading_batch_operation()` | Dispatches batch actions to `process_feedbackaif()` |
| `process_feedbackaif()` | Queues an adhoc task for batch generate/delete operations |
| `get_feedbackaif()` | Complex 5-table JOIN query to retrieve feedback for a user's latest submission |
| `view_summary()` / `view()` | Format and return feedback text for display |
| `is_feedback_modified()` | Compare editor content with stored feedback to detect changes |
| `delete_instance()` | Clean up all plugin data when assignment is deleted |
| `get_editor_text()` / `set_editor_text()` | Import/export support for feedback text |

### `aif` (classes/aif.php)

The AI request handler and prompt builder. Manages all communication with AI backends and
constructs prompts from templates.

**Responsibilities:**
- Dispatch AI requests to the configured backend
- Build prompts from templates with placeholder substitution
- Manage disclaimer text (append + translate)
- Extract content from submission files and images
- Extract rubric criteria from grading definitions

**Key Methods:**

| Method | Purpose |
|--------|---------|
| `perform_request()` | Routes the AI request to the configured backend (core_ai or local_ai_manager) |
| `build_prompt_from_template()` | Replaces `{{placeholders}}` in the template with actual values |
| `get_prompt()` | Assembles the complete prompt from submission data, rubric, and template |
| `append_disclaimer()` | Appends a (optionally translated) disclaimer to AI feedback |
| `translate_text()` | Translates text using the AI backend with caching |
| `get_rubric_text()` | Extracts rubric criteria and level definitions from grading areas |
| `extract_content_from_files()` | Extracts text (via converter) and images (base64) from file submissions |
| `get_current_language_name()` | Returns the user's current language name using Moodle's string manager |

### `observer` (classes/event/observer.php)

Event observer that reacts to assignment submission events.

| Event | Action |
|-------|--------|
| `assessable_submitted` | Checks if autogenerate is enabled; if yes, queues an adhoc task |
| `submission_removed` | Deletes associated AI feedback records |

### Task Classes

| Class | Type | Purpose |
|-------|------|---------|
| `process_feedback` | Scheduled | Processes unprocessed submissions (simple text, no rubric) |
| `process_feedback_rubric` | Scheduled | Processes unprocessed submissions (with rubric support) |
| `process_feedback_rubric_adhoc` | Adhoc | On-demand generation/deletion (batch + single regenerate) |

### `regenerate_feedback` (classes/external/regenerate_feedback.php)

External API function for AJAX-based feedback regeneration. Called by the `regenerate.js`
AMD module from the grading form.

### `provider` (classes/privacy/provider.php)

GDPR Privacy API implementation. Handles data export and deletion for compliance with
data protection regulations.

## Database Schema

### Table: `assignfeedback_aif`

Stores the per-assignment configuration for the AI feedback plugin.

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT(10) | Primary key (auto-increment) |
| `assignment` | INT(10) | Foreign key → `course_modules.id` |
| `prompt` | TEXT | Teacher's custom prompt for this assignment |
| `autogenerate` | INT(1) | Whether to auto-generate on submission (0/1) |
| `timecreated` | INT(10) | Unix timestamp of record creation |

### Table: `assignfeedback_aif_feedback`

Stores the generated AI feedback per individual submission.

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT(10) | Primary key (auto-increment) |
| `aif` | INT(10) | Foreign key → `assignfeedback_aif.id` |
| `feedback` | TEXT | The AI-generated feedback text |
| `feedbackformat` | INT(2) | Moodle text format (default: FORMAT_HTML = 1) |
| `timecreated` | INT(10) | Unix timestamp of feedback generation |
| `submission` | INT(10) | Foreign key → `assign_submission.id` |

### Entity Relationship

```
assign (Moodle Core)
  │
  ├──► course_modules
  │        │
  │        └──► assignfeedback_aif (1 per assignment)
  │                  │
  │                  └──► assignfeedback_aif_feedback (1 per submission)
  │                            │
  └──► assign_submission ◄─────┘
```

## Data Flow

### Feedback Generation Flow

```
1. Trigger (one of):
   ├── Student submits → Event observer → Queue adhoc task
   ├── Teacher batch "Generate" → Queue adhoc task
   └── Teacher "Regenerate" button → AJAX → External API → Queue adhoc task

2. Cron picks up adhoc task
   └── process_feedback_rubric_adhoc::execute()

3. For each user in task:
   ├── Fetch submission record (5-table JOIN)
   ├── Build prompt:
   │   ├── Get online text from submission
   │   ├── Extract text/images from file submissions
   │   ├── Get rubric criteria (if rubric grading)
   │   ├── Get teacher's custom prompt
   │   └── Apply prompt template with placeholder substitution
   ├── Send to AI backend:
   │   ├── core_ai_subsystem → core_ai\manager::process_action()
   │   └── local_ai_manager → local_ai_manager\manager::perform_request()
   ├── Append disclaimer (with optional translation)
   └── Store feedback in assignfeedback_aif_feedback table

4. Teacher views feedback in grading interface
   └── Can edit before saving grade
```

### Scheduled Task Flow

```
Cron runs every minute
  │
  ├── process_feedback (scheduled)
  │   └── Finds submitted assignments WITHOUT existing AI feedback
  │       └── Generates feedback for simple text submissions
  │
  └── process_feedback_rubric (scheduled)
      └── Finds submitted assignments WITHOUT existing AI feedback
          └── Generates feedback with rubric criteria included
```

## File Structure

```
mod/assign/feedback/aif/
├── locallib.php                          # Main plugin class
├── version.php                           # Plugin metadata
├── settings.php                          # Admin settings page
├── README.md                             # Plugin documentation
├── classes/
│   ├── aif.php                           # AI request handler & prompt builder
│   ├── event/
│   │   └── observer.php                  # Event observer (submit/remove)
│   ├── external/
│   │   └── regenerate_feedback.php       # External API for AJAX regeneration
│   ├── privacy/
│   │   └── provider.php                  # GDPR Privacy API
│   └── task/
│       ├── process_feedback.php          # Scheduled: simple text feedback
│       ├── process_feedback_rubric.php   # Scheduled: rubric-aware feedback
│       └── process_feedback_rubric_adhoc.php  # Adhoc: on-demand generate/delete
├── db/
│   ├── install.xml                       # Database schema (XMLDB)
│   ├── install.php                       # Post-install hook
│   ├── upgrade.php                       # Database upgrade steps
│   ├── access.php                        # Capabilities (none custom)
│   ├── events.php                        # Event observer registration
│   ├── services.php                      # External function registration
│   ├── tasks.php                         # Scheduled task registration
│   └── caches.php                        # Cache definitions
├── lang/
│   └── en/
│       └── assignfeedback_aif.php        # English language strings
├── amd/
│   ├── src/
│   │   └── regenerate.js                 # ES6 source: regenerate button
│   └── build/
│       ├── regenerate.min.js             # Compiled AMD module
│       └── regenerate.min.js.map         # Source map
├── templates/                            # Mustache templates (if any)
├── tests/
│   ├── process_feedback_test.php         # PHPUnit: scheduled task test
│   ├── submission_test.php               # PHPUnit: plugin enable test
│   └── behat/
│       └── feedback_aif.feature          # Behat: batch generation scenario
├── docs/
│   ├── architecture.md                   # This file
│   ├── admin-configuration.md            # Admin settings guide
│   ├── teacher-guide.md                  # Teacher usage guide
│   ├── prompt-template-system.md         # Prompt customization
│   ├── ai-backends.md                    # Backend comparison & setup
│   ├── task-system.md                    # Background processing
│   ├── api-reference.md                  # External API & events
│   ├── development.md                    # Developer guide
│   ├── FEATURE_DOKUMENTATION.md          # Legacy: German feature docs
│   ├── ANALYSE_LOCAL_AI_MANAGER_INTEGRATION.md  # Legacy: Integration analysis
│   └── images/
│       ├── assign_feedback_aif.png       # Screenshot: assignment editing
│       ├── assign_feedback_aif_settings.png  # Screenshot: admin settings
│       └── bulk_update.png               # Screenshot: batch operations
└── styles.css                            # Plugin CSS
```

## Technology Stack

| Component | Technology |
|-----------|-----------|
| Backend Language | PHP 8.2+ |
| Frontend | AMD/ES6 JavaScript |
| AI Integration | Moodle Core AI API (PSR-compatible) |
| Task Processing | Moodle Task API (scheduled + adhoc) |
| Caching | Moodle Cache API (application mode) |
| Database | XMLDB (MySQL, PostgreSQL, MariaDB, MSSQL) |
| Events | Moodle Event System (PSR-14 compatible) |
| Privacy | Moodle Privacy API (GDPR) |
| Web Services | Moodle External API |
