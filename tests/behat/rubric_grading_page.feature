@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: AI rubric assessment is mirrored into the grading form
  As a teacher
  I want the rubric on the grading page to show the AI assessment right after generation
  So that I can review and adjust it without reloading the page

  Background:
    Given the following config values are set as admin:
      | enableapplyrubricgrades | 1 | assignfeedback_aif |
    And the following "courses" exist:
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
      | activity                            | assign                |
      | course                              | C1                    |
      | name                                | Rubric Task           |
      | assignsubmission_onlinetext_enabled | 1                     |
      | assignfeedback_aif_enabled          | 1                     |
      | assignfeedback_aif_prompt           | Evaluate the text     |
      | markingworkflow                     | 1                     |
      | submissiondrafts                    | 0                     |
      | grade                               | 100                   |
      | advancedgradingmethod_submissions   | rubric                |
    And I am on the "Course 1" course page logged in as teacher1
    And I go to "Rubric Task" advanced grading definition page
    And I set the following fields to these values:
      | Name | Essay rubric |
    And I define the following rubric:
      | Spelling | Nothing but mistakes | 1 | Several mistakes | 2 | No mistakes           | 3 |
      | Pictures | No pictures          | 1 | One picture      | 2 | More than one picture | 3 |
    And I press "Save rubric and make it ready"
    And I am on the "Rubric Task" "assign activity" page
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "assignfeedback_aif_applyrubricgrades" to "1"
    And I press "Save and display"
    And I log out
    And the AI feedback mock returns:
      """
      Good work overall.

      ```json
      {"rubric": [
        {"criterion": "Spelling", "level": "No mistakes", "score": 3, "remark": "Flawless."},
        {"criterion": "Pictures", "level": "One picture", "score": 2, "remark": "Add another one."}
      ]}
      ```
      """
    And I am on the "Rubric Task" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | An essay with one picture. |
    And I press "Save changes"
    And I log out

  @javascript
  Scenario: Generating feedback on the grading page fills the rubric without a reload
    Given I am on the "Rubric Task" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "Grade actions" "actionmenu" in the "Student 1" "table_row"
    And I choose "Grade" in the open action menu
    And ".level.checked" "css_element" should not exist
    When I click on "button[data-action='regenerate-aif']" "css_element"
    And I click on "Yes" "button" in the ".modal" "css_element"
    And I run all adhoc tasks
    And I wait until ".level.checked" "css_element" exists
    Then the level with "3" points is selected for the rubric criterion "Spelling"
    And the level with "2" points is selected for the rubric criterion "Pictures"
    And the field with xpath "(//textarea[contains(@name, '[remark]')])[1]" matches value "Flawless."
