Feature: Phase 06 settlement batch generation and submission
  Captured transactions should be grouped into settlement batches, written to
  artifacts, and tracked as submitted or exception batches.

  Background:
    Given an active merchant with valid API credentials

  Scenario: Scheduler creates and submits a settlement batch for eligible captured transactions
    Given the merchant has captured settlement-eligible transactions for amounts:
      | 80.00 |
      | 50.00 |
    When the settlement window runs for batch date "2026-04-05"
    Then exactly 1 settlement batch should be stored
    And the settlement batch status should be "submitted"
    And the settlement batch item count should be 2
    And the settlement batch total amount should be "130.0000"
    And exactly 2 settlement items should be stored
    And exactly 1 settlement artifact should be stored
    And a "settlement.batch.submitted" event should be published
    And an audit event "settlement.batch_submitted" should be recorded

  Scenario: Scheduler marks the batch as exception when processor submission fails
    Given the merchant has captured a settlement transaction for amount "75.00" on processor "processor_b"
    When the settlement window runs for batch date "2026-04-05" with submission failure for processor "processor_b"
    Then exactly 1 settlement batch should be stored
    And the settlement batch status should be "exception"
    And the settlement batch exception reason should be "Settlement submission failed for processor [processor_b]"
    And exactly 1 settlement artifact should be stored
    And an audit event "settlement.batch_exception" should be recorded

  Scenario: Transactions from exception batches are re-eligible in the next settlement window
    Given the merchant has captured a settlement transaction for amount "60.00" on processor "processor_b"
    When the settlement window runs for batch date "2026-04-05" with submission failure for processor "processor_b"
    Then the settlement run should report 0 submitted batch
    And the settlement run should report 1 exception batch
    When the settlement window runs again for batch date "2026-04-06"
    Then the settlement run should report 1 submitted batch
    And the settlement run should report 0 exception batch
    And the latest settlement batch status should be "submitted"

  Scenario: No batches are created when there are no eligible captured transactions
    When the settlement window runs for batch date "2026-04-05"
    Then the settlement run should report 0 submitted batch
    And exactly 0 settlement batches should be stored

  Scenario: Captures in different currencies produce separate settlement batches
    Given the merchant has captured settlement-eligible transactions for amounts and currencies:
      | 80.00 | CAD |
      | 100.00 | USD |
    When the settlement window runs for batch date "2026-04-07"
    Then the settlement run should report 2 submitted batches
    And exactly 2 settlement batches should be stored
    And the settlement batches should cover currencies "CAD" and "USD"
