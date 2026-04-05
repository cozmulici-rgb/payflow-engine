<?php

return <<<'SQL'
CREATE TABLE fx_rate_locks (
    id CHAR(36) PRIMARY KEY,
    transaction_id CHAR(36) NOT NULL,
    base_currency CHAR(3) NOT NULL,
    quote_currency CHAR(3) NOT NULL,
    rate DECIMAL(18,8) NOT NULL,
    settlement_amount DECIMAL(18,4) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL
);
SQL;
