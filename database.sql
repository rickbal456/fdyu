-- AIKAFLOW Database Schema
-- MySQL 8.0+ compatible
-- NOTE: Create your database manually first, then run this schema

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    api_key VARCHAR(64) NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    role ENUM('admin', 'user') DEFAULT 'user',
    language VARCHAR(5) DEFAULT 'en',
    invitation_code VARCHAR(12) NULL UNIQUE,
    referred_by INT UNSIGNED NULL,
    referred_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_verified TINYINT(1) DEFAULT 0,
    verification_token VARCHAR(64) NULL,
    verification_token_expires_at TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_api_key (api_key),
    INDEX idx_invitation_code (invitation_code)
) ENGINE=InnoDB;

-- Workflows table
CREATE TABLE IF NOT EXISTS workflows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    json_data JSON NOT NULL,
    thumbnail_url VARCHAR(512) NULL,
    is_public TINYINT(1) DEFAULT 0,
    version INT UNSIGNED DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_public (is_public)
) ENGINE=InnoDB;

-- Workflow executions table (tracks each run)
CREATE TABLE IF NOT EXISTS workflow_executions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    input_data JSON NULL,
    output_data JSON NULL,
    result_url VARCHAR(512) NULL,
    error_message TEXT NULL,
    repeat_count INT UNSIGNED DEFAULT 1,
    current_iteration INT UNSIGNED DEFAULT 1,
    iteration_outputs JSON NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_workflow_id (workflow_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Flow executions table (tracks individual flows within an execution)
CREATE TABLE IF NOT EXISTS flow_executions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id INT UNSIGNED NOT NULL,
    flow_id VARCHAR(50) NOT NULL,
    entry_node_id VARCHAR(50) NOT NULL,
    flow_name VARCHAR(255) NULL,
    status ENUM('pending', 'queued', 'running', 'completed', 'failed', 'skipped') DEFAULT 'pending',
    priority INT UNSIGNED DEFAULT 0,
    result_data JSON NULL,
    error_message TEXT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_id) REFERENCES workflow_executions(id) ON DELETE CASCADE,
    INDEX idx_execution_id (execution_id),
    INDEX idx_flow_id (flow_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Node tasks table (tracks individual node execution)

CREATE TABLE IF NOT EXISTS node_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id INT UNSIGNED NOT NULL,
    node_id VARCHAR(50) NOT NULL,
    node_type VARCHAR(50) NOT NULL,
    status ENUM('pending', 'queued', 'processing', 'completed', 'failed') DEFAULT 'pending',
    external_task_id VARCHAR(255) NULL,
    input_data JSON NULL,
    output_data JSON NULL,
    result_url VARCHAR(512) NULL,
    error_message TEXT NULL,
    attempts INT UNSIGNED DEFAULT 0,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_id) REFERENCES workflow_executions(id) ON DELETE CASCADE,
    INDEX idx_execution_id (execution_id),
    INDEX idx_external_task_id (external_task_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Task queue table (for async processing)
CREATE TABLE IF NOT EXISTS task_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_type VARCHAR(50) NOT NULL,
    payload JSON NOT NULL,
    priority INT DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    attempts INT UNSIGNED DEFAULT 0,
    max_attempts INT UNSIGNED DEFAULT 3,
    locked_at TIMESTAMP NULL,
    locked_by VARCHAR(50) NULL,
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_priority (status, priority, scheduled_at),
    INDEX idx_locked (locked_at, locked_by)
) ENGINE=InnoDB;

-- API logs table (for debugging and rate limiting)
CREATE TABLE IF NOT EXISTS api_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    request_data JSON NULL,
    response_code INT NULL,
    response_time_ms INT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- Sessions table (optional: for database-backed sessions)
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    payload TEXT NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB;

-- Media assets table (for BunnyCDN stored files)
CREATE TABLE IF NOT EXISTS media_assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NULL,
    file_type VARCHAR(50) NOT NULL,
    file_size INT UNSIGNED NULL,
    cdn_url VARCHAR(512) NOT NULL,
    cdn_path VARCHAR(512) NOT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_file_type (file_type)
) ENGINE=InnoDB;

-- Webhook logs table
CREATE TABLE IF NOT EXISTS webhook_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL,
    external_id VARCHAR(255) NULL,
    payload JSON NOT NULL,
    processed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_source (source),
    INDEX idx_external_id (external_id),
    INDEX idx_processed (processed)
) ENGINE=InnoDB;

-- CSRF tokens table
CREATE TABLE IF NOT EXISTS csrf_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    user_id INT UNSIGNED NULL,
    session_id VARCHAR(128) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;
-- Workflow shares table (snapshots for sharing)
CREATE TABLE IF NOT EXISTS workflow_shares (
    id VARCHAR(32) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    workflow_id INT UNSIGNED NULL,
    workflow_data JSON NOT NULL,
    is_public TINYINT(1) DEFAULT 1,
    views INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
) ENGINE=InnoDB;

