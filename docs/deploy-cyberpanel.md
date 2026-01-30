# 🚀 Tutorial Deploy AIKAFLOW ke CyberPanel

Panduan lengkap untuk mendeploy aplikasi AIKAFLOW ke server dengan CyberPanel.

---

## 📋 Persyaratan Sistem

| Komponen | Minimum | Rekomendasi |
| -------- | ------- | ----------- |
| PHP      | 8.1+    | 8.2+        |
| MySQL    | 8.0+    | 8.0+        |
| RAM      | 1 GB    | 2 GB+       |
| Storage  | 5 GB    | 20 GB+      |

### Ekstensi PHP yang Dibutuhkan

- `pdo_mysql`
- `curl`
- `json`
- `mbstring`
- `openssl`
- `fileinfo`

---

## 📦 Langkah 1: Persiapan di CyberPanel

### 1.1 Login ke CyberPanel

```
https://your-server-ip:8090
```

### 1.2 Buat Website Baru

1. Buka menu **Websites** → **Create Website**
2. Isi form:
   - **Select Package**: Default atau custom
   - **Select Owner**: admin
   - **Domain Name**: `yourdomain.com`
   - **Email**: `admin@yourdomain.com`
   - **PHP**: Pilih **PHP 8.1** atau lebih tinggi
3. Klik **Create Website**

### 1.3 Buat Database MySQL

1. Buka menu **Databases** → **Create Database**
2. Isi form:
   - **Select Website**: `yourdomain.com`
   - **Database Name**: `aikaflow_db`
   - **Username**: `aikaflow_user`
   - **Password**: `[buat password yang kuat]`
3. Klik **Create Database**
4. **⚠️ CATAT kredensial database ini!**

---

## 📁 Langkah 2: Upload Source Code

### Opsi A: Via File Manager

1. Buka **Websites** → **List Websites** → Pilih domain → **File Manager**
2. Masuk ke folder `public_html`
3. Hapus file default (`index.html`)
4. Upload file ZIP project AIKAFLOW
5. Extract file ZIP

### Opsi B: Via SSH/Terminal (Rekomendasi)

```bash
# 1. Akses SSH ke server
ssh root@your-server-ip

# 2. Masuk ke direktori website
cd /home/yourdomain.com/public_html

# 3. Hapus file default
rm -rf *

# 4. Clone dari Git (jika menggunakan Git)
git clone https://github.com/username/aikaflow.git .

# Atau upload via SCP dari lokal
# scp -r /path/to/aikaflow/* root@your-server-ip:/home/yourdomain.com/public_html/
```

---

## ⚙️ Langkah 3: Konfigurasi Environment

### 3.1 Buat File .env

```bash
# Masuk ke direktori project
cd /home/yourdomain.com/public_html

# Copy template environment
cp .env.example .env

# Edit file .env
nano .env
```

### 3.2 Isi Konfigurasi .env

```env
# Application
APP_URL=https://yourdomain.com
APP_DEBUG=false

# Database (sesuaikan dengan yang dibuat di Step 1.3)
DB_HOST=localhost
DB_PORT=3306
DB_NAME=yourdomain_aikaflow_db
DB_USER=yourdomain_aikaflow_user
DB_PASS=your_database_password

# Session Security
SESSION_SECURE=true

# BunnyCDN (opsional - untuk storage)
BUNNY_STORAGE_ZONE=
BUNNY_ACCESS_KEY=
BUNNY_STORAGE_URL=https://storage.bunnycdn.com
BUNNY_CDN_URL=
```

> [!IMPORTANT]
> Pastikan `APP_DEBUG=false` untuk production!
> Prefix database dan user di CyberPanel biasanya: `domain_namadb`

---

## 🔐 Langkah 4: Set Permission

```bash
# Set ownership
chown -R yourdomain.yourdomain:yourdomain.yourdomain /home/yourdomain.com/public_html

# Set permission untuk direktori
find /home/yourdomain.com/public_html -type d -exec chmod 755 {} \;

# Set permission untuk file
find /home/yourdomain.com/public_html -type f -exec chmod 644 {} \;

# Buat dan set permission untuk direktori writable
mkdir -p /home/yourdomain.com/public_html/logs
mkdir -p /home/yourdomain.com/public_html/temp
chmod 755 /home/yourdomain.com/public_html/logs
chmod 755 /home/yourdomain.com/public_html/temp
```

---

## 🗄️ Langkah 5: Install Database

### 5.1 Jalankan Installer

1. Buka browser: `https://yourdomain.com/install.php`
2. Ikuti wizard instalasi
3. Masukkan kredensial database yang sudah dibuat
4. Tunggu proses selesai

### 5.2 Hapus File Installer (PENTING!)

```bash
rm /home/yourdomain.com/public_html/install.php
```

> [!CAUTION]
> **WAJIB hapus `install.php` setelah instalasi selesai!**
> Jika tidak dihapus, bisa diakses orang lain dan membahayakan keamanan.

---

## 🔒 Langkah 6: SSL Certificate

### Via CyberPanel (Let's Encrypt)

1. Buka **SSL** → **Manage SSL**
2. Pilih domain: `yourdomain.com`
3. Klik **Issue SSL**
4. Tunggu sampai proses selesai

### Verifikasi SSL

```bash
curl -I https://yourdomain.com
# Harus menampilkan: HTTP/2 200
```

---

## ⏰ Langkah 7: Setup Cron Job

Cron job diperlukan untuk menjalankan background tasks.

