<?php

return <<<'SQL'
CREATE TABLE transactions (
    id CHAR(36) PRIMARY KEY,
    merchant_id CHAR(36) NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    amount DECIMAL(18,4) NOT NULL,
    currency CHAR(3) NOT NULL,
    settlement_currency CHAR(3) NOT NULL,
    payment_method_type VARCHAR(50) NOT NULL,
    payment_method_token VARCHAR(255) NOT NULL,
    capture_mode VARCHAR(50) NOT NULL,
    reference VARCHAR(255) NULL,
    status VARCHAR(50) NOT NULL,
    processor_reference VARCHAR(255) NULL,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY uk_merchant_idempotency (merchant_id, idempotency_key)
);
SQL;
