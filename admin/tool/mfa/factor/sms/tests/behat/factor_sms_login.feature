@tool_admin_mfa
Feature: Login user with sms authentication factor
  In order to login using SMS factor authentication
  As an user
  I need to be able to login

  Background:
    Given I log in as "admin"
    # Enable SMS factor and MFA authentication.
    And I navigate to "Plugins > Admin tools > Multi-factor authentication" in site administration
    When I set the field "MFA plugin enabled" to "1"
    And I set the field "Lockout threshold" to "3"
    Then I press "Save changes"
    And  I visit "/admin/settings.php?section=factor_sms"
    And I set the field "Enable factor" to "1"
    Then I press "Save changes"
    # Set up user factor.
    When I follow "Preferences" in the user menu
    And I click on "Multi-factor authentication preferences" "link"
    And I click on "Setup SMS" "button"
    And I set the field "Mobile number" to "+34649709233"
    Then I press "Send code"
    When I set the field "Enter code" with valid code
    Then I press "Save"

  Scenario: Unable to login user without set up sms factor
    Given I click on "Revoke" "link"
    Then I should see "Are you sure you want to revoke factor?"
    When I press "Revoke"
    Then I should see "successfully revoked" in the ".alert-success" "css_element"
    When  I log out
    And I log in as "admin"
    Then I should see "Unable to authenticate" in the ".alert-danger" "css_element"

  Scenario: Login user successfully with sms verification
    Given  I log out
    When I log in as "admin"
    Then  I should see "2-step verification"
    And  I should see "Enter code"
    When I set the field "Enter code" with valid code
    And I click on "Continue" "button"
    Then I should see "Welcome back"

  Scenario: Wrong code number end of possible attempts
    Given  I log out
    When I log in as "admin"
    Then  I should see "2-step verification"
    And  I should see "Enter code"
    When I set the field "Enter code" to "555556"
    And I click on "Continue" "button"
    Then I should see "Wrong code."
    And I should see "You have 2 attempts left."
    When I set the field "Enter code" to "555553"
    And I click on "Continue" "button"
    Then I should see "Wrong code."
    And I should see "1 attempts left."
    When I set the field "Enter code" to "555553"
    And I click on "Continue" "button"
    Then I should see "Unable to authenticate"
