<?php

return <<<'SQL'
CREATE TABLE idempotency_records (
    id CHAR(36) PRIMARY KEY,
    scope_key VARCHAR(320) NOT NULL,
    response_code SMALLINT NOT NULL,
    response_body JSON NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uk_scope_key (scope_key)
);
SQL;