-- Site settings table (admin-configurable)
CREATE TABLE IF NOT EXISTS site_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default site settings
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES 
('site_title', 'AIKAFLOW'),
('logo_url', NULL),
('favicon_url', NULL),
('default_theme', 'dark'),
('hcaptcha_enabled', '0'),
('hcaptcha_site_key', NULL),
('hcaptcha_secret_key', NULL),
('headway_widget_id', NULL),
-- SMTP Settings
('smtp_enabled', '0'),
('smtp_host', NULL),
('smtp_port', '587'),
('smtp_username', NULL),
('smtp_password', NULL),
('smtp_encryption', 'tls'),
('smtp_from_email', NULL),
('smtp_from_name', 'AIKAFLOW'),
-- Email Templates
('email_verification_subject', 'Verify your email - AIKAFLOW'),
('email_verification_body', 'Hello {{username}},\n\nPlease click the link below to verify your email address:\n\n{{verification_link}}\n\nThis link will expire in 24 hours.\n\nBest regards,\nAIKAFLOW Team'),
('email_welcome_subject', 'Welcome to AIKAFLOW'),
('email_welcome_body', 'Hello {{username}},\n\nWelcome to AIKAFLOW! Your account has been created successfully.\n\nYou can now login at: {{login_link}}\n\nBest regards,\nAIKAFLOW Team'),
('email_forgot_password_subject', 'Reset your password - AIKAFLOW'),
('email_forgot_password_body', 'Hello {{username}},\n\nWe received a request to reset your password. Click the link below to set a new password:\n\n{{reset_link}}\n\nThis link will expire in 1 hour.\n\nIf you did not request this, please ignore this email.\n\nBest regards,\nAIKAFLOW Team'),
-- Terms and Privacy
('terms_of_service', '# Terms of Service\n\nBy using AIKAFLOW, you agree to these terms.'),
('privacy_policy', '# Privacy Policy\n\nYour privacy is important to us.');

-- Password reset tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- CREDIT SYSTEM TABLES
-- ============================================

-- Node cost configuration
CREATE TABLE IF NOT EXISTS node_costs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    node_type VARCHAR(100) NOT NULL UNIQUE,
    cost_per_call DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Credit ledger (tracks balance with expiration)
CREATE TABLE IF NOT EXISTS credit_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    credits DECIMAL(10,2) NOT NULL,
    remaining DECIMAL(10,2) NOT NULL,
    source ENUM('welcome', 'topup', 'bonus', 'coupon', 'adjustment', 'referral_bonus', 'referral_reward') NOT NULL,
    expires_at DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_expires (user_id, expires_at)
) ENGINE=InnoDB;

-- Credit transactions (usage history)
CREATE TABLE IF NOT EXISTS credit_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('topup', 'usage', 'bonus', 'refund', 'adjustment', 'expired', 'welcome', 'referral_bonus', 'referral_reward') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    balance_after DECIMAL(10,2) NOT NULL,
    description VARCHAR(255),
    reference_id VARCHAR(100),
    node_type VARCHAR(100),
    workflow_id INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB;

-- Credit packages
CREATE TABLE IF NOT EXISTS credit_packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    credits INT NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    bonus_credits INT DEFAULT 0,
    description VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0
) ENGINE=InnoDB;

-- Coupon codes
CREATE TABLE IF NOT EXISTS credit_coupons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    type ENUM('percentage', 'fixed_discount', 'bonus_credits') NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    min_purchase DECIMAL(12,2) DEFAULT 0,
    max_uses INT NULL,
    used_count INT DEFAULT 0,
    valid_from DATE NULL,
    valid_until DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Coupon usage tracking
CREATE TABLE IF NOT EXISTS credit_coupon_usage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    topup_request_id INT UNSIGNED NULL,
    discount_applied DECIMAL(10,2),
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coupon_id) REFERENCES credit_coupons(id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Top-up requests
CREATE TABLE IF NOT EXISTS topup_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    package_id INT UNSIGNED NULL,
    coupon_id INT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    discount DECIMAL(12,2) DEFAULT 0,
    final_amount DECIMAL(12,2) NOT NULL,
    credits_requested INT NOT NULL,
    bonus_credits INT DEFAULT 0,
    payment_proof VARCHAR(255),
    payment_method VARCHAR(50) DEFAULT 'bank_transfer',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    admin_notes TEXT,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    credits_expire_at DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES credit_packages(id),
    FOREIGN KEY (coupon_id) REFERENCES credit_coupons(id),
    INDEX idx_user_status (user_id, status),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Default credit packages (IDR)
INSERT IGNORE INTO credit_packages (name, credits, price, bonus_credits, description, sort_order) VALUES
('Starter', 500, 50000, 0, '500 credits for beginners', 1),
('Pro', 2000, 180000, 200, '2000 + 200 bonus credits', 2),
('Enterprise', 5000, 400000, 750, '5000 + 750 bonus credits', 3);

-- Credit system settings
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('credit_currency', 'IDR'),
('credit_currency_symbol', 'Rp'),
('credit_welcome_amount', '100'),
('credit_default_expiry_days', '365'),
('credit_low_threshold', '100'),
('credit_bank_name', NULL),
('credit_bank_account', NULL),
('credit_bank_holder', NULL),
('email_verification_enabled', '0'),
('qris_string', NULL),
-- Invitation System Settings
('invitation_enabled', '0'),
('invitation_referrer_credits', '50'),
('invitation_referee_credits', '50'),
-- PayPal Payment Gateway
('paypal_enabled', '0'),
('paypal_sandbox', '1'),
('paypal_client_id', NULL),
('paypal_secret_key', NULL),
('paypal_usd_rate', NULL);

-- Bank accounts for payment
CREATE TABLE IF NOT EXISTS payment_bank_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    account_holder VARCHAR(100) NOT NULL,
    logo_url VARCHAR(255) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- API RATE LIMITING SYSTEM
-- ============================================

-- Default rate limits per API provider
CREATE TABLE IF NOT EXISTS api_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    default_max_concurrent INT UNSIGNED DEFAULT 50,
    queue_timeout INT UNSIGNED DEFAULT 3600,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_provider (provider)
) ENGINE=InnoDB;

