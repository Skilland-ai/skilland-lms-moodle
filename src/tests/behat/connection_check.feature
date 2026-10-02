@mod @mod_skilland
Feature: Administrators test the connection to Skilland
  In order to know the plugin settings work together
  As an administrator
  I need to run a connection check that names the setting to fix

  Background:
    Given the following config values are set as admin:
      | graphql_endpoint | https://skilland.example                     | mod_skilland |
      | apikey           | behat-fixture-api-key                        | mod_skilland |
      | orgid            | fixture-org                                  | mod_skilland |
      | sso_secret       | behat-fixture-sso-secret-not-a-real-one-0123 | mod_skilland |

  Scenario: The settings page links to the check and the check reports a working connection
    Given I log in as "admin"
    # Open the settings page by URL: navigating by name also matches "Skilland content: test connection".
    And I visit "/admin/settings.php?section=modsettingskilland"
    And I should see "SSO Shared Secret"
    When I follow "Test connection"
    Then I should see "Run check"
    And I should not see "Connected to"
    And I press "Run check"
    And I should see "Connected to Fixture organization."
    And I should see "The SSO shared secret matches."
