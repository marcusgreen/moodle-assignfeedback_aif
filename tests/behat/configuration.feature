@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: AI Feedback plugin configuration
  As a teacher
  I want to configure the AI Feedback plugin for my assignments
  So that I can control how AI feedback is generated for student submissions

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

  @javascript
  Scenario: Teacher creates an assignment with AI feedback enabled and verifies settings persist
    Given the following "activity" exists:
      | activity                            | assign                       |
      | course                              | C1                           |
      | name                                | AI Assignment                |
      | assignsubmission_onlinetext_enabled | 1                            |
      | assignfeedback_aif_enabled          | 1                            |
      | assignfeedback_aif_prompt           | Check spelling and grammar   |
      | submissiondrafts                    | 0                            |
    When I am on the "AI Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the field "assignfeedback_aif_enabled" matches value "1"
    And the field "assignfeedback_aif_prompt" matches value "Check spelling and grammar"

  @javascript
  Scenario: Teacher enables autogenerate option for AI feedback
    Given the following "activity" exists:
      | activity                            | assign                  |
      | course                              | C1                      |
      | name                                | Auto Assignment         |
      | assignsubmission_onlinetext_enabled | 1                       |
      | assignfeedback_aif_enabled          | 1                       |
      | assignfeedback_aif_prompt           | Evaluate the text       |
      | assignfeedback_aif_autogenerate     | 1                       |
      | submissiondrafts                    | 0                       |
    When I am on the "Auto Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the field "assignfeedback_aif_autogenerate" matches value "1"

  @javascript
  Scenario: Teacher modifies prompt on existing assignment
    Given the following "activity" exists:
      | activity                            | assign                  |
      | course                              | C1                      |
      | name                                | Existing Assignment     |
      | assignsubmission_onlinetext_enabled | 1                       |
      | assignfeedback_aif_enabled          | 1                       |
      | assignfeedback_aif_prompt           | Original prompt         |
      | submissiondrafts                    | 0                       |
    When I am on the "Existing Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "assignfeedback_aif_prompt" to "Updated prompt for better feedback"
    And I press "Save and return to course"
    And I am on the "Existing Assignment" "assign activity" page
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the field "assignfeedback_aif_prompt" matches value "Updated prompt for better feedback"

  @javascript
  Scenario: Teacher disables AI feedback and prompt is no longer visible
    Given the following "activity" exists:
      | activity                            | assign                  |
      | course                              | C1                      |
      | name                                | Toggle Assignment       |
      | assignsubmission_onlinetext_enabled | 1                       |
      | assignfeedback_aif_enabled          | 1                       |
      | assignfeedback_aif_prompt           | Some prompt             |
      | submissiondrafts                    | 0                       |
    When I am on the "Toggle Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "assignfeedback_aif_enabled" to "0"
    And I press "Save and return to course"
    And I am on the "Toggle Assignment" "assign activity" page
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the field "assignfeedback_aif_enabled" matches value "0"
