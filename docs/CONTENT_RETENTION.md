# Content Retention Feature - Implementation Guide

## Overview
The Content Retention feature allows administrators to automatically delete generated content (images, videos, audio) after a specified number of days. This helps manage storage costs and comply with data retention policies.

## Features Implemented

### 1. Database Changes
- **Migration File**: `migrations/content_retention.sql`
- Added `content_retention_days` setting to `site_settings` table (default: 0 = disabled)
- Added `expires_at` column to `user_gallery` table with index for efficient queries

### 2. Admin Configuration
- **Location**: Admin Panel → Site Settings → Workflow Settings
- **Setting**: "Content Retention (Days)" input field
- **Values**: 
  - `0` = Disabled (files never expire)
  - `1-365` = Number of days to keep files

### 3. Automatic Cleanup
- **Script**: `api/cron/cleanup-expired-content.php`
- Deletes expired files from:
  - Database (`user_gallery` table)
  - Local storage (`uploads/` folder)
  - BunnyCDN (if configured)
- Logs all deletions to `logs/content-cleanup.log`

### 4. User Notifications
- **Gallery Panel**: Shows retention notice when enabled
- **Gallery Items**: Display expiry badge with days remaining
  - Red badge: ≤ 3 days remaining
  - Amber badge: 4-7 days remaining
  - Gray badge: > 7 days remaining

### 5. API Updates
- **Gallery API** (`api/user/gallery.php`):
  - Automatically sets `expires_at` when creating items
  - Returns `days_remaining` for each item
  - Returns `retention_days` setting in response

## Installation Steps

### Step 1: Run Database Migration
```bash
# Using MySQL command line
mysql -u your_username -p your_database < migrations/content_retention.sql

# Or using phpMyAdmin
# Import the migrations/content_retention.sql file
```

### Step 2: Set Up Cron Job
Add one of these cron entries to run the cleanup script daily:

**Option A: CLI (Recommended)**
```bash
# Run daily at 2:00 AM
0 2 * * * php /path/to/aikaflow/api/cron/cleanup-expired-content.php
```

**Option B: HTTP with Secret Key**
First, add a cron secret key to your database:
```sql
INSERT INTO site_settings (setting_key, setting_value) 
VALUES ('cron_secret_key', 'your-random-secret-key-here');
```

Then add this cron entry:
```bash
# Run daily at 2:00 AM
0 2 * * * curl "https://yourdomain.com/api/cron/cleanup-expired-content.php?key=your-random-secret-key-here"
```

**Option C: HTTP with Admin API Key**
```bash
# Run daily at 2:00 AM (replace YOUR_ADMIN_API_KEY)
0 2 * * * curl "https://yourdomain.com/api/cron/cleanup-expired-content.php?api_key=YOUR_ADMIN_API_KEY"
```

### Step 3: Configure Retention Period
1. Log in as admin
2. Click your profile → Administration
3. Go to "Site Settings" tab
4. Scroll to "Workflow Settings" section
5. Set "Content Retention (Days)" to desired value (e.g., 30)
6. Click "Save Site Settings"

## Usage

### For Administrators

**Setting Retention Period:**
- Navigate to: Admin Panel → Site Settings → Workflow Settings
- Set the number of days (0 to disable, 1-365 to enable)
- Save settings

**Monitoring Cleanup:**
- Check logs at: `logs/content-cleanup.log`
- Each deletion is logged with timestamp, user ID, file URL, and creation date

**Manual Cleanup (Testing):**
```bash
# Run cleanup script manually
php api/cron/cleanup-expired-content.php

# Or via browser (with authentication)
https://yourdomain.com/api/cron/cleanup-expired-content.php?key=YOUR_SECRET_KEY
```

### For Users

**Viewing Expiry Information:**
1. Open the Generated Content panel (gallery icon in toolbar)
2. If retention is enabled, you'll see:
   - A notice at the top: "Files are stored for X days"
   - Expiry badges on each item showing days remaining

**Understanding Expiry Badges:**
- **Red badge (≤3 days)**: File will be deleted very soon
- **Amber badge (4-7 days)**: File will be deleted soon
- **Gray badge (>7 days)**: File is safe for now
- **No badge**: Retention is disabled, files never expire

## Technical Details

### How Expiry is Calculated
1. When a file is generated, `expires_at` is set to: `created_at + retention_days`
2. If retention is 0 (disabled), `expires_at` is NULL
3. Cleanup script finds items where `expires_at < NOW()`
4. Both database record and physical file are deleted

### Storage Support
The cleanup script supports:
- **Local files**: Files in `uploads/` directory
- **BunnyCDN**: Files stored via BunnyCDN plugin
- **Mixed storage**: Handles both local and CDN files

### Performance Considerations
- Cleanup processes max 500 items per run (prevents timeouts)
- Uses indexed queries for efficient expiry lookups
- Runs during off-peak hours (recommended: 2-4 AM)

## Troubleshooting

### Cleanup Not Running
**Check cron job:**
```bash
# List cron jobs
crontab -l

# Check cron logs
grep CRON /var/log/syslog
```

**Test manually:**
```bash
php api/cron/cleanup-expired-content.php
```

### Files Not Being Deleted
**Check retention setting:**
```sql
SELECT setting_value FROM site_settings WHERE setting_key = 'content_retention_days';
```

**Check expired items:**
```sql
SELECT COUNT(*) FROM user_gallery WHERE expires_at < NOW();
```

**Check cleanup logs:**
```bash
tail -f logs/content-cleanup.log
```

### Permission Issues
Ensure the web server has write permissions:
```bash
chmod 755 api/cron/cleanup-expired-content.php
chmod 755 logs/
```

## API Reference

### Gallery API Response (with retention)
```json
{
  "success": true,
  "items": [
    {
      "id": 123,
      "url": "https://cdn.example.com/file.mp4",
      "type": "video",
      "created_at": "2026-01-01 10:00:00",
      "expires_at": "2026-01-31 10:00:00",
      "days_remaining": 10
    }
  ],
  "retention_days": 30,
  "total": 50
}
```

### Cleanup Script Response
```json
{
  "success": true,
  "data": {
    "checked": 150,
    "deleted_db": 150,
    "deleted_files": 100,
    "deleted_cdn": 50,
    "errors": [],
    "started_at": "2026-01-20 02:00:00",
    "completed_at": "2026-01-20 02:01:23",
    "message": "Cleanup completed successfully"
  }
}
```

## Security Considerations

1. **Cron Authentication**: Always use secret key or admin API key for HTTP cron
2. **Log Files**: Cleanup logs contain file URLs - restrict access
3. **Irreversible**: Deleted files cannot be recovered - warn users appropriately
4. **Grace Period**: Consider setting retention to at least 7-14 days

## Localization

The feature is fully translated in:
- **English** (en)
- **Indonesian** (id)
- **Arabic** (ar)

Translation keys:
- `panels.content_retention_notice`
- `panels.expires_in`
- `panels.expiring_soon`

## Future Enhancements

Potential improvements:
- [ ] Per-user retention settings
- [ ] Email notifications before deletion
- [ ] Retention exemptions for specific files
- [ ] Archive to cold storage instead of delete
- [ ] Retention analytics dashboard

## Support

For issues or questions:
1. Check the cleanup logs: `logs/content-cleanup.log`
2. Verify database migration ran successfully
3. Test cleanup script manually
4. Check cron job configuration

---

**Version**: 1.0  
**Last Updated**: 2026-01-20  
**Compatibility**: AIKAFLOW v1.0+
