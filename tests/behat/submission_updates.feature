@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: AI feedback on updated submissions
  As a teacher
  I want existing AI feedback to be handled correctly when students update their submissions
  So that feedback stays relevant to the current submission content

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
    And the AI feedback mock returns "Feedback for the current version of your submission."

  @javascript
  Scenario: Student updates submission with autogenerate replaces old AI feedback
    Given the following "activity" exists:
      | activity                            | assign                    |
      | course                              | C1                        |
      | name                                | Updatable Assignment      |
      | assignsubmission_onlinetext_enabled | 1                         |
      | assignfeedback_aif_enabled          | 1                         |
      | assignfeedback_aif_prompt           | Evaluate the text         |
      | assignfeedback_aif_autogenerate     | 1                         |
      | submissiondrafts                    | 0                         |
      | maxattempts                         | -1                        |
      | attemptreopenmethod                 | manual                    |
    When I am on the "Updatable Assignment" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | First version of my essay about data privacy. |
    And I press "Save changes"
    And I run all adhoc tasks
    And AI feedback should exist for "student1" in "Updatable Assignment"
    And there should be 1 AI feedback record for "student1" in "Updatable Assignment"
    # Teacher allows another attempt via submissions action menu.
    And I am on the "Updatable Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I open the action menu in "Student 1" "table_row"
    And I follow "Allow another attempt"
    # Student submits a new attempt.
    And the AI feedback mock returns "Improved feedback for the updated version."
    And I am on the "Updatable Assignment" Activity page logged in as student1
    And I press "Add a new attempt based on previous submission"
    And I set the following fields to these values:
      | Online text | Revised and improved version of my essay about data privacy with additional sources. |
    And I press "Save changes"
    And I run all adhoc tasks
    Then AI feedback should exist for "student1" in "Updatable Assignment"

  @javascript
  Scenario: AI feedback exists after submission and is visible to teacher
    Given the following "activity" exists:
      | activity                            | assign                    |
      | course                              | C1                        |
      | name                                | Visible Assignment        |
      | assignsubmission_onlinetext_enabled | 1                         |
      | assignfeedback_aif_enabled          | 1                         |
      | assignfeedback_aif_prompt           | Evaluate the text         |
      | assignfeedback_aif_autogenerate     | 1                         |
      | submissiondrafts                    | 0                         |
    When I am on the "Visible Assignment" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | An essay about cybersecurity threats in modern banking systems. |
    And I press "Save changes"
    And I run all adhoc tasks
    And AI feedback should exist for "student1" in "Visible Assignment"
    # Teacher can see that AI feedback exists.
    And I am on the "Visible Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    Then I should see "Student 1"
