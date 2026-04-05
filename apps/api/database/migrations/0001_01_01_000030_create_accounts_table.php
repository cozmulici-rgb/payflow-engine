<?php

return <<<'SQL'
CREATE TABLE accounts (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    normal_balance ENUM('debit', 'credit') NOT NULL,
    currency CHAR(3) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY uk_accounts_code (code)
);
SQL;
