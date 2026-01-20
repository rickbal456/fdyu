-- Content Retention Feature Migration
-- Run this migration to enable auto-deletion of generated content

-- Add content retention setting (0 = disabled/never expires)
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('content_retention_days', '0');

-- Add expires_at column to user_gallery for tracking expiration
ALTER TABLE user_gallery 
ADD COLUMN expires_at TIMESTAMP NULL AFTER created_at,
ADD INDEX idx_expires_at (expires_at);

-- Note: expires_at will be populated when content is created
-- based on the content_retention_days setting at that time.
-- If retention is 0 (disabled), expires_at will be NULL (never expires).
