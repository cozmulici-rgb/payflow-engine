Feature: Phase 02 transaction ingestion and pending state
  Merchant requests should create exactly one pending transaction per
  idempotency key and allow merchant-scoped status reads.

  Background:
    Given an active merchant with valid API credentials

  Scenario: Merchant creates a pending authorization transaction
    When the merchant submits a new authorization request with idempotency key "idem-transaction-001"
    Then the response status should be 202
    And the response body should contain "status" = "pending"
    And the response body should contain "idempotency_key" = "idem-transaction-001"
    And exactly 1 transaction should be persisted for the merchant
    And the transaction status history should contain 1 "pending" entry
    And exactly 1 processing command should be published to topic "transaction.processing"
    And an audit event "transaction.created" should be recorded

  Scenario: Merchant replays the same request with the same idempotency key
    Given a previously accepted transaction for idempotency key "idem-transaction-001"
    When the merchant resubmits the same authorization payload with idempotency key "idem-transaction-001"
    Then the response status should be 202
    And the original transaction id should be returned
    And only 1 transaction should exist for that idempotency key
    And only 1 processing command should exist for that idempotency key
    And only 1 idempotency record should exist for that key

  Scenario: Merchant reads its own pending transaction
    Given the merchant created transaction "txn-1" in status "pending"
    When the merchant requests "GET /v1/transactions/txn-1"
    Then the response status should be 200
    And the response body should contain "transaction_id" = "txn-1"
    And the response body should contain "status" = "pending"

  Scenario: Another merchant cannot read a foreign transaction
    Given merchant A created transaction "txn-1"
    And merchant B has separate API credentials
    When merchant B requests "GET /v1/transactions/txn-1"
    Then the response status should be 404
    And the response body should contain "message" = "Transaction not found"

  Scenario: Unsupported transaction currency is rejected
    When the merchant submits an authorization request in currency "USD" for a "CAD" merchant
    Then the response status should be 422
    And the response body should contain "message" = "Validation failed"
    And the validation errors should include "currency"
    And no additional transaction should be persisted
