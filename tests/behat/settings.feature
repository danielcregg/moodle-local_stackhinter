@local @local_stackhinter
Feature: STACK AI Hinter administration settings
  In order to enable the Socratic hint button
  As an administrator
  I need to reach its configuration page

  Scenario: An administrator can view STACK AI Hinter settings page
    Given I log in as "admin"
    And I visit "/admin/settings.php?section=local_stackhinter"
    Then I should see "Enable STACK AI Hinter"
    And I should see "AI provider"
    And I should see "AI API key"
