<?php

return <<<'SQL'
CREATE TABLE journal_entries (
    id CHAR(36) PRIMARY KEY,
    reference_type VARCHAR(100) NOT NULL,
    reference_id CHAR(36) NOT NULL,
    description VARCHAR(500) NOT NULL,
    effective_date DATE NOT NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_journal_reference (reference_type, reference_id),
    INDEX idx_journal_effective_date (effective_date)
);
SQL;
