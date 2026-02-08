# Development Guide

This guide is for developers who want to contribute to, test, or extend the AI Assisted
Feedback plugin.

## Prerequisites

- Moodle 4.5+ development environment
- PHP 8.2+
- Node.js 22+ (for AMD module building)
- A running Moodle instance with the plugin installed

## Plugin Structure

```
mod/assign/feedback/aif/
├── locallib.php              # Main plugin class (assign_feedback_aif)
├── version.php               # Version, dependencies, maturity
├── settings.php              # Site admin settings
├── classes/
│   ├── aif.php               # AI handler & prompt builder
│   ├── event/observer.php    # Event callbacks
│   ├── external/regenerate_feedback.php  # Web service
│   ├── privacy/provider.php  # GDPR compliance
│   └── task/                 # Background tasks
├── db/                       # Database schema & registrations
├── lang/en/                  # Language strings
├── amd/src/                  # JavaScript source (ES6)
├── amd/build/                # Compiled AMD modules
├── tests/                    # PHPUnit & Behat tests
├── docs/                     # Documentation
└── styles.css                # Plugin CSS
```

## Setting Up for Development

### Docker-Based (Recommended)

If you are using the MBS Docker development environment:

```bash
# Start the environment
bindev/start.sh

# Run the database upgrade (after code changes)
bindev/upgrade.sh

# Purge caches (after config or string changes)
bindev/purge_caches.sh
```

### Standard Moodle Development

```bash
# Install the plugin
cd /path/to/moodle/mod/assign/feedback
git clone <repository-url> aif
cd /path/to/moodle
php admin/cli/upgrade.php
```

## Coding Standards

The plugin follows Moodle coding standards. All code must pass:

1. **Codechecker (phpcs)** — Moodle coding style
2. **Moodlecheck** — PHPDoc documentation standards

### Running Code Checks

```bash
# Docker environment
bindev/codechecker.sh public/mod/assign/feedback/aif
bindev/moodlecheck.sh public/mod/assign/feedback/aif

# Auto-fix formatting issues
bindev/codechecker_autofix.sh public/mod/assign/feedback/aif

# Standard Moodle
vendor/bin/phpcs --standard=moodle mod/assign/feedback/aif
```

### Key Style Rules

- **4 spaces** for indentation (no tabs)
- **No closing `?>`** PHP tag
- **PHPDoc on everything** — classes, methods, properties, constants
- **Type declarations** — Use PHP type hints on all method parameters and return types
- **Named parameters** in SQL — `:paramname` not `?`
- **Clock API** — Use `\core\di::get(\core\clock::class)` instead of `time()`
- **Language strings** — No hardcoded text; use `get_string()`
- **Single quotes** for string literals; double quotes only when embedding variables

## Testing

### PHPUnit

#### Existing Tests

| Test File | What It Tests |
|-----------|---------------|
| `tests/process_feedback_test.php` | Scheduled task execution, feedback generation |
| `tests/submission_test.php` | Plugin enabling, basic submission flow |

#### Running PHPUnit

```bash
# Docker environment
bindev/phpunit.sh public/mod/assign/feedback/aif/tests/process_feedback_test.php
bindev/phpunit.sh public/mod/assign/feedback/aif/tests/submission_test.php

# All plugin tests
bindev/phpunit.sh --filter assignfeedback_aif

# Standard Moodle
vendor/bin/phpunit --filter assignfeedback_aif
```

#### Writing Tests

Follow Moodle's testing patterns:

```php
namespace assignfeedback_aif;

/**
 * Tests for the my_feature class.
 *
 * @package    assignfeedback_aif
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_aif\my_class
 */
final class my_class_test extends \advanced_testcase {

    public function test_my_feature(): void {
        $this->resetAfterTest();

        // Use generators for test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // Use frozen clock for time-dependent tests.
        $clock = $this->mock_clock_with_frozen(
            new \DateTimeImmutable('2025-06-15 10:00:00')
        );

        // Test assertions...
        $this->assertNotEmpty($result);
    }
}
```

#### Mocking the AI Backend

In test environments, `aif::perform_request()` automatically returns `"AI Feedback"` without
making real AI calls. This is controlled by:

```php
if (defined('BEHAT_SITE_RUNNING') || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
    return "AI Feedback";
}
```

For more fine-grained control, you can mock the DI container:

```php
$mock = $this->createMock(\core_ai\manager::class);
$mock->method('process_action')->willReturn($mockedResponse);
\core\di::set(\core_ai\manager::class, $mock);
```

### Behat

#### Existing Scenarios

| Feature File | Scenario |
|-------------|----------|
| `tests/behat/feedback_aif.feature` | Teacher batch-generates AI feedback for a submission |

#### Running Behat

