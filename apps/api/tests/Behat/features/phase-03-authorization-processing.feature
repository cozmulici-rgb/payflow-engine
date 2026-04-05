Feature: Phase 03 authorization worker processing
  The payment worker should authorize pending transactions, fail them on
  fraud, and deduplicate retries while supporting FX and inquiry fallback.

  Background:
    Given an active merchant with valid API credentials

  Scenario: Worker authorizes an approved pending transaction
    Given a pending authorization transaction for amount "100.00" in currency "CAD"
    When the payment worker processes pending transaction commands
    Then exactly 1 command should be processed
    And the transaction status should become "authorized"
    And the transaction processor id should be "processor_a"
    And exactly 1 processed event should be recorded for the payment worker
    And a "transaction.authorized" event should be published

  Scenario: Worker fails a transaction rejected by fraud screening
    Given a pending authorization transaction flagged for fraud rejection
    When the payment worker processes pending transaction commands
    Then the transaction status should become "failed"
    And the transaction error code should be "fraud_rejected"
    And a "transaction.failed" event should be published

  Scenario: Worker recovers from processor timeout by inquiry with FX lock
    Given a pending cross-border authorization transaction from "CAD" to "USD"
    And the processor is configured to timeout before inquiry confirms approval
    When the payment worker processes pending transaction commands
    Then the transaction status should become "authorized"
    And the transaction processor id should be "processor_a"
    And exactly 1 FX rate lock should be stored
    And the FX rate lock should be marked as used
    When the payment worker processes pending transaction commands again
    Then exactly 1 processed event should still be recorded for the payment worker

  Scenario: Illegal capture transition from failed state is rejected by the state machine
    Given a transaction is in status "failed"
    When the state machine evaluates a transition to "captured"
    Then the transition should be rejected
