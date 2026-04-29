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
