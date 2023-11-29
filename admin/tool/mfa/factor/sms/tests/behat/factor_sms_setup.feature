@tool_admin_mfa
Feature: Setup SMS factor in user preferences
  In order check the setup SMS factor verification
  As an admin
  I want to setup and enable the SMS factor for the current user

  Background:
    Given I log in as "admin"
    And I navigate to "Plugins > Admin tools > Multi-factor authentication" in site administration
    When I set the field "MFA plugin enabled" to "1"
    Then I press "Save changes"
    And  I visit "/admin/settings.php?section=factor_sms"
    And I set the field "Enable factor" to "1"
    Then I press "Save changes"

  Scenario: Setup and revoke SMS factor
    And I follow "Preferences" in the user menu
    And I click on "Multi-factor authentication preferences" "link"
    Then I should see "SMS Mobile phone"
    And I click on "Setup SMS" "button"
    Then I should see "SMS Setup"
    # Valid phone number.
    When I set the field "Mobile number" to "+34649709233"
    And I press "Send code"
    Then I should see "+34649709233" in the ".alert-success" "css_element"
    And I should see "Enter code"
    # Valid security code.
    When I set the field "Enter code" with valid code
    Then I press "Save"
    Then I should see "successfully set up" in the ".alert-success" "css_element"
    And I should see "Active factors"
    And I should see "SMS Mobile phone"
    # Revoke the SMS factor
    When I click on "Revoke" "link"
    Then I should see "Are you sure you want to revoke factor?"
    When I press "Revoke"
    Then I should see "successfully revoked" in the ".alert-success" "css_element"

  Scenario: Phone number setup form validation
    When I follow "Preferences" in the user menu
    And I click on "Multi-factor authentication preferences" "link"
    Then I should see "SMS Mobile phone"
    And I click on "Setup SMS" "button"
    Then I should see "SMS Setup"
    # Invalid phone number.
    When I set the field "Mobile number" to "++5555sss"
    And I press "Send code"
    Then I should see "The phone number you provided is not in a valid format."
    When I set the field "Mobile number" to "0123456789"
    And I press "Send code"
    Then I should see "The phone number you provided is not in a valid format."
    When I set the field "Mobile number" to "786-307-3615"
    And I press "Send code"
    Then I should see "The phone number you provided is not in a valid format."
    When I set the field "Mobile number" to "649709233"
    And I press "Send code"
    Then I should see "The phone number you provided is not in a valid format."
    # Valid phone number.
    When I set the field "Mobile number" to "+34649709233"
    And I press "Send code"
    Then I should see "+34649709233" in the ".alert-success" "css_element"
    And I should see "Enter code"
    # Edit phone number button.
    When I click on "Edit phone number" "link"
    And I should see "Mobile number"
    And I should see "" in the "#id_phonenumber" "css_element"
    Then I set the field "Mobile number" to "+34649709232"
    And I press "Send code"
    Then I should see "+34649709232" in the ".alert-success" "css_element"
    And I should see "Enter code"

  Scenario: Code setup form validation
    When I follow "Preferences" in the user menu
    And I click on "Multi-factor authentication preferences" "link"
    And I click on "Setup SMS" "button"
    And I set the field "Mobile number" to "+34649709233"
    And I press "Send code"
    Then I should see "Enter code"
    # Invalid code.
    When I set the field "Enter code" to "555556"
    And I click on "Save" "button"
    Then I should see "Wrong code. Try again"
    When I set the field "Enter code" to "ddddd5"
    And I click on "Save" "button"
    Then I should see "Wrong code. Try again"
