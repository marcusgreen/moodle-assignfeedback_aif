@mod @mod_assign @assignfeedback @assignfeedback_aif
Feature: AI feedback regeneration and retry
  As a teacher
  I want to regenerate or retry AI feedback
  So that I can get updated feedback after changes or recover from errors

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
      | name                                | Essay Task                |
      | assignsubmission_onlinetext_enabled | 1                         |
      | assignfeedback_aif_enabled          | 1                         |
      | assignfeedback_aif_prompt           | Evaluate the writing      |
      | assignfeedback_aif_autogenerate     | 1                         |
      | submissiondrafts                    | 0                         |

  @javascript
  Scenario: Teacher regenerates AI feedback and old feedback is replaced
    Given the AI feedback mock returns "Initial feedback: Your essay needs more structure."
    And I am on the "Essay Task" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | An essay about the effects of social media on adolescent mental health. |
    And I press "Save changes"
    And I run all adhoc tasks
    And AI feedback should exist for "student1" in "Essay Task"
    And there should be 1 AI feedback record for "student1" in "Essay Task"
    # Teacher wants to regenerate with better prompt or after AI update.
    And the AI feedback mock returns "Updated feedback: Your essay is well-structured with clear arguments."
    And I am on the "Essay Task" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    # Old feedback should be replaced, not duplicated.
    Then there should be 1 AI feedback record for "student1" in "Essay Task"

  @javascript
  Scenario: Teacher retries failed AI feedback after an error
    Given the AI feedback mock returns an error "The AI service is temporarily overloaded"
    And I am on the "Essay Task" Activity page logged in as student1
    And I press "Add submission"
    And I set the following fields to these values:
      | Online text | Essay about the impact of artificial intelligence on job markets worldwide. |
    And I press "Save changes"
    And I run all adhoc tasks
    And AI feedback should have an error for "student1" in "Essay Task"
    # Teacher now retries after the AI service has recovered.
    And the AI feedback mock returns "Your essay provides a thoughtful analysis of AI's impact on employment."
    And I am on the "Essay Task" "assign activity" page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I click on "Save changes" "button"
    And I run all adhoc tasks
    Then AI feedback should exist for "student1" in "Essay Task"
    And AI feedback should not have an error for "student1" in "Essay Task"