### Via CyberPanel

1. Buka **Websites** → **List Websites** → Pilih domain → **Cron Jobs**
2. Klik **Add New Cron Job**
3. Isi:
   - **Command**:
     ```
     /usr/local/lsws/lsphp81/bin/php /home/yourdomain.com/public_html/cron.php >> /home/yourdomain.com/public_html/logs/cron.log 2>&1
     ```
   - **Interval**: Every minute (`* * * * *`)
4. Klik **Add Cron Job**

### Via SSH (Alternative)

```bash
# Edit crontab
crontab -e

# Tambahkan baris berikut
* * * * * /usr/local/lsws/lsphp81/bin/php /home/yourdomain.com/public_html/cron.php >> /home/yourdomain.com/public_html/logs/cron.log 2>&1
```

> [!NOTE]
> Sesuaikan path PHP (`lsphp81`) dengan versi PHP yang diinstall di CyberPanel.
> Cek dengan: `ls /usr/local/lsws/`

---

## 🔄 Langkah 8: Setup Background Worker

Worker diperlukan untuk memproses workflow secara asynchronous.

### 8.1 Buat Supervisor Config

```bash
# Buat file konfigurasi supervisor
nano /etc/supervisor/conf.d/aikaflow-worker.conf
```

### 8.2 Isi Konfigurasi

```ini
[program:aikaflow-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/local/lsws/lsphp81/bin/php /home/yourdomain.com/public_html/worker.php
autostart=true
autorestart=true
user=yourdomain.yourdomain
numprocs=1
redirect_stderr=true
stdout_logfile=/home/yourdomain.com/public_html/logs/worker.log
stopwaitsecs=3600
```

### 8.3 Aktifkan Worker

```bash
# Reload supervisor
supervisorctl reread
supervisorctl update
supervisorctl start aikaflow-worker:*

# Cek status
supervisorctl status
```

### Alternative: Menggunakan cron-worker.sh

```bash
# Set executable
chmod +x /home/yourdomain.com/public_html/cron-worker.sh

# Jalankan
cd /home/yourdomain.com/public_html
./cron-worker.sh start
```

---

## ✅ Langkah 9: Verifikasi Deployment

### 9.1 Checklist Verifikasi

- [ ] Website dapat diakses via HTTPS
- [ ] Halaman login muncul
- [ ] Dapat register user baru
- [ ] Dapat login
- [ ] Worker status aktif (cek di dashboard)
- [ ] File upload berfungsi

### 9.2 Test Manual

```bash
# Test koneksi database
php -r "
\$pdo = new PDO('mysql:host=localhost;dbname=yourdomain_aikaflow_db', 'yourdomain_aikaflow_user', 'password');
echo 'Database OK';
"

# Test cron berjalan
tail -f /home/yourdomain.com/public_html/logs/cron.log

# Test worker berjalan
tail -f /home/yourdomain.com/public_html/logs/worker.log
```

---

## 🛠️ Troubleshooting

### Error 500 Internal Server Error

```bash
# Cek error log
tail -100 /home/yourdomain.com/logs/yourdomain.com.error_log

# Cek PHP error log
tail -100 /home/yourdomain.com/public_html/logs/php_errors.log
```

### Worker Tidak Berjalan

```bash
# Restart supervisor
supervisorctl restart aikaflow-worker:*

# Atau gunakan script
cd /home/yourdomain.com/public_html
./cron-worker.sh restart
```

### Permission Denied

```bash
# Reset ownership
chown -R yourdomain.yourdomain:yourdomain.yourdomain /home/yourdomain.com/public_html

# Reset permission
find /home/yourdomain.com/public_html -type d -exec chmod 755 {} \;
find /home/yourdomain.com/public_html -type f -exec chmod 644 {} \;
```

### Database Connection Error

1. Verifikasi kredensial di `.env`
2. Cek format nama database (dengan prefix domain)
3. Test koneksi manual via terminal

---

## 📊 Monitoring

### Log Files Location

| Log             | Path                                                   |
| --------------- | ------------------------------------------------------ |
| PHP Errors      | `/home/yourdomain.com/public_html/logs/php_errors.log` |
| Cron Log        | `/home/yourdomain.com/public_html/logs/cron.log`       |
| Worker Log      | `/home/yourdomain.com/public_html/logs/worker.log`     |
| LiteSpeed Error | `/home/yourdomain.com/logs/yourdomain.com.error_log`   |

### Setup Log Rotation

```bash
# Buat logrotate config
nano /etc/logrotate.d/aikaflow

# Isi:
/home/yourdomain.com/public_html/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0644 yourdomain.yourdomain yourdomain.yourdomain
}
```

---

## 🔄 Update Deployment

Untuk update aplikasi di masa depan:

```bash
# 1. Backup dulu
cd /home/yourdomain.com
tar -czvf backup-$(date +%Y%m%d).tar.gz public_html

# 2. Pull update (jika via Git)
cd /home/yourdomain.com/public_html
git pull origin main

# 3. Jalankan migrasi database (jika ada)
php migrations/run.php

# 4. Restart worker
supervisorctl restart aikaflow-worker:*

# 5. Clear cache (jika ada)
rm -rf temp/*
```

---

## 📞 Support

Jika mengalami masalah:

1. Cek semua log files
2. Pastikan semua persyaratan sistem terpenuhi
3. Verifikasi konfigurasi .env
4. Restart services jika diperlukan

---

**🎉 Selamat! AIKAFLOW sudah aktif di CyberPanel!**
