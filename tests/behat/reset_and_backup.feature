@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: Reset and backup/restore of AI feedback data
  In order to manage course life cycle
  As an admin
  I need AI feedback configuration to be removable via course reset and to survive backup/restore

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activity" exists:
      | activity                            | assign                 |
      | course                              | C1                     |
      | name                                | AIF Assignment         |
      | assignsubmission_onlinetext_enabled | 1                      |
      | assignfeedback_aif_enabled          | 1                      |
      | assignfeedback_aif_prompt           | Check grammar and tone |
    And the following config values are set as admin:
      | enableasyncbackup | 0 |

  Scenario: Resetting course submissions reports success for the assign module
    Given I log in as "admin"
    When I am on the "Course 1" "reset" page
    And I set the following fields to these values:
      | All submissions | 1 |
    And I press "Reset course"
    Then I should see "Continue"

  @javascript
  Scenario: AI feedback configuration survives course backup and restore
    Given I log in as "admin"
    When I backup "Course 1" course using this options:
      | Confirmation | Filename | test_backup.mbz |
    And I restore "test_backup.mbz" backup into a new course using this options:
      | Schema | Course name       | Restored Course 1 |
      | Schema | Course short name | RC1               |
    Then I should see "Restored Course 1"
    And I should see "AIF Assignment"
@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: Reset and backup/restore of AI feedback data
  In order to manage course life cycle
  As a teacher
  I need AI feedback configuration and per-submission feedback to survive backup/restore and be removable via course reset

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activity" exists:
      | activity                            | assign                 |
      | course                              | C1                     |
      | name                                | AIF Assignment         |
      | assignsubmission_onlinetext_enabled | 1                      |
      | assignfeedback_aif_enabled          | 1                      |
      | assignfeedback_aif_prompt           | Check grammar and tone |

  @javascript
  Scenario: Resetting course submissions removes AIF configuration
    Given I am on the "AIF Assignment" Activity page logged in as teacher1
    And I log out
    And I log in as "admin"
    When I am on "Course 1" course homepage
    And I navigate to "Course reuse" in current page administration
    And I select "Reset" from the "Course reuse" singleselect
    And I expand all fieldsets
    And I set the field "Delete all submissions" to "1"
    And I press "Reset course"
    And I press "Continue"
    Then I should see "Assignment submissions and feedback deleted"

  @javascript
  Scenario: AI feedback configuration survives course backup and restore
    Given I log in as "admin"
    And I backup "Course 1" course using this options:
      | Confirmation | Filename | test_backup.mbz |
    And I restore "test_backup.mbz" backup into a new course using this options:
      | Schema | Course name       | Restored Course 1 |
      | Schema | Course short name | RC1               |
    When I am on the "AIF Assignment" "assign activity editing" page
    And I expand all fieldsets
    Then the field "AI Prompt" matches value "Check grammar and tone"
    And the field "assignfeedback_aif_enabled" matches value "1"
