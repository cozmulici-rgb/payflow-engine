<?php

return <<<'SQL'
CREATE TABLE webhook_deliveries (
    id CHAR(36) PRIMARY KEY,
    webhook_endpoint_id CHAR(36) NOT NULL,
    event_id CHAR(36) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    merchant_id CHAR(36) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    attempt INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    signature VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    delivered_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL
);
SQL;
