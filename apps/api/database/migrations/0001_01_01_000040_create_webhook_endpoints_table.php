<?php

return <<<'SQL'
CREATE TABLE webhook_endpoints (
    id CHAR(36) PRIMARY KEY,
    merchant_id CHAR(36) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    signing_secret VARCHAR(255) NOT NULL,
    event_types JSON NOT NULL,
    status VARCHAR(50) NOT NULL,
    created_at DATETIME(6) NOT NULL
);
SQL;
