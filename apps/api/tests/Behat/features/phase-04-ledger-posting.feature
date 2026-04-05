Feature: Phase 04 ledger posting for authorization
  Successful authorizations should write balanced append-only ledger entries,
  and ledger failures must roll the transaction state back.

  Scenario: Authorized transaction writes a balanced journal entry pair
    Given an active merchant with valid API credentials
    And a pending authorization transaction for amount "100.00" in currency "CAD"
    When the payment worker processes pending transaction commands
    Then the transaction status should become "authorized"
    And exactly 1 journal entry should be stored
    And exactly 2 ledger entries should be stored
    And the ledger entry account codes should be:
      | merchant_payable |
      | processor_receivable |
    And the ledger entries should belong to the same journal entry
    And an audit event "ledger.authorization_posted" should be recorded

  Scenario: Ledger posting failure rolls authorization back
    Given an active merchant with valid API credentials
    And a pending authorization transaction for amount "40.00" in currency "CAD"
    And the authorization ledger accounts are unavailable
    When the payment worker processes the authorization command
    Then authorization should fail with a runtime error
    And the transaction status should remain "pending"
    And no journal entries should be stored
    And no ledger entries should be stored
    And no processed payment-worker event should be recorded
    And no FX rate lock should be stored
