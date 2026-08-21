-- Run this once against your MySQL database (e.g. `mysql tattoo_bot < schema.sql`)

CREATE TABLE IF NOT EXISTS bot_states (
    telegram_id BIGINT PRIMARY KEY,
    username    VARCHAR(255)    DEFAULT NULL,
    step        VARCHAR(32)     NOT NULL DEFAULT 'idle',
    temp_date   DATE            DEFAULT NULL,
    temp_time   TIME            DEFAULT NULL,
    temp_desc   TEXT            DEFAULT NULL,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS appointments (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id        BIGINT NOT NULL,
    username           VARCHAR(255) DEFAULT NULL,
    appointment_date   DATE NOT NULL,
    appointment_time   TIME NOT NULL,
    description        TEXT DEFAULT NULL,
    status             ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_telegram_id (telegram_id),
    INDEX idx_date_time (appointment_date, appointment_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
