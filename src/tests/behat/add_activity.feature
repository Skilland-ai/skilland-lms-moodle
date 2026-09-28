@mod @mod_skilland @javascript
Feature: Teachers add a SkilLand activity to a course
  In order to deliver a SkilLand topic in my course
  As a teacher
  I need to pick a topic and its lessons in the activity form

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | customfield_skilland_course_id |
      | Course 1 | C1        | skill-1                        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    # The fixture API signs its SCORM package with a test-only key (fixture_api_client::fixture_key_line()).
    And the following config values are set as admin:
      | signingkeys | fixture:jTte47f+m0Osdcz/wIohztUx4OSXUtq4f4BxGucmeoM= | mod_skilland |

  Scenario: The form lists the mapped skill's topics and lessons and saves the activity
    Given I log in as "teacher1"
    And I add a "skilland" activity to course "Course 1" section "1"
    And I should see "Fixture skill"
    When I set the field "Topic" to "T1 - Fixture topic one (topic-1)"
    Then I should see "L1.1 - Lesson one"
    And I should see "L1.2 - Lesson two"
    And the field "lesson_lesson-1" matches value "1"
    And the field "lesson_lesson-2" matches value "1"
    # The lang string's {$a} placeholder is filled in by the form's JS with the lesson's date.
    And "//div[contains(@class, 'skilland-lesson-meta')][starts-with(normalize-space(.), 'Updated ')][not(contains(., '{$a}'))]" "xpath_element" should exist
    And I set the field "lesson_lesson-2" to "0"
    And I press "Save and display"
    And I should see "Content Not Yet Available"
    And I press "Prepare Topic Content"
    And I wait until "L1.1" "text" exists
    And I should see "Lesson one"
    And I should not see "Lesson two"
    And I am on "Course 1" course homepage
    And I should see "T1 - Fixture topic one"

  Scenario: A course without a SkilLand course asks the teacher to set one first
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 2 | C2        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C2     | editingteacher |
    When I log in as "teacher1"
    And I add a "skilland" activity to course "Course 2" section "1"
    Then I should see "Set Skilland Course ID in course settings"
    And "Topic" "field" should not exist
