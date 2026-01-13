# AIKAFLOW

A node-based drag-and-drop web application for creating and executing AI video workflows.

![AIKAFLOW](https://via.placeholder.com/800x400?text=AIKAFLOW+Screenshot)

## Features

- 🎨 **Visual Workflow Editor** - Drag-and-drop nodes to build AI video pipelines
- 🔗 **Node Connections** - Connect nodes with bezier curves for intuitive flow visualization
- 🎬 **AI Video Generation** - Text-to-video, image-to-video, and more
- 🎵 **Audio Processing** - Music generation, voice cloning, text-to-speech
- ✂️ **Video Editing** - Merge, trim, add audio, subtitles, and effects
- 📦 **Multiple AI Providers** - RunningHub.ai, Kie.ai (Suno), JsonCut.com
- ☁️ **Cloud Storage** - BunnyCDN integration for media storage
- 🔐 **Secure Authentication** - Session-based auth with API key support
- 📱 **Responsive Design** - Works on desktop and tablet devices

## Requirements

- PHP 8.1+
- MySQL 8.0+
- Apache/Nginx with mod_rewrite
- cURL extension
- JSON extension
- PDO MySQL extension

## Quick Start

### 1. Clone or Download

```bash
git clone https://github.com/yourusername/aikaflow.git
cd aikaflow
```

### 2. Configure Environment

```bash
cp .env.example .env
# Edit .env with your database and API credentials
```

### 3. Create Database

```sql
CREATE DATABASE aikaflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON aikaflow.* TO 'your_user'@'localhost';
```

### 4. Run Installation

Open `http://yourdomain.com/install.php` in your browser and follow the prompts.

### 5. Delete Installation File

```bash
rm install.php
```

### 6. Configure Cron (Optional but Recommended)

```bash
# Add to crontab
* * * * * php /path/to/aikaflow/cron.php >> /path/to/aikaflow/logs/cron.log 2>&1
```

### 7. Start Background Worker

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
| `DB_NAME` | Database name | `aikaflow` |
| `DB_USER` | Database user | `root` |
| `DB_PASS` | Database password | - |
| `RUNNINGHUB_API_KEY` | RunningHub.ai API key | - |
| `KIE_API_KEY` | Kie.ai API key | - |
| `JSONCUT_API_KEY` | JsonCut.com API key | - |
| `BUNNY_STORAGE_ZONE` | BunnyCDN storage zone | - |
| `BUNNY_ACCESS_KEY` | BunnyCDN access key | - |

### API Keys

1. **RunningHub.ai** - Sign up at [runninghub.ai](https://runninghub.ai)
2. **Kie.ai** - Sign up at [kie.ai](https://kie.ai)
3. **JsonCut.com** - Sign up at [jsoncut.com](https://jsoncut.com)
4. **BunnyCDN** - Sign up at [bunny.net](https://bunny.net)

## Usage

### Creating a Workflow

1. Log in to the dashboard
2. Drag nodes from the left sidebar onto the canvas
3. Connect node outputs to inputs by clicking and dragging
4. Configure node properties in the right panel
5. Click "Run" to execute the workflow

### API Access

Use your API key to execute workflows programmatically:

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
| **Text to Image** | Generate images from prompts | Prompt | Image |
| **Image to Video** | Animate static images | Image | Video |
| **Text to Video** | Generate video from text | Prompt | Video |
| **Text to Speech** | Convert text to audio | Text | Audio |
| **Music Generation** | AI music creation (Suno) | Prompt | Audio |

### Audio Nodes

| Node | Description | Inputs | Outputs |
|------|-------------|--------|---------|
| **Audio Merge** | Combine audio tracks | Audio 1, Audio 2 | Audio |
| **Audio Trim** | Cut audio clips | Audio | Audio |
| **Voice Clone** | Clone voices | Sample, Text | Audio |
| **Speech to Text** | Transcription | Audio | Text |

### Editing Nodes

| Node | Description | Inputs | Outputs |
|------|-------------|--------|---------|
| **Video Merge** | Combine videos | Video 1, Video 2 | Video |
| **Video Trim** | Cut video clips | Video | Video |
| **Add Audio** | Add audio to video | Video, Audio | Video |
| **Add Subtitles** | Overlay captions | Video, Text | Video |
| **Resize/Crop** | Change dimensions | Video | Video |
| **Filters & Effects** | Visual effects | Video | Video |

### Output Nodes

| Node | Description | Inputs |
|------|-------------|--------|
| **Video Output** | Export MP4 | Video |
| **Image Output** | Export PNG/JPG | Image |
| **Audio Output** | Export MP3/WAV | Audio |

### Utility Nodes

| Node | Description | Inputs | Outputs |
|------|-------------|--------|---------|
| **Delay** | Wait timer | Any | Any |
| **Condition** | If/else logic | Any | True, False |
| **Loop** | Repeat nodes | Any | Any, Iteration |

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

- Verify API keys are correct in `.env`
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
chmod 775 temp/ logs/
chown -R www-data:www-data .
```

### Log Files

| Log | Location | Description |
|-----|----------|-------------|
| PHP Errors | `logs/php_errors.log` | PHP runtime errors |
| Worker | `logs/worker.log` | Background worker output |
| Cron | `logs/cron.log` | Scheduled task output |
| Apache/Nginx | `/var/log/apache2/` or `/var/log/nginx/` | Web server logs |

### Debug Mode

Enable debug mode for detailed error messages:

```env
APP_DEBUG=true
```

⚠️ **Never enable debug mode in production!**

## Development

### Running Tests

```bash
# API tests
php tests/test-api.php

# Frontend tests (run in browser console on editor page)
# Copy and paste contents of tests/test-nodes.js
```

### Adding New Node Types

1. Add node definition to `assets/js/nodes.js`:

```javascript
'my-custom-node': {
    type: 'my-custom-node',
    category: 'editing', // input, generation, audio, editing, output, utility
    name: 'My Custom Node',
    description: 'Description of what it does',
    icon: 'lucide-icon-name',
    inputs: [
        { id: 'input1', type: 'video', label: 'Input Video' }
    ],
    outputs: [
        { id: 'output1', type: 'video', label: 'Output Video' }
    ],
    fields: [
        {
            id: 'setting1',
            type: 'select', // text, textarea, number, select, checkbox, slider, color, file
            label: 'Setting',
            default: 'option1',
            options: [
                { value: 'option1', label: 'Option 1' },
                { value: 'option2', label: 'Option 2' }
            ]
        }
    ],
    preview: {
        type: 'video',
        source: 'output'
    },
    defaultData: {
        setting1: 'option1'
    }
}
```

2. Add node to sidebar in `index.php`

3. Add execution logic to `worker.php`:

```php
case 'my-custom-node':
    return callExternalApi('jsoncut', 'my-action', [
        'input_url' => $inputData['input1'] ?? '',
        'setting' => $inputData['setting1'] ?? 'option1'
    ]);
```

### Project Structure

```
aikaflow/
├── api/                  # Backend API endpoints
│   ├── auth/             # Authentication endpoints
│   ├── workflows/        # Workflow CRUD & execution
│   ├── proxy/            # External API proxies
│   ├── media/            # File upload/management
│   └── user/             # User settings
├── assets/               # Frontend assets
│   ├── css/              # Stylesheets
│   └── js/               # JavaScript modules
├── includes/             # PHP includes
│   ├── config.php        # Configuration
│   ├── db.php            # Database connection
│   └── auth.php          # Authentication
├── logs/                 # Application logs
├── temp/                 # Temporary files
├── tests/                # Test scripts
├── .env                  # Environment config
├── index.php             # Main editor
├── worker.php            # Background worker
└── cron.php              # Scheduled tasks
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

### Security Headers

The `.htaccess` file sets these security headers:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
```

### API Authentication

All API endpoints (except `/api/ping.php` and `/api/status.php`) require authentication via:

1. **Session Cookie** - For browser-based access
2. **API Key Header** - `X-API-Key: your_key` for programmatic access

## Performance Optimization

### Caching

Consider adding:

- **OPcache** for PHP bytecode caching
- **Redis/Memcached** for session and query caching
- **CDN** for static assets

### Database

```sql
-- Add indexes for better performance
CREATE INDEX idx_workflows_user_updated ON workflows(user_id, updated_at);
CREATE INDEX idx_executions_user_status ON workflow_executions(user_id, status);
CREATE INDEX idx_tasks_execution_status ON node_tasks(execution_id, status);
```

### Worker Scaling

For high-traffic deployments, run multiple workers:

```ini
# supervisor.conf
[program:aikaflow-worker]
numprocs=4
process_name=%(program_name)s_%(process_num)02d
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

### v1.0.0 (2024-XX-XX)

- Initial release
- Visual node-based workflow editor
- Support for RunningHub.ai, Kie.ai, JsonCut.com
- BunnyCDN integration
- Background task processing
- User authentication with API keys

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Credits

- [Tailwind CSS](https://tailwindcss.com/) - Styling
- [Lucide Icons](https://lucide.dev/) - Icons
- [Inter Font](https://rsms.me/inter/) - Typography

## Support

- 📖 Documentation: [docs.aikaflow.com](https://docs.aikaflow.com)
- 🐛 Issues: [GitHub Issues](https://github.com/yourusername/aikaflow/issues)
- 💬 Discussions: [GitHub Discussions](https://github.com/yourusername/aikaflow/discussions)
- 📧 Email: support@aikaflow.com

---

Made with ❤️ by the AIKAFLOW Team