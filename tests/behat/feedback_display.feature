@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: AI feedback viewing and display for students and teachers
  As a student or teacher
  I want to view AI-generated feedback in the appropriate context
  So that students get useful feedback and teachers can review it

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
  Scenario: Teacher views AI feedback in the grading table overview
    Given the following "activity" exists:
      | activity                            | assign                 |
      | course                              | C1                     |
      | name                                | Graded Essay           |
      | assignsubmission_onlinetext_enabled | 1                      |
      | assignfeedback_aif_enabled          | 1                      |
      | assignfeedback_aif_prompt           | Evaluate the text      |
      | assignfeedback_aif_autogenerate     | 1                      |
      | submissiondrafts                    | 0                      |
    And the AI feedback mock returns "Excellent analysis. Your writing style is clear and well-structured."
    And I am on the "Graded Essay" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Analysis of economic factors in climate policy implementation. |
    And I press "Save changes"
    And I run all adhoc tasks
    When I am on the "Graded Essay" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    Then I should see "Excellent analysis" in the "Student 1" "table_row"

  @javascript
  Scenario: Teacher views AI feedback details on the grading form
    Given the following "activity" exists:
      | activity                            | assign                 |
      | course                              | C1                     |
      | name                                | Detailed Review        |
      | assignsubmission_onlinetext_enabled | 1                      |
      | assignfeedback_aif_enabled          | 1                      |
      | assignfeedback_aif_prompt           | Evaluate the text      |
      | assignfeedback_aif_autogenerate     | 1                      |
      | submissiondrafts                    | 0                      |
    And the AI feedback mock returns "Clear thesis statement and good use of evidence. Consider expanding your conclusion."
    And I am on the "Detailed Review" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | A detailed analysis of migration patterns in the European Union. |
    And I press "Save changes"
    And I run all adhoc tasks
    When I am on the "Detailed Review" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    Then I should see "Clear thesis statement" in the "Student 1" "table_row"
    And I should see "good use of evidence" in the "Student 1" "table_row"

  @javascript
  Scenario: Student sees AI feedback after autogenerate in practice mode
    Given the following "activity" exists:
      | activity                            | assign                 |
      | course                              | C1                     |
      | name                                | Practice Exercise      |
      | assignsubmission_onlinetext_enabled | 1                      |
      | assignfeedback_aif_enabled          | 1                      |
      | assignfeedback_aif_prompt           | Give practice tips     |
      | assignfeedback_aif_autogenerate     | 1                      |
      | submissiondrafts                    | 0                      |
    And the AI feedback mock returns "Your practice submission shows good understanding."
    When I am on the "Practice Exercise" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Practice essay about the benefits of reading daily for cognitive development. |
    And I press "Save changes"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "Your practice submission shows good understanding"
