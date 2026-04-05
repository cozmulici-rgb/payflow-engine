<?php

return <<<'SQL'
CREATE TABLE api_credentials (
    id CHAR(36) PRIMARY KEY,
    merchant_id CHAR(36) NOT NULL,
    key_id VARCHAR(64) NOT NULL,
    secret_hash VARCHAR(255) NOT NULL,
    created_at DATETIME(6) NOT NULL
);
SQL;
