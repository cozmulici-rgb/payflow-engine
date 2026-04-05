Feature: Phase 01 platform skeleton and merchant access
  The platform bootstrap should expose health endpoints and the minimal
  operator-managed merchant access flow required by later payment phases.

  Scenario: Health and readiness endpoints are available
    Given the API is bootstrapped
    When an internal caller requests "GET /internal/health"
    Then the response status should be 200
    And the response body should contain "status" = "ok"
    And the response body should contain a non-empty "correlation_id"
    When an internal caller requests "GET /internal/ready"
    Then the response status should be 200
    And the response body should contain "status" = "ready"
    And the readiness checks should report "storage" = "ok"

  Scenario: Operator creates a merchant
    Given an operator with role "merchant.write"
    When the operator creates a merchant named "Acme Payments"
    Then the response status should be 201
    And a merchant record should be stored for "Acme Payments"
    And an audit event "merchant.created" should be recorded
    And the audit event should contain a correlation id

  Scenario: Operator issues merchant API credentials
    Given an operator with role "merchant.write"
    And an existing merchant "Acme Payments"
    When the operator issues API credentials for that merchant
    Then the response status should be 201
    And the response should include a generated API secret
    And the stored credential key id should start with "pk_"
    And the stored secret should be hashed at rest
    And an audit event "merchant.api_credential_issued" should be recorded
