@mod @mod_assign @assignfeedback @assignfeedback_aif @_file_upload

Feature: AI Feedback Plugin
    As a teacher
    I want to use AI Feedback in assignments
    So that I can provide automated feedback to students
  Background:
    Given the following "courses" exist:
          | fullname | shortname | category | groupmode |
          | Course 1 | C1        | 0        | 1         |
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
    And the following "groups" exist:
          | name | course | idnumber |
          | G1   | C1     | G1       |
    And the following "group members" exist:
          | user     | group |
          | student1 | G1    |
          | student2 | G1    |
    And the following "activity" exists:
          | activity                            | assign               |
          | course                              | C1                   |
          | name                                | Test assignment name |
          | assignsubmission_file_enabled       | 1                    |
          | assignsubmission_onlinetext_enabled | 1                    |
          | assignsubmission_file_maxfiles      | 1                    |
          | assignsubmission_file_maxsizebytes  | 1024                 |
          | assignfeedback_comments_enabled     | 1                    |
          | assignfeedback_aif_enabled          | 1                    |
          | assignfeedback_aif_prompt           | Analyste the grammar |
          | maxfilessubmission                  | 2                    |
          | teamsubmission                      | 1                    |
          | submissiondrafts                    | 0                    |
    And I am on the "Test assignment name" Activity page logged in as student1
    When I press "Add submission"
    And I set the following fields to these values:
          | Online text | I'm the student first submission |
    And I press "Save changes"

  @javascript
  Scenario: A teacher can configure assignment sumissions to receive ai feedback.
    And I am on the "Test assignment name" Activity page logged in as teacher1
    And I navigate to "Submissions" in current page administration
    And I click on "selectall" "checkbox"
    And I click on "More" "button"
    And I click on "Generate AI feedback" "link"
    And I should see "Save changes"
    And I click on "Save changes" "button"
    And I log out
    And I log in as "admin"
    And I trigger cron
