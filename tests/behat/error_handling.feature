@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: AI feedback error handling
  As a teacher
  I want to see clear error messages when AI feedback generation fails
  So that I can understand what went wrong and take appropriate action

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
      | activity                            | assign                    |
      | course                              | C1                        |
      | name                                | Error Test Task           |
      | assignsubmission_onlinetext_enabled | 1                         |
      | assignfeedback_aif_enabled          | 1                         |
      | assignfeedback_aif_prompt           | Evaluate the submission   |
      | submissiondrafts                    | 0                         |

  @javascript
  Scenario: Error feedback record is created when AI service returns an error
    Given the AI feedback mock returns an error "API rate limit exceeded"
    And I am on the "Error Test Task" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | My essay about the importance of education reform. |
    And I press "Save changes"
    And I am on the "Error Test Task" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    Then AI feedback should have an error for "student1" in "Error Test Task"

  @javascript
  Scenario: AI feedback mock is unavailable and shows unavailability reason
    Given the AI feedback mock is unavailable with "No AI provider configured for this site"
    And I am on the "Error Test Task" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Essay about the challenges of distance learning during a pandemic. |
    And I press "Save changes"
    And I am on the "Error Test Task" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    Then AI feedback should have an error for "student1" in "Error Test Task"

  @javascript
  Scenario: Successful generation after previous error replaces the error record
    Given the AI feedback mock returns an error "Service temporarily unavailable"
    And I am on the "Error Test Task" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Essay about renewable energy technologies and their adoption barriers. |
    And I press "Save changes"
    And I am on the "Error Test Task" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    And AI feedback should have an error for "student1" in "Error Test Task"
    # Now retry with working AI.
    And the AI feedback mock is reset
    And the AI feedback mock returns "Your essay provides an excellent overview of renewable energy technologies."
    And I am on the "Error Test Task" "assign activity" page
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    Then AI feedback should exist for "student1" in "Error Test Task"
    And AI feedback should not have an error for "student1" in "Error Test Task"
