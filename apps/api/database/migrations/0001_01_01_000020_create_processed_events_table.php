<?php

return <<<'SQL'
CREATE TABLE processed_events (
    id CHAR(36) PRIMARY KEY,
    consumer_group VARCHAR(100) NOT NULL,
    event_id VARCHAR(255) NOT NULL,
    processed_at DATETIME(6) NOT NULL,
    UNIQUE KEY uk_consumer_event (consumer_group, event_id)
);
SQL;
