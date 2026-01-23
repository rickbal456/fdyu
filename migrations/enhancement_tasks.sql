-- Enhancement Tasks Table
-- For standalone AI enhancement requests (not part of workflow execution)

CREATE TABLE IF NOT EXISTS enhancement_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    node_id VARCHAR(50) NOT NULL UNIQUE,
    external_task_id VARCHAR(255) NULL,
    provider VARCHAR(50) NOT NULL DEFAULT 'rhub-enhance',
    task_type VARCHAR(50) NOT NULL DEFAULT 'image_enhance',
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'processing',
    input_data JSON NULL,
    result_data JSON NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_external_task_id (external_task_id),
    INDEX idx_node_id (node_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;
