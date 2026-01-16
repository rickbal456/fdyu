-- WhatsApp Verification Migration
-- Run this SQL to add WhatsApp verification support

-- Add whatsapp_phone column to users table
ALTER TABLE users ADD COLUMN whatsapp_phone VARCHAR(20) NULL UNIQUE AFTER email;
ALTER TABLE users ADD INDEX idx_whatsapp_phone (whatsapp_phone);

-- Add WhatsApp verification settings
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('whatsapp_verification_enabled', '0'),
('whatsapp_api_url', ''),
('whatsapp_api_method', 'GET'),
('whatsapp_verification_message', 'Your verification code for {{site_title}} is: {{code}}. This code expires in 10 minutes.');
