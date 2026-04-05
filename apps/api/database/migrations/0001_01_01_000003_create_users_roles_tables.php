<?php

return <<<'SQL'
CREATE TABLE operators (
    id CHAR(36) PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    role VARCHAR(100) NOT NULL,
    created_at DATETIME(6) NOT NULL
);
SQL;
