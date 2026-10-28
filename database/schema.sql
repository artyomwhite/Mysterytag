CREATE TABLE IF NOT EXISTS events (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    click_id         VARCHAR(255) NOT NULL,
    event            VARCHAR(100) NOT NULL,
    amount           DECIMAL(18,6) NOT NULL,
    dedup_hash       CHAR(64) NOT NULL,
    status           ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_http_status SMALLINT UNSIGNED NULL,
    last_error       VARCHAR(500) NULL,
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    processed_at     DATETIME(3) NULL,
    PRIMARY KEY (id),
    KEY idx_click_id (click_id),
    KEY idx_status_created (status, created_at),
    KEY idx_dedup_hash (dedup_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_attempts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id    BIGINT UNSIGNED NOT NULL,
    attempt_no  TINYINT UNSIGNED NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    error       VARCHAR(500) NULL,
    duration_ms INT UNSIGNED NOT NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_event (event_id),
    CONSTRAINT fk_attempt_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
