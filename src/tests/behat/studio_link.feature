@mod @mod_skilland
Feature: Teachers reach SkilLand Studio from their course
  In order to edit the content behind my SkilLand activities
  As a teacher
  I need a link that signs me in to SkilLand Studio

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
    And the following "activities" exist:
      | activity | name             | course | idnumber | provisioned |
      | skilland | Fixture activity | C1     | skl1     | 1           |

  Scenario: A teacher sees the SkilLand Studio link and the provision controls
    When I am on the "Course 1" "course" page logged in as "teacher1"
    Then "//a[contains(@href, '/mod/skilland/sso_redirect.php') and contains(normalize-space(.), 'Edit in Skilland')]" "xpath_element" should exist
    And I am on the "skl1" "Activity" page
    And I should see "Lesson one"
    And I should see "Content last updated"

  Scenario: A student gets no SkilLand Studio link
    When I am on the "Course 1" "course" page logged in as "student1"
    Then "//a[contains(@href, '/mod/skilland/sso_redirect.php')]" "xpath_element" should not exist
