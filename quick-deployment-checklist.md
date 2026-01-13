## AIKAFLOW Deployment Checklist

### Pre-Deployment
- [ ] PHP 8.1+ installed
- [ ] MySQL 8.0+ installed
- [ ] Required PHP extensions (pdo_mysql, curl, json, mbstring)
- [ ] Apache mod_rewrite enabled
- [ ] SSL certificate configured

### Configuration
- [ ] Copy .env.example to .env
- [ ] Set APP_URL to production domain
- [ ] Set APP_DEBUG to false
- [ ] Configure database credentials
- [ ] Add external API keys
- [ ] Configure BunnyCDN credentials

### Database
- [ ] Create database
- [ ] Create database user with privileges
- [ ] Run install.php
- [ ] Verify tables created

### Security
- [ ] Delete install.php
- [ ] Set proper file permissions (755 for dirs, 644 for files)
- [ ] Verify .htaccess is working
- [ ] Test HTTPS redirect
- [ ] Verify sensitive files are protected

### Background Processing
- [ ] Configure cron job for cron.php
- [ ] Start worker process
- [ ] Verify worker is processing tasks

### Testing
- [ ] Test user registration
- [ ] Test user login
- [ ] Test workflow creation
- [ ] Test workflow execution
- [ ] Test file uploads
- [ ] Test external API connections

### Monitoring
- [ ] Check logs directory is writable
- [ ] Set up log rotation
- [ ] Configure error alerting (optional)

### Post-Deployment
- [ ] Clear any test data
- [ ] Create production admin user
- [ ] Document API keys location
- [ ] Set up backups