@mod @mod_skilland
Feature: Learners see the lessons of a SkilLand activity
  In order to follow a SkilLand topic in Moodle
  As a student
  I need to see the lessons of the activity and open the ones that are ready

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | customfield_skilland_course_id |
      | Course 1 | C1        | skill-1                        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: A student sees the lesson list of a provisioned activity
    Given the following "activities" exist:
      | activity | name             | course | idnumber | provisioned |
      | skilland | Fixture activity | C1     | skl1     | 1           |
    When I am on the "skl1" "Activity" page logged in as "student1"
    Then I should see "Lessons"
    And I should see "L1.1"
    And I should see "Lesson one"
    And I should see "L1.2"
    And I should see "Lesson two"
    And "Lesson one" "link" should exist

  Scenario: A student waits while the content of an activity is not provisioned yet
    Given the following "activities" exist:
      | activity | name             | course | idnumber |
      | skilland | Fixture activity | C1     | skl1     |
    When I am on the "skl1" "Activity" page logged in as "student1"
    Then I should see "This lesson content is being prepared. Please check back later."
    And I should not see "Lesson one"
