CREATE TABLE shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_uuid VARCHAR(36) NOT NULL UNIQUE,
    actual_clock_in DATETIME NULL,
    processed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    raw_data JSON,
    forward_token VARCHAR(32) NULL,
    forward_expires_at DATETIME NULL,
    forward_email VARCHAR(255) NULL,
    forwarded_at DATETIME NULL,
    INDEX idx_actual_clock_in (actual_clock_in),
    INDEX idx_processed (processed),
    INDEX idx_forward_token (forward_token)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

CREATE TABLE api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL,
    request_data JSON,
    response_data JSON,
    status_code INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; 

