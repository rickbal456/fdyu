# AIKAFLOW

A node-based drag-and-drop web application for creating and executing AI video workflows with multi-language support and integrated payment system.

![AIKAFLOW](https://placehold.co/800x400?text=AIKAFLOW+Screenshot)

## Features

- 🎨 **Visual Workflow Editor** - Drag-and-drop nodes to build AI video pipelines
- 🔗 **Node Connections** - Connect nodes with bezier curves for intuitive flow visualization
- 🎬 **AI Video Generation** - Text-to-video, image-to-video (10-15s), and more
- 🎵 **Audio Processing** - Music generation, voice cloning, text-to-speech
- ✂️ **Video Editing** - Merge, trim, crop, overlay, speed control, and effects
- 📦 **Multiple AI Providers** - RunningHub.ai, Kie.ai (Suno), JsonCut.com
- ☁️ **Cloud Storage** - BunnyCDN integration for media storage
- 🔐 **Secure Authentication** - Session-based auth with Google OAuth and API key support
- 💳 **Credit System** - Integrated payment with PayPal, Bank Transfer, and QRIS
- 🌐 **Multi-Language** - English, Indonesian (Bahasa), and Arabic with RTL support
- 📱 **Responsive Design** - Works on desktop and tablet devices
- 🔌 **Plugin System** - Extensible architecture with plugin support
- 👥 **Social Media Integration** - Post directly to Instagram, TikTok, Facebook, YouTube, X, LinkedIn, Pinterest, Bluesky, and Threads via Postforme API
- 🔗 **Workflow Sharing** - Share workflows as read-only links
- 🎁 **Invitation System** - Referral program with credit rewards

## Requirements

- PHP 8.1+
- MySQL 8.0+
- Apache/Nginx with mod_rewrite
- cURL extension
- JSON extension
- PDO MySQL extension
- Composer (for dependencies)

## Quick Start

### 1. Clone or Download

```bash
git clone https://github.com/yourusername/aikaflow.git
cd aikaflow
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Environment

```bash
cp .env.example .env
# Edit .env with your database and API credentials
```

### 4. Create Database

```sql
CREATE DATABASE aikaflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON aikaflow.* TO 'your_user'@'localhost';
```

### 5. Run Installation

Open `http://yourdomain.com/install.php` in your browser and follow the prompts.

### 6. Delete Installation File

```bash
rm install.php
```

### 7. Configure Cron (Optional but Recommended)

```bash
# Add to crontab
* * * * * php /path/to/aikaflow/cron.php >> /path/to/aikaflow/logs/cron.log 2>&1
```

### 8. Start Background Worker

```bash
# Using the shell script
chmod +x cron-worker.sh
./cron-worker.sh start

# Or with supervisor (recommended for production)
sudo cp supervisor.conf /etc/supervisor/conf.d/aikaflow.conf
sudo supervisorctl reread
sudo supervisorctl update
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_URL` | Application URL | `http://localhost` |
| `APP_DEBUG` | Enable debug mode | `false` |
| `DB_HOST` | Database host | `localhost` |
| `DB_PORT` | Database port | `3306` |
| `DB_NAME` | Database name | `aikaflow` |
| `DB_USER` | Database user | `root` |
| `DB_PASS` | Database password | - |
| `SESSION_SECURE` | Use secure cookies (HTTPS) | `false` |
| `BUNNY_STORAGE_ZONE` | BunnyCDN storage zone | - |
| `BUNNY_ACCESS_KEY` | BunnyCDN access key | - |
| `BUNNY_STORAGE_URL` | BunnyCDN storage URL | `https://storage.bunnycdn.com` |
| `BUNNY_CDN_URL` | BunnyCDN public URL | - |

### API Keys Configuration

API keys can be configured in two ways:

1. **Administration Panel** (Settings → Integrations)
   - RunningHub.ai API Key
   - Kie.ai API Key
   - JsonCut.com API Key
   - Postforme API Key (for social media posting)
   - Google OAuth credentials

2. **Per-Node Configuration** - Override global keys for specific nodes

### Payment Methods

Configure payment methods in Administration → Credits → Payment Methods:

1. **PayPal** - Enter Client ID and Secret
2. **Bank Transfer** - Configure bank account details
3. **QRIS** - Upload QR code image for Indonesian e-wallet payments

### Social Media Integration

Connect social accounts in Settings → Social Accounts:
- Instagram
- TikTok
- Facebook
- YouTube
- X (Twitter)
- LinkedIn
- Pinterest
- Bluesky
- Threads

## Usage

### Creating a Workflow

1. Log in to the dashboard
2. Drag nodes from the left sidebar onto the canvas
3. Connect node outputs to inputs by clicking and dragging
4. Configure node properties in the right panel
5. Click "Run" to execute the workflow
6. View results in the Gallery or History panel

### Sharing Workflows

1. Click the Share button on the canvas
2. Toggle "Enable Share Link"
3. Click "Generate Share Link"
4. Copy and share the link (recipients can view but not edit or run)

### API Access

Generate your API key in Settings → API, then use it to execute workflows programmatically:

```bash
curl -X POST https://yourdomain.com/api/workflows/execute.php \
  -H "X-API-Key: your_api_key" \
  -H "Content-Type: application/json" \
  -d '{"workflowId": 123}'
```

### API Endpoints

#### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/auth/register.php` | Register new user |
| `POST` | `/api/auth/login.php` | User login |
| `POST` | `/api/auth/logout.php` | User logout |
| `GET` | `/api/auth/me.php` | Get current user |
| `GET` | `/api/auth/google.php` | Google OAuth login |
| `GET` | `/api/auth/google-callback.php` | Google OAuth callback |

#### Workflows

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/workflows/list.php` | List user workflows |
| `GET` | `/api/workflows/get.php?id={id}` | Get workflow details |
| `POST` | `/api/workflows/save.php` | Create/update workflow |
| `DELETE` | `/api/workflows/delete.php?id={id}` | Delete workflow |
| `POST` | `/api/workflows/duplicate.php` | Duplicate workflow |
| `POST` | `/api/workflows/execute.php` | Execute workflow |
| `GET` | `/api/workflows/status.php?id={id}` | Get execution status |
| `POST` | `/api/workflows/cancel.php` | Cancel execution |
| `GET` | `/api/workflows/history.php` | Execution history |

#### Credits & Payments

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/credits/balance.php` | Get credit balance |
| `GET` | `/api/credits/packages.php` | List credit packages |
| `POST` | `/api/credits/topup.php` | Create top-up request |
| `GET` | `/api/credits/history.php` | Transaction history |
| `POST` | `/api/credits/apply-coupon.php` | Apply coupon code |
| `POST` | `/api/payments/paypal-create.php` | Create PayPal order |
| `POST` | `/api/payments/paypal-capture.php` | Capture PayPal payment |

#### Social Media

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/social/accounts.php` | List connected accounts |
| `POST` | `/api/social/connect.php` | Get OAuth URL for platform |
| `DELETE` | `/api/social/accounts.php?id={id}` | Disconnect account |

#### Invitations

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/invitations/code.php` | Get invitation code and stats |
| `POST` | `/api/invitations/code.php` | Apply invitation code |

#### Media

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/media/upload.php` | Upload file |
| `GET` | `/api/media/list.php` | List media assets |
| `DELETE` | `/api/media/delete.php?id={id}` | Delete media |

#### User Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/user/settings.php` | Get settings |
| `POST` | `/api/user/settings.php` | Update settings |
| `POST` | `/api/user/regenerate-api-key.php` | Regenerate API key |
| `POST` | `/api/user/change-password.php` | Change password |

#### Administration (Admin Only)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/admin/users.php` | List all users |
| `POST` | `/api/admin/users.php` | Create user |
| `PUT` | `/api/admin/users.php` | Update user |
| `DELETE` | `/api/admin/users.php?id={id}` | Delete user |
| `GET` | `/api/admin/settings.php` | Get site settings |
| `POST` | `/api/admin/settings.php` | Update site settings |
| `GET` | `/api/admin/credits.php` | Get credit requests |
| `POST` | `/api/admin/credits.php` | Approve/reject request |

## Node Types

### Input Nodes

| Node | Description | Outputs |
|------|-------------|---------|
| **Image Input** | Upload image or provide URL | Image |
| **Video Input** | Upload video or provide URL | Video |
| **Audio Input** | Upload audio or provide URL | Audio |
| **Text/Prompt** | Text content input | Text |

### Generation Nodes

| Node | Description | Inputs | Outputs |
|------|-------------|--------|---------|
| **Image to Video V1** | Animate static images (10-15s) | Image, Motion Prompt | Video |

### Editing Nodes

| Node | Description | Inputs | Outputs |
|------|-------------|--------|---------|
| **Video Merge** | Combine videos sequentially | Video 1, Video 2 | Video |
| **Video Trim** | Cut video clips | Video, Start, End | Video |
| **Video Crop** | Crop video dimensions | Video | Video |
| **Video Overlay** | Overlay video on another | Background, Overlay | Video |
| **Video Reverse** | Reverse video playback | Video | Video |
| **Video Speed** | Change playback speed | Video, Speed | Video |
| **Text Overlay** | Add text to video | Video, Text | Video |
| **Audio Volume** | Adjust audio volume | Video, Volume | Video |
| **Extract Audio** | Extract audio from video | Video | Audio |

### Social Media Nodes

| Node | Description | Inputs | Outputs |
|------|-------------|--------|---------|
| **Social Post** | Publish to social media | Video/Image, Caption, Accounts | Post Result |

### Utility Nodes

| Node | Description | Inputs | Outputs |
|------|-------------|--------|---------|
| **Entry** | Workflow entry point | - | Flow |
| **Delay** | Wait timer | Any | Any |

## Plugins

AIKAFLOW uses a plugin system for extensibility. Plugins are located in the `plugins/` directory.

### Core Plugins

- **aflow-api** - API access modal
- **aflow-credits** - Credit management and top-up
- **aflow-credits-qris** - QRIS payment integration
- **aflow-invitation** - Referral system
- **aflow-share** - Workflow sharing
- **aflow-social-post** - Social media integration
- **aflow-i2v-v1** - Image to Video generation
- **aflow-edit-*** - Video editing nodes (merge, trim, crop, overlay, etc.)
- **aflow-input-*** - Input nodes (image, video, audio, text)

### Creating a Plugin

Create a new directory in `plugins/` with the following structure:

```
plugins/my-plugin/
├── plugin.json       # Plugin metadata
├── nodes.js          # Node definitions (optional)
└── my-plugin.js      # Plugin logic (optional)
```

Example `plugin.json`:

```json
{
  "id": "my-plugin",
  "name": "My Plugin",
  "version": "1.0.0",
  "description": "My custom plugin",
  "author": "Your Name",
  "scripts": ["nodes.js", "my-plugin.js"],
  "styles": [],
  "dependencies": []
}
```

## Internationalization

AIKAFLOW supports multiple languages:

- **English** (default)
- **Bahasa Indonesia**
- **العربية (Arabic)** with RTL support

### Language Files

Translation files are located in `assets/lang/`:
- `en.json` - English
- `id.json` - Indonesian
- `ar.json` - Arabic

### Adding Translations

1. Add translation keys to all language files
2. Use `data-i18n` attribute in HTML:
   ```html
   <button data-i18n="common.save">Save</button>
   ```
3. Use `window.t()` function in JavaScript:
   ```javascript
   const label = window.t('common.save');
   ```

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl+N` | New workflow |
| `Ctrl+O` | Open workflow |
| `Ctrl+S` | Save workflow |
| `Ctrl+Shift+S` | Save as |
| `Ctrl+E` | Export workflow |
| `Ctrl+Z` | Undo |
| `Ctrl+Y` / `Ctrl+Shift+Z` | Redo |
| `Ctrl+A` | Select all nodes |
| `Ctrl+C` | Copy selected |
| `Ctrl+V` | Paste |
| `Ctrl+D` | Duplicate selected |
| `Delete` / `Backspace` | Delete selected |
| `Escape` | Clear selection / Cancel |
| `Space` | Toggle properties panel |
| `Tab` | Cycle node selection |
| `F5` / `Ctrl+Enter` | Run workflow |
| `Ctrl++` | Zoom in |
| `Ctrl+-` | Zoom out |
| `Ctrl+0` | Reset zoom |
| `Arrow Keys` | Move selected nodes |
| `Shift+Arrow Keys` | Move nodes faster |

## Troubleshooting

### Common Issues

#### "Database connection failed"

```bash
# Check MySQL is running
sudo systemctl status mysql

# Verify credentials
mysql -u your_user -p your_database

# Check .env file
cat .env | grep DB_
```

#### "External API error"

- Verify API keys in Administration → Integrations
- Check API provider status pages
- Review `logs/php_errors.log`
- Test API connection:

```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
  https://api.runninghub.ai/v1/status
```

#### "File upload failed"

```bash
# Check PHP settings
php -i | grep upload_max_filesize
php -i | grep post_max_size

# Check directory permissions
ls -la temp/
chmod 775 temp/

# Verify BunnyCDN credentials
curl -H "AccessKey: YOUR_ACCESS_KEY" \
  https://storage.bunnycdn.com/your-zone/
```

#### "Workflow execution stuck"

```bash
# Check worker status
./cron-worker.sh status

# Run cron manually to clean up
php cron.php

# Check for stuck tasks
mysql -e "SELECT * FROM task_queue WHERE status='processing';" aikaflow

# Review worker log
tail -f logs/worker.log
```

#### "Permission denied"

```bash
# Fix file permissions
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
chmod 775 temp/ logs/ uploads/
chown -R www-data:www-data .
```

### Log Files

| Log | Location | Description |
|-----|----------|-------------|
| PHP Errors | `logs/php_errors.log` | PHP runtime errors |
| Worker | `logs/worker.log` | Background worker output |
| Cron | `logs/cron.log` | Scheduled task output |
| Database | `logs/database.log` | Database connection errors |
| Apache/Nginx | `/var/log/apache2/` or `/var/log/nginx/` | Web server logs |

### Debug Mode

Enable debug mode for detailed error messages:

```env
APP_DEBUG=true
```

⚠️ **Never enable debug mode in production!**

## Development

### Project Structure

```
aikaflow/
├── api/                  # Backend API endpoints
│   ├── admin/            # Admin endpoints
│   ├── auth/             # Authentication endpoints
│   ├── credits/          # Credit system endpoints
│   ├── invitations/      # Invitation system
│   ├── media/            # File upload/management
│   ├── payments/         # Payment processing
│   ├── proxy/            # External API proxies
│   ├── share/            # Workflow sharing
│   ├── social/           # Social media integration
│   ├── user/             # User settings
│   └── workflows/        # Workflow CRUD & execution
├── assets/               # Frontend assets
│   ├── css/              # Stylesheets
│   ├── js/               # JavaScript modules
│   └── lang/             # Translation files
├── includes/             # PHP includes
│   ├── ApiRateLimiter.php
│   ├── auth.php          # Authentication
│   ├── config.php        # Configuration
│   ├── db.php            # Database connection
│   ├── email.php         # Email utilities
│   ├── LicenseVerifier.php
│   └── PluginManager.php
├── plugins/              # Plugin system
│   ├── aflow-api/
│   ├── aflow-credits/
│   ├── aflow-edit-*/     # Editing nodes
│   ├── aflow-i2v-v1/
│   ├── aflow-input-*/    # Input nodes
│   ├── aflow-invitation/
│   ├── aflow-share/
│   └── aflow-social-post/
├── generated/            # Generated content
├── logs/                 # Application logs
├── temp/                 # Temporary files
├── uploads/              # User uploads
├── vendor/               # Composer dependencies
├── .env                  # Environment config
├── .gitignore            # Git ignore rules
├── composer.json         # PHP dependencies
├── cron.php              # Scheduled tasks
├── cron-worker.sh        # Worker management script
├── database.sql          # Database schema
├── index.php             # Main editor
├── install.php           # Installation wizard
├── login.php             # Login page
├── register.php          # Registration page
├── supervisor.conf       # Supervisor config
├── view.php              # Shared workflow viewer
└── worker.php            # Background worker
```

### Code Style

- **PHP**: PSR-12 coding standard
- **JavaScript**: ES6+ with JSDoc comments
- **CSS**: BEM-like naming with Tailwind utilities

## Security Considerations

### Production Checklist

- [ ] Set `APP_DEBUG=false`
- [ ] Use HTTPS with valid SSL certificate
- [ ] Delete `install.php` after installation
- [ ] Use strong database passwords
- [ ] Restrict database user permissions
- [ ] Configure firewall rules
- [ ] Enable rate limiting
- [ ] Set up log rotation
- [ ] Regular security updates
- [ ] Backup strategy in place
- [ ] Configure `SESSION_SECURE=true` for HTTPS
- [ ] Review and restrict file upload types
- [ ] Set appropriate file permissions

### Security Headers

The `.htaccess` file sets these security headers:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
```

### API Authentication

All API endpoints (except public endpoints) require authentication via:

1. **Session Cookie** - For browser-based access
2. **API Key Header** - `X-API-Key: your_key` for programmatic access

### License Verification

AIKAFLOW includes a license verification system that checks:
- License validity
- Domain matching
- Expiration date

License checks are performed locally (fast) with periodic server verification.

## Performance Optimization

### Caching

Consider adding:

- **OPcache** for PHP bytecode caching
- **Redis/Memcached** for session and query caching
- **CDN** for static assets (BunnyCDN recommended)

### Database

```sql
-- Add indexes for better performance
CREATE INDEX idx_workflows_user_updated ON workflows(user_id, updated_at);
CREATE INDEX idx_executions_user_status ON workflow_executions(user_id, status);
CREATE INDEX idx_tasks_execution_status ON node_tasks(execution_id, status);
CREATE INDEX idx_credits_user_created ON credit_transactions(user_id, created_at);
```

### Worker Scaling

For high-traffic deployments, run multiple workers:

```ini
# supervisor.conf
[program:aikaflow-worker]
numprocs=4
process_name=%(program_name)s_%(process_num)02d
```

## Docker Deployment

```bash
# Build and run with Docker Compose
docker-compose up -d

# View logs
docker-compose logs -f

# Stop containers
docker-compose down
```

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Commit changes: `git commit -am 'Add my feature'`
4. Push to branch: `git push origin feature/my-feature`
5. Submit a Pull Request

### Development Setup

```bash
# Clone repo
git clone https://github.com/yourusername/aikaflow.git
cd aikaflow

# Install dependencies
composer install

# Copy development config
cp .env.development .env

# Create database
mysql -u root -p -e "CREATE DATABASE aikaflow_dev;"

# Run install
php -S localhost:8000
# Visit http://localhost:8000/install.php

# Start worker in development
php worker.php
```

## Changelog

### v1.0.0 (2026-01-XX)

- Initial release
- Visual node-based workflow editor
- Multi-language support (English, Indonesian, Arabic)
- Credit system with multiple payment methods
- Social media integration (9 platforms)
- Plugin architecture
- Workflow sharing
- Invitation/referral system
- Google OAuth integration
- Background task processing
- User authentication with API keys

## License

This software requires a valid license for production use. Contact the administrator for licensing information.

## Credits

- [Tailwind CSS](https://tailwindcss.com/) - Styling
- [Lucide Icons](https://lucide.dev/) - Icons
- [Inter Font](https://rsms.me/inter/) - Typography
- [Postforme API](https://postforme.dev/) - Social media integration

## Support

- 📖 Documentation: [docs.aikaflow.com](https://docs.aikaflow.com)
- 🐛 Issues: [GitHub Issues](https://github.com/yourusername/aikaflow/issues)
- 💬 Discussions: [GitHub Discussions](https://github.com/yourusername/aikaflow/discussions)
- 📧 Email: support@aikaflow.com

---

Made with ❤️ by the AIKAFLOW Team