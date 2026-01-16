-- WhatsApp Verification Migration
-- Run this SQL to add WhatsApp verification support

-- Add whatsapp_phone column to users table (if not exists)
-- Check if column exists first (for safety)
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'whatsapp_phone') = 0,
    'ALTER TABLE users ADD COLUMN whatsapp_phone VARCHAR(20) NULL UNIQUE AFTER email',
    'SELECT "Column whatsapp_phone already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index if not exists
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_whatsapp_phone') = 0,
    'ALTER TABLE users ADD INDEX idx_whatsapp_phone (whatsapp_phone)',
    'SELECT "Index idx_whatsapp_phone already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add WhatsApp verification settings (INSERT IGNORE won't duplicate)
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('whatsapp_verification_enabled', '0'),
('whatsapp_api_url', ''),
('whatsapp_api_method', 'GET'),
('whatsapp_verification_message', 'Your verification code for {{site_title}} is: {{code}}. This code expires in 10 minutes.');
