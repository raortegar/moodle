@core @core_grades
Feature: Gradebook calculation freeze for 20260808
  In order to prevent existing grades from changing unexpectedly after upgrade
  As a teacher
  I need to be able to review and accept grade calculation changes

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | groupmode |
      | Course 1 | C1        | 0        | 1 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | t1       |
      | student1 | Student   | 1        | student1@example.com | s1       |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  @javascript
  Scenario: A frozen gradebook displays the grade calculation changes notice
    Given gradebook calculations for the course "C1" are frozen at version "20260808"
    And I am on the "Course 1" "grades > gradebook setup" page logged in as "teacher1"
    Then I should see "Accept grade changes and fix calculation errors"

  @javascript
  Scenario: Accepting the grade calculation changes removes the freeze notice
    Given gradebook calculations for the course "C1" are frozen at version "20260808"
    And I am on the "Course 1" "grades > gradebook setup" page logged in as "teacher1"
    And I should see "Accept grade changes and fix calculation errors"
    When I click on "Accept grade changes and fix calculation errors" "button"
    And I wait until the page is ready
    Then I should not see "Accept grade changes and fix calculation errors"
