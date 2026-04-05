<?php

return <<<'SQL'
CREATE TABLE ledger_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id CHAR(36) NOT NULL,
    account_id CHAR(36) NOT NULL,
    entry_type ENUM('debit', 'credit') NOT NULL,
    amount DECIMAL(18,4) NOT NULL,
    currency CHAR(3) NOT NULL,
    transaction_id CHAR(36) NULL,
    settlement_batch_id CHAR(36) NULL,
    description VARCHAR(500) NOT NULL,
    effective_date DATE NOT NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_journal (journal_entry_id),
    INDEX idx_account_date (account_id, effective_date),
    INDEX idx_transaction (transaction_id),
    INDEX idx_settlement_batch (settlement_batch_id),
    INDEX idx_effective_date (effective_date)
);
SQL;
