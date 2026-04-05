Feature: Phase 05 capture, refund, and merchant webhooks
  Authorized transactions should be capturable, captured transactions should
  support refunds, and subscribed merchant endpoints should receive signed
  lifecycle events.

  Background:
    Given an active merchant with valid API credentials
    And the merchant has an authorized transaction for amount "125.50" in currency "CAD"

  Scenario: Merchant captures part of an authorized transaction
    When the merchant captures the transaction for amount "100.00"
    Then the response status should be 202
    And the response body should contain "status" = "captured"
    And the response body should contain "captured_amount" = "100.00"
    And the stored transaction status should be "captured"
    And the stored transaction metadata should contain "captured_amount" = "100.00"
    And a "transaction.captured" event should be published

  Scenario: Merchant cannot capture more than the authorized amount
    When the merchant captures the transaction for amount "200.00"
    Then the response status should be 422
    And the response body should contain "message" = "Capture amount is invalid"

  Scenario: Merchant refunds part of a captured transaction
    Given the merchant already captured the transaction for amount "100.00"
    When the merchant refunds the transaction for amount "20.00"
    Then the response status should be 202
    And the response body should contain "status" = "refunded"
    And the response body should contain "refund_amount" = "20.00"
    And the stored transaction status should be "refunded"
    And the stored transaction metadata should contain "refunded_amount" = "20.00"
    And exactly 2 journal entries should be stored
    And exactly 4 ledger entries should be stored
    And the refund ledger entries should be recorded in currency "USD"
    And a "transaction.refunded" event should be published

  Scenario: Merchant cannot refund more than the captured amount
    Given the merchant already captured the transaction for amount "100.00"
    When the merchant refunds the transaction for amount "200.00"
    Then the response status should be 422
    And the response body should contain "message" = "Refund amount is invalid"

  Scenario: Matching webhook endpoints receive signed lifecycle notifications
    Given the merchant registered webhook endpoint "https://merchant.example/webhooks/payments" for:
      | transaction.authorized |
      | transaction.captured |
    And the merchant registered webhook endpoint "https://merchant.example/failover/webhooks/payments" for:
      | transaction.captured |
    And the merchant captures the transaction for amount "80.00"
    When the webhook worker processes transaction lifecycle events
    Then exactly 2 webhook events should be processed
    And exactly 3 webhook deliveries should be stored
    And the first delivery event type should be "transaction.authorized"
    And delivered webhook payloads should contain signatures
    And the endpoint "https://merchant.example/failover/webhooks/payments" should receive exactly 1 successful delivery
