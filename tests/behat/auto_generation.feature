@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: Automatic AI feedback generation on submission
  As a teacher
  I want AI feedback to be generated automatically when students submit
  So that students can receive feedback quickly without manual intervention

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
    And the AI feedback mock returns "Good work on your submission. Here are some suggestions for improvement."

  @javascript
  Scenario: AI feedback is automatically generated when student submits and autogenerate is on
    Given the following "activity" exists:
      | activity                            | assign                 |
      | course                              | C1                     |
      | name                                | Auto Feedback Task     |
      | assignsubmission_onlinetext_enabled | 1                      |
      | assignfeedback_aif_enabled          | 1                      |
      | assignfeedback_aif_prompt           | Provide feedback       |
      | assignfeedback_aif_autogenerate     | 1                      |
      | submissiondrafts                    | 0                      |
    When I am on the "Auto Feedback Task" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | This is my essay about the importance of environmental protection. |
    And I press "Save changes"
    And I run all adhoc tasks
    Then AI feedback should exist for "student1" in "Auto Feedback Task"

  @javascript
  Scenario: AI feedback is NOT generated on submit when autogenerate is off
    Given the following "activity" exists:
      | activity                            | assign               |
      | course                              | C1                   |
      | name                                | Manual Only Task     |
      | assignsubmission_onlinetext_enabled | 1                    |
      | assignfeedback_aif_enabled          | 1                    |
      | assignfeedback_aif_prompt           | Provide feedback     |
      | assignfeedback_aif_autogenerate     | 0                    |
      | submissiondrafts                    | 0                    |
    When I am on the "Manual Only Task" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | This is my essay about sustainability in modern agriculture. |
    And I press "Save changes"
    And I run all adhoc tasks
    Then AI feedback should not exist for "student1" in "Manual Only Task"

  @javascript
  Scenario: Multiple students submitting with autogenerate creates feedback for each
    Given the following "activity" exists:
      | activity                            | assign               |
      | course                              | C1                   |
      | name                                | Class Assignment     |
      | assignsubmission_onlinetext_enabled | 1                    |
      | assignfeedback_aif_enabled          | 1                    |
      | assignfeedback_aif_prompt           | Evaluate the essay   |
      | assignfeedback_aif_autogenerate     | 1                    |
      | submissiondrafts                    | 0                    |
    When I am on the "Class Assignment" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Student 1 writes about quantum computing and its future applications. |
    And I press "Save changes"
    And I log out
    And I am on the "Class Assignment" Activity page logged in as student2
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Student 2 writes about the ethical implications of artificial intelligence. |
    And I press "Save changes"
    And I run all adhoc tasks
    Then AI feedback should exist for "student1" in "Class Assignment"
    And AI feedback should exist for "student2" in "Class Assignment"

  @javascript
  Scenario: Autogenerate with marking workflow hides feedback from student until released
    Given the following "activity" exists:
      | activity                            | assign               |
      | course                              | C1                   |
      | name                                | Reviewed Assignment  |
      | assignsubmission_onlinetext_enabled | 1                    |
      | assignfeedback_aif_enabled          | 1                    |
      | assignfeedback_aif_prompt           | Evaluate the essay   |
      | assignfeedback_aif_autogenerate     | 1                    |
      | markingworkflow                     | 1                    |
      | submissiondrafts                    | 0                    |
    When I am on the "Reviewed Assignment" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | My essay about the industrial revolution and its lasting impact on society. |
    And I press "Save changes"
    And I run all adhoc tasks
    # Feedback is generated but student should not see it yet (marking workflow not released).
    And AI feedback should exist for "student1" in "Reviewed Assignment"
    And I am on the "Reviewed Assignment" Activity page logged in as student1
    Then I should not see "Good work on your submission"
    # Teacher releases the grade via marking workflow.
    And I am on the "Reviewed Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "Grade actions" "actionmenu" in the "Student 1" "table_row"
    And I choose "Grade" in the open action menu
    And I set the field "Marking workflow state" to "Released"
    And I press "Save changes"
    # Now student can see the feedback.
    And I am on the "Reviewed Assignment" Activity page logged in as student1
    And I should see "Good work on your submission"

  @javascript
  Scenario: Practice mode shows feedback immediately without marking workflow
    Given the following "activity" exists:
      | activity                            | assign               |
      | course                              | C1                   |
      | name                                | Practice Task        |
      | assignsubmission_onlinetext_enabled | 1                    |
      | assignfeedback_aif_enabled          | 1                    |
      | assignfeedback_aif_prompt           | Give practice tips   |
      | assignfeedback_aif_autogenerate     | 1                    |
      | markingworkflow                     | 0                    |
      | submissiondrafts                    | 0                    |
    When I am on the "Practice Task" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | My practice essay about the role of music in education. |
    And I press "Save changes"
    And I run all adhoc tasks
    And I am on the "Practice Task" Activity page logged in as student1
    Then I should see "Good work on your submission"
