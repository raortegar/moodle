@core @core_course
Feature: Course default settings for update calendar events
  In order to test the default course settings for update calendar events
  As a admin
  I need to view the default course settings

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |

    And the following "activities" exist:
      | activity | course | idnumber | intro | name     | completion | completionview | completionexpected |
      | page     | C1     | p1       | x     | TestPage | 2          | 1              | 0                  |

  Scenario: New update calendar event in course default settings
    Given I log in as "admin"
    And I navigate to "Courses > Course default settings" in site administration
    Then the field "id_s_moodlecourse_completioneventsmaxtime_enabled" matches value "1"
    And the field "id_s_moodlecourse_completioneventsmaxtimev" matches value "365"
    And the field "id_s_moodlecourse_completioneventsmaxtimeu" matches value "days"
    And the field "id_s_moodlecourse_completioneventsvisible" matches value "1"

  @javascript
  Scenario: New calendar event completion created is save in logs
    Given I am on the TestPage "Page Activity" page logged in as admin
    When I am on the TestPage "Page Activity editing" page
    And I expand all fieldsets
    When I click on "id_completionexpected_enabled" "checkbox"
    Then I run all adhoc tasks
    And I press "Save and display"
    When I navigate to "Reports > Logs" in site administration
    And I press "Get these logs"
    Then I should see "Calendar event created"
    And I should see "created the event 'TestPage should be completed'"
    And I should not see "Calendar event updated"
