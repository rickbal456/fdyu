-- Add source column to user_gallery table
-- This allows separating manual executions from API executions

ALTER TABLE user_gallery 
ADD COLUMN source ENUM('manual', 'api') DEFAULT 'manual' AFTER node_type;

-- Add index for faster filtering by source
ALTER TABLE user_gallery 
ADD INDEX idx_source (source);
