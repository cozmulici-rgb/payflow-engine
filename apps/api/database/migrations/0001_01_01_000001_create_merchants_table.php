<?php

return <<<'SQL'
CREATE TABLE merchants (
    id CHAR(36) PRIMARY KEY,
    legal_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    country CHAR(2) NOT NULL,
    default_currency CHAR(3) NOT NULL,
    status VARCHAR(50) NOT NULL,
    created_at DATETIME(6) NOT NULL
);
SQL;
