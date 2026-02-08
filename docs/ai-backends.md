# AI Backends

The plugin supports two AI backend systems. This document explains each backend, how to
configure it, and the differences between them.

## Backend Selection

The backend is configured site-wide in:

**Site Administration → Plugins → Activity modules → Assignment → Feedback plugins
→ AI Assisted Feedback → AI backend system**

All assignments on the site use the same backend. Changing the backend affects all future
AI requests.

---

## Core AI Subsystem (Default)

**Setting value:** `core_ai_subsystem`

The Moodle Core AI Subsystem was introduced in Moodle 4.5. It provides a standardised
interface for AI operations within Moodle core.

### Setup

1. Navigate to **Site Administration → AI → AI providers**.
2. Enable and configure at least one AI provider (e.g., OpenAI, Ollama).
3. Configure the provider's API key and model settings.
4. Enable the `generate_text` action for the provider.
5. In the plugin settings, select **Core AI Subsystem** as the backend.

### How It Works

The plugin uses the Core AI API via dependency injection:

```php
$manager = \core\di::get(\core_ai\manager::class);
$action = new \core_ai\aiactions\generate_text(
    contextid: $this->contextid,
    userid: $USER->id,
    prompttext: $prompt
);
$llmresponse = $manager->process_action($action);
$responsedata = $llmresponse->get_response_data();
$content = $responsedata['generatedcontent'];
```

### Advantages

- **No additional plugins required** — Built into Moodle 4.5+.
- **Consistent with Moodle core** — Uses the same AI framework as other core features.
- **Simple setup** — Just configure an AI provider in Site Admin.
- **Activity-level context** — Requests include the module context for proper tracking.

### Limitations

- **No usage quotas** — Cannot limit AI usage per user or role.
- **No purpose-based routing** — Cannot route different types of requests to different providers.
- **Limited provider options** — Only providers supported by Moodle core are available.

### Error Handling

The plugin checks for:
- `null` response data
- Missing `generatedcontent` key
- `null` content value

On failure, a `moodle_exception` with the error string `err_retrievingfeedback_checkconfig`
is thrown.

---

## local_ai_manager

**Setting value:** `local_ai_manager`

The [local_ai_manager](https://moodle.org/plugins/local_ai_manager) plugin is a comprehensive
AI management solution for Moodle.

### Setup

1. Install the `local_ai_manager` plugin.
2. Configure AI providers in the local_ai_manager settings.
3. Create purposes (e.g., `feedback`, `translate`, `itt`) and assign providers to them.
4. Configure quotas and role-based access as needed.
5. In the plugin settings, select **Local AI manager** as the backend.
6. Set the **AI purpose** to match your local_ai_manager purpose name (default: `feedback`).

### How It Works

The plugin creates a manager instance with the configured purpose:

```php
$purpose = get_config('assignfeedback_aif', 'purpose') ?: 'feedback';
$manager = new \local_ai_manager\manager($purpose);
$llmresponse = $manager->perform_request($prompt, 'assignfeedback_aif', $this->contextid);

if ($llmresponse->get_code() !== 200) {
    throw new \moodle_exception(
        'err_retrievingfeedback',
        'assignfeedback_aif',
        '',
        $llmresponse->get_errormessage(),
        $llmresponse->get_debuginfo()
    );
}
$content = $llmresponse->get_content();
```

### Advantages

- **Purpose-based routing** — Different AI providers for different tasks
  (feedback vs. translation vs. image analysis).
- **Usage quotas** — Limit AI usage per user, role, or purpose.
- **Role-based configuration** — Different AI access levels for different user roles.
- **Tenant support** — Multi-tenant AI configurations.
- **Detailed statistics** — Usage tracking and reporting.
- **Multiple providers** — Support for a wide range of AI providers.

### Limitations

- **Requires additional plugin** — Must install and maintain local_ai_manager.
- **More complex setup** — Purposes, providers, and quotas need configuration.
- **Not part of Moodle core** — Separate update cycle.

### Error Handling

The local_ai_manager returns a `prompt_response` object with structured error information:

| Method | Returns |
|--------|---------|
| `get_code()` | HTTP status code (200 = success) |
| `get_content()` | Response text on success |
| `get_errormessage()` | Human-readable error message |
| `get_debuginfo()` | Technical debug information |

---

## Backend Comparison

| Feature | Core AI Subsystem | local_ai_manager |
|---------|-------------------|------------------|
| **Moodle Version** | 4.5+ (built-in) | Any (plugin) |
| **Installation** | None needed | Requires plugin install |
| **Provider Config** | Site Admin → AI | Plugin settings |
| **Purpose Routing** | ✗ | ✓ |
| **Usage Quotas** | ✗ | ✓ (per user/role) |
| **Role-based Access** | ✗ | ✓ |
| **Multi-tenant** | ✗ | ✓ |
| **Usage Statistics** | Basic (via AI logs) | Detailed |
| **Image Analysis** | Provider-dependent | Via `itt` purpose |
| **Response Format** | Array with `generatedcontent` | `prompt_response` object |
| **Component Tracking** | Via context | Via component parameter |

## Purpose Mapping

When using local_ai_manager, the plugin uses different purposes for different operations:

| Operation | Purpose | Setting |
|-----------|---------|---------|
| Feedback generation | Configurable (default: `feedback`) | `assignfeedback_aif/purpose` |
| Disclaimer translation | `translate` | Hardcoded |
| Image analysis | `itt` (image-to-text) | Automatic when image detected |

Ensure all three purposes are configured in local_ai_manager with appropriate providers.

## Test Environment Behaviour

In test environments (PHPUnit and Behat), both backends are bypassed. The plugin returns
a static string `"AI Feedback"` without making any actual AI calls:

```php
if (defined('BEHAT_SITE_RUNNING') || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
    return "AI Feedback";
}
```

This ensures tests can run without an AI provider configured.

## Migration Between Backends

Switching backends is non-destructive:

1. Existing AI feedback is preserved (it's stored as plain text in the database).
2. Change the backend in admin settings.
3. All future AI requests use the new backend.
4. No data migration is needed.

> **Note:** If switching to local_ai_manager, ensure the required purposes are configured
> before generating new feedback, otherwise requests will fail.
