@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: Manual AI feedback generation by teacher
  As a teacher
  I want to manually generate AI feedback for individual and multiple student submissions
  So that I can provide AI-assisted feedback at my own pace and review it before release

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |
      | student3 | Student   | 3        | student3@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "activity" exists:
      | activity                            | assign                      |
      | course                              | C1                          |
      | name                                | Essay Assignment            |
      | assignsubmission_onlinetext_enabled | 1                           |
      | assignfeedback_aif_enabled          | 1                           |
      | assignfeedback_aif_prompt           | Evaluate the argumentation  |
      | submissiondrafts                    | 0                           |
    And the AI feedback mock returns "The essay demonstrates solid argumentation skills. Consider adding more evidence to support your main thesis."

  @javascript
  Scenario: Teacher sees generate button on grading page for submitted student
    Given I am on the "Essay Assignment" Activity page logged in as student1
    When I press "Add submission"
    And I set the following fields to these values:
      | Online text | Climate change is a pressing global issue that requires immediate action. |
    And I press "Save changes"
    And I am on the "Essay Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "Grade actions" "actionmenu" in the "Student 1" "table_row"
    And I choose "Grade" in the open action menu
    Then "button[data-action='regenerate-aif']" "css_element" should exist

  @javascript
  Scenario: Teacher uses batch operation to generate AI feedback for multiple students
    Given I am on the "Essay Assignment" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Student 1 essay about renewable energy sources and their importance. |
    And I press "Save changes"
    And I log out
    And I am on the "Essay Assignment" Activity page logged in as student2
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Student 2 essay about the impact of technology on modern education. |
    And I press "Save changes"
    And I log out
    And I am on the "Essay Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I should see "Generate AI feedback for all selected users?"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    Then AI feedback should exist for "student1" in "Essay Assignment"
    And AI feedback should exist for "student2" in "Essay Assignment"

  @javascript
  Scenario: Teacher uses batch operation to delete AI feedback for selected students
    Given I am on the "Essay Assignment" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | My essay about artificial intelligence in healthcare. |
    And I press "Save changes"
    And I log out
    And I am on the "Essay Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    And AI feedback should exist for "student1" in "Essay Assignment"
    # Now delete the feedback.
    And I am on the "Essay Assignment" "assign activity" page
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Delete AI feedback" "link"
    And I click on "Save changes" "button"
    Then AI feedback should not exist for "student1" in "Essay Assignment"

  @javascript
  Scenario: Batch generate does not fail for students without submissions
    # Only student1 submits, student3 does not.
    Given I am on the "Essay Assignment" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Essay from student1 for batch testing without all having submissions. |
    And I press "Save changes"
    And I log out
    And I am on the "Essay Assignment" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    Then AI feedback should exist for "student1" in "Essay Assignment"
    # student3 has no submission, so no feedback should be generated.
    And AI feedback should not exist for "student3" in "Essay Assignment"
