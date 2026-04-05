<?php

return <<<'SQL'
CREATE TABLE transaction_status_history (
    id CHAR(36) PRIMARY KEY,
    transaction_id CHAR(36) NOT NULL,
    status VARCHAR(50) NOT NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_transaction_created (transaction_id, created_at)
);
SQL;
