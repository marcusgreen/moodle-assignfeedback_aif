@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: AI feedback edge cases and special scenarios
  As a developer
  I want edge cases to be handled correctly
  So that the plugin is robust under unusual conditions

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the AI feedback mock returns "Default mock response for edge case testing."

  @javascript
  Scenario: Batch generate skips students without submissions
    Given the following "activity" exists:
      | activity                            | assign                    |
      | course                              | C1                        |
      | name                                | Partial Submissions       |
      | assignsubmission_onlinetext_enabled | 1                         |
      | assignfeedback_aif_enabled          | 1                         |
      | assignfeedback_aif_prompt           | Evaluate the text         |
      | submissiondrafts                    | 0                         |
    # Only student1 submits, student2 does not.
    And I am on the "Partial Submissions" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Student 1 has submitted a thoughtful essay about urban planning. |
    And I press "Save changes"
    And I log out
    And I am on the "Partial Submissions" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    Then AI feedback should exist for "student1" in "Partial Submissions"
    And AI feedback should not exist for "student2" in "Partial Submissions"

  @javascript
  Scenario: Mock response can be changed between scenarios
    Given the AI feedback mock returns "First custom response"
    And the following "activity" exists:
      | activity                            | assign                    |
      | course                              | C1                        |
      | name                                | Mock Switch Test          |
      | assignsubmission_onlinetext_enabled | 1                         |
      | assignfeedback_aif_enabled          | 1                         |
      | assignfeedback_aif_prompt           | Evaluate the text         |
      | assignfeedback_aif_autogenerate     | 1                         |
      | submissiondrafts                    | 0                         |
    And I am on the "Mock Switch Test" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Essay for first mock response test. |
    And I press "Save changes"
    And I run all adhoc tasks
    Then AI feedback should exist for "student1" in "Mock Switch Test"

  @javascript
  Scenario: AI feedback plugin is disabled - no feedback column shown
    Given the following "activity" exists:
      | activity                            | assign                  |
      | course                              | C1                      |
      | name                                | No AIF Assignment       |
      | assignsubmission_onlinetext_enabled | 1                       |
      | assignfeedback_aif_enabled          | 0                       |
      | submissiondrafts                    | 0                       |
    And I am on the "No AIF Assignment" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Essay without AI feedback enabled. |
    And I press "Save changes"
    And I am on the "No AIF Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    Then "Generate AI feedback" "link" should not exist