```bash
# Docker environment
bindev/behat.sh --tags=@assignfeedback_aif

# Standard Moodle
vendor/bin/behat --tags=@assignfeedback_aif
```

#### Available Tags

- `@assignfeedback_aif` — All plugin scenarios
- `@mod_assign` — All assignment scenarios (including this plugin)

#### Writing Behat Scenarios

```gherkin
@mod @mod_assign @assignfeedback @assignfeedback_aif @javascript
Feature: AI Feedback regeneration
  As a teacher
  I want to regenerate AI feedback
  So that I can get updated feedback for a student

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: Teacher regenerates AI feedback
    Given I log in as "teacher1"
    # ... test steps
```

## JavaScript Development

### AMD Module: regenerate.js

The regenerate button functionality is implemented as an AMD module.

**Source:** `amd/src/regenerate.js`
**Built:** `amd/build/regenerate.min.js`

#### Building AMD Modules

```bash
# From the plugin directory
cd mod/assign/feedback/aif
npx grunt amd --force

# Docker environment (outside container, from mbsmoodle/public)
cd mbsmoodle/public/mod/assign/feedback/aif && npx grunt amd --force

# Then purge caches
bindev/purge_caches.sh
```

#### Module Dependencies

| Module | Purpose |
|--------|---------|
| `core/ajax` | Call external web service functions |
| `core/notification` | Show success/error notifications |
| `core/str` | Load language strings asynchronously |
| `core/pending` | Behat compatibility (pending promise tracking) |

#### Key Pattern: Pending Promise

For Behat compatibility, async operations must use `core/pending`:

```javascript
import Pending from 'core/pending';

const pendingPromise = new Pending('assignfeedback_aif/regenerate');
// ... async operation ...
pendingPromise.resolve();
```

## Database Schema Changes

### Adding a New Field

1. Edit `db/install.xml` to add the field to the schema.
2. Create an upgrade step in `db/upgrade.php`:

```php
if ($oldversion < 2026020700) {
    $table = new \xmldb_table('assignfeedback_aif');
    $field = new \xmldb_field('newfield', XMLDB_TYPE_INTEGER, '4',
        null, XMLDB_NOTNULL, null, '0', 'autogenerate');

    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }

    upgrade_plugin_savepoint(true, 2026020700, 'assignfeedback', 'aif');
}
```

3. Increment the version number in `version.php`.
4. Run `bindev/upgrade.sh`.

### Checking Schema Consistency

```bash
bindev/check_db_schema.sh
```

## Adding New Language Strings

1. Add the string to `lang/en/assignfeedback_aif.php`:

```php
$string['newstring'] = 'My new string with {$a} placeholder';
```

2. Use it in PHP:

```php
$text = get_string('newstring', 'assignfeedback_aif', $value);
```

3. Use it in JavaScript:

```javascript
import {get_string} from 'core/str';
const text = await get_string('newstring', 'assignfeedback_aif');
```

4. Use it in Mustache templates:

```mustache
{{#str}} newstring, assignfeedback_aif {{/str}}
```

5. Purge caches: `bindev/purge_caches.sh`.

## Adding a New Admin Setting

1. Add the setting in `settings.php`:

```php
$settings->add(new admin_setting_configtext(
    'assignfeedback_aif/newsetting',
    get_string('newsetting', 'assignfeedback_aif'),
    get_string('newsetting_text', 'assignfeedback_aif'),
    'default_value',
    PARAM_ALPHANUMEXT
));
```

2. Add language strings (see above).
3. Use the setting:

```php
$value = get_config('assignfeedback_aif', 'newsetting');
```

## Adding a New External Function

1. Create the class in `classes/external/`:

```php
namespace assignfeedback_aif\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class my_function extends external_api {
    public static function execute_parameters(): external_function_parameters { ... }
    public static function execute(...): array { ... }
    public static function execute_returns(): external_single_structure { ... }
}
```

2. Register in `db/services.php`:

```php
$functions['assignfeedback_aif_my_function'] = [
    'classname'    => 'assignfeedback_aif\external\my_function',
    'description'  => 'Description of the function',
    'type'         => 'read', // or 'write'
    'ajax'         => true,
    'capabilities' => 'mod/assign:grade',
];
```

3. Increment version and run `bindev/upgrade.sh`.

## Release Checklist

Before releasing a new version:

1. ☐ All PHPUnit tests pass
2. ☐ All Behat tests pass
3. ☐ Codechecker clean (`bindev/codechecker.sh`)
4. ☐ Moodlecheck clean (`bindev/moodlecheck.sh`)
5. ☐ AMD modules built (`npx grunt amd --force`)
6. ☐ Version number incremented in `version.php`
7. ☐ Language strings reviewed
8. ☐ Documentation updated
9. ☐ Database upgrade steps tested
10. ☐ Privacy API up to date