-- Default rate limits for known providers
INSERT IGNORE INTO api_rate_limits (provider, display_name, default_max_concurrent, queue_timeout) VALUES
('runninghub', 'RunningHub.ai', 50, 3600),
('kie', 'Kie.ai', 50, 3600),
('jsoncut', 'JsonCut', 100, 1800),
('postforme', 'Postforme (Social Media)', 50, 3600);

-- Track active API calls per API key
CREATE TABLE IF NOT EXISTS api_active_calls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    api_key_hash VARCHAR(64) NOT NULL,
    task_id VARCHAR(255) NOT NULL,
    workflow_run_id INT UNSIGNED NULL,
    node_id VARCHAR(100) NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (workflow_run_id) REFERENCES workflow_executions(id) ON DELETE SET NULL,
    INDEX idx_provider_key (provider, api_key_hash),
    INDEX idx_task_id (task_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- Queue for API calls waiting for available slots
CREATE TABLE IF NOT EXISTS api_call_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    api_key_hash VARCHAR(64) NOT NULL,
    workflow_run_id INT UNSIGNED NULL,
    node_id VARCHAR(100) NOT NULL,
    node_type VARCHAR(100) NOT NULL,
    input_data JSON NOT NULL,
    priority INT UNSIGNED DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed', 'expired') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (workflow_run_id) REFERENCES workflow_executions(id) ON DELETE CASCADE,
    INDEX idx_provider_key_status (provider, api_key_hash, status),
    INDEX idx_status_priority (status, priority, created_at)
) ENGINE=InnoDB;

-- ============================================
-- USER SETTINGS AND AUTOSAVE TABLES
-- ============================================

-- User settings table (stores all user preferences)
CREATE TABLE IF NOT EXISTS user_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value JSON NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_user_setting (user_id, setting_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Workflow autosaves table (for unsaved work recovery)
CREATE TABLE IF NOT EXISTS workflow_autosaves (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    workflow_id INT UNSIGNED NULL,
    json_data JSON NOT NULL,
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_user_workflow (user_id, COALESCE(workflow_id, 0)),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- User gallery table (stores generated content references)
CREATE TABLE IF NOT EXISTS user_gallery (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    workflow_id INT UNSIGNED NULL,
    item_type ENUM('image', 'video', 'audio') NOT NULL DEFAULT 'image',
    url VARCHAR(512) NOT NULL,
    node_id VARCHAR(50) NULL,
    node_type VARCHAR(50) NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_workflow_id (workflow_id)
) ENGINE=InnoDB;

-- ============================================
-- MIGRATION NOTES (for existing databases)
-- ============================================
-- Run these commands if upgrading from an older version:
--
-- ALTER TABLE users ADD COLUMN role ENUM('admin', 'user') DEFAULT 'user' AFTER is_active;
-- UPDATE users SET role = 'admin' WHERE id = 1;
--
-- CREATE TABLE IF NOT EXISTS site_settings (...); -- see above
-- INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ... -- see above
--
-- CREATE TABLE IF NOT EXISTS password_reset_tokens (...); -- see above
--
-- For Credit System:
-- Run all CREATE TABLE statements for: node_costs, credit_ledger, credit_transactions,
-- credit_packages, credit_coupons, credit_coupon_usage, topup_requests
-- Run all INSERT IGNORE statements for credit_packages and credit settings
--
-- For API Rate Limiting:
-- Run the CREATE TABLE statements for: api_rate_limits, api_active_calls, api_call_queue
-- Run the INSERT IGNORE statement for api_rate_limits
--
-- For User Settings Migration:
-- Run the CREATE TABLE statements for: user_settings, workflow_autosaves, user_gallery
