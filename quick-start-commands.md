# 1. Setup
cp .env.example .env
# Edit .env with your credentials

# 2. Install database
# Visit http://yourdomain.com/install.php

# 3. Delete install file
rm install.php

# 4. Start worker
chmod +x cron-worker.sh
./cron-worker.sh start

# 5. Add cron job
echo "* * * * * php $(pwd)/cron.php >> $(pwd)/logs/cron.log 2>&1" | crontab -

# 6. Run tests
php tests/test-api.php