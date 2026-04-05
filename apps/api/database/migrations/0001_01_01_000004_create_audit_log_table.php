<?php

return <<<'SQL'
CREATE TABLE audit_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    event_type VARCHAR(100) NOT NULL,
    actor_id VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    resource_type VARCHAR(100) NOT NULL,
    resource_id VARCHAR(100) NOT NULL,
    correlation_id CHAR(36) NOT NULL,
    context JSON NOT NULL,
    created_at DATETIME(6) NOT NULL
);
SQL;
