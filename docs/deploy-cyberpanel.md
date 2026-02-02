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

Cron job diperlukan untuk menjalankan background tasks secara otomatis. AIKAFLOW memiliki 2 cron job yang perlu disetup.

### 7.1 Akses Halaman Cron Job

1. Login ke CyberPanel: `https://your-server-ip:8090`
2. Buka **Websites** → **List Websites**
3. Klik nama domain Anda (contoh: `apps.vandal.id`)
4. Klik tombol **Cron Jobs**
5. Klik tab **TAMBAH CRON**

### 7.2 Format Field Cron di CyberPanel

| Field                   | Deskripsi               | Nilai                                |
| ----------------------- | ----------------------- | ------------------------------------ |
| **Pre-defined**         | Pilih jadwal preset     | Pilih manual atau preset             |
| **Menit**               | Menit ke-berapa (0-59)  | `*` = setiap menit, `0` = menit ke-0 |
| **Jam**                 | Jam ke-berapa (0-23)    | `*` = setiap jam, `3` = jam 3 pagi   |
| **Tanggal dalam bulan** | Tanggal (1-31)          | `*` = setiap hari                    |
| **Bulan**               | Bulan (1-12)            | `*` = setiap bulan                   |
| **Hari dalam pekan**    | Hari (0-7, 0/7=Minggu)  | `*` = setiap hari                    |
| **Perintah**            | Command yang dijalankan | Path lengkap ke script               |

---

### 7.3 Cron Job 1: System Cleanup (Harian)

Membersihkan workflow executions, task queue, dan log lama.

**Isi Form:**

| Field                   | Nilai                         |
| ----------------------- | ----------------------------- |
| **Pre-defined**         | `Setiap hari` atau isi manual |
| **Menit**               | `0`                           |
| **Jam**                 | `3`                           |
| **Tanggal dalam bulan** | `*`                           |
| **Bulan**               | `*`                           |
| **Hari dalam pekan**    | `*`                           |
| **Perintah**            | _(lihat di bawah)_            |

**Perintah (copy paste ini):**

```bash
/usr/local/lsws/lsphp81/bin/php /home/yourdomain.com/public_html/cron/cleanup.php >> /home/yourdomain.com/public_html/logs/cleanup.log 2>&1
```

> **Jadwal:** Berjalan setiap hari jam 03:00 pagi

Klik tombol **Tambah cron** untuk menyimpan.

---

### 7.4 Cron Job 2: Content Cleanup (Harian)

Membersihkan konten user yang sudah expired dari database dan storage.

**Isi Form:**

| Field                   | Nilai                         |
| ----------------------- | ----------------------------- |
| **Pre-defined**         | `Setiap hari` atau isi manual |
| **Menit**               | `0`                           |
| **Jam**                 | `2`                           |
| **Tanggal dalam bulan** | `*`                           |
| **Bulan**               | `*`                           |
| **Hari dalam pekan**    | `*`                           |
| **Perintah**            | _(lihat di bawah)_            |

**Perintah (copy paste ini):**

```bash
/usr/local/lsws/lsphp81/bin/php /home/yourdomain.com/public_html/api/cron/cleanup-expired-content.php >> /home/yourdomain.com/public_html/logs/content-cleanup.log 2>&1
```

> **Jadwal:** Berjalan setiap hari jam 02:00 pagi

Klik tombol **Tambah cron** untuk menyimpan.

---

### 7.5 Contoh Jadwal Lainnya

| Jadwal                 | Menit | Jam | Tgl | Bulan | Hari |
| ---------------------- | ----- | --- | --- | ----- | ---- |
| Setiap menit           | `*`   | `*` | `*` | `*`   | `*`  |
| Setiap 5 menit         | `*/5` | `*` | `*` | `*`   | `*`  |
| Setiap jam             | `0`   | `*` | `*` | `*`   | `*`  |
| Setiap hari jam 3 pagi | `0`   | `3` | `*` | `*`   | `*`  |
| Setiap Minggu          | `0`   | `0` | `*` | `*`   | `0`  |
| Setiap tanggal 1       | `0`   | `0` | `1` | `*`   | `*`  |

---

### 7.6 Verifikasi Cron Job

Setelah menambahkan cron job:

1. Klik tab **FETCH CURRENT CRON JOBS** untuk melihat daftar cron yang sudah ditambahkan
2. Tunggu beberapa waktu, lalu cek log file:
   ```bash
   # Via SSH
   tail -f /home/yourdomain.com/public_html/logs/cleanup.log
   tail -f /home/yourdomain.com/public_html/logs/content-cleanup.log
   ```

---

### 7.7 Via SSH (Alternative)

Jika ingin setup via terminal SSH:

```bash
# Edit crontab
crontab -e

# Tambahkan baris berikut:

# System Cleanup - setiap hari jam 3 pagi
0 3 * * * /usr/local/lsws/lsphp81/bin/php /home/yourdomain.com/public_html/cron/cleanup.php >> /home/yourdomain.com/public_html/logs/cleanup.log 2>&1

# Content Cleanup - setiap hari jam 2 pagi
0 2 * * * /usr/local/lsws/lsphp81/bin/php /home/yourdomain.com/public_html/api/cron/cleanup-expired-content.php >> /home/yourdomain.com/public_html/logs/content-cleanup.log 2>&1
```

Simpan dan keluar (`Ctrl+X`, `Y`, `Enter` jika menggunakan nano).

> [!NOTE]
> Sesuaikan path PHP (`lsphp81`) dengan versi PHP yang diinstall di CyberPanel.
> Cek dengan: `ls /usr/local/lsws/`

> [!IMPORTANT]
> Ganti `yourdomain.com` dengan nama domain Anda yang sebenarnya!

---

## 🔄 Langkah 8: Setup PHP Worker Daemon

Worker daemon diperlukan untuk memproses workflow dan task queue secara background. Worker akan berjalan terus-menerus untuk memproses antrian task.

### 8.1 Apa itu Worker Daemon?

| Aspek       | Penjelasan                                                 |
| ----------- | ---------------------------------------------------------- |
| **Fungsi**  | Memproses task queue (workflow execution, node processing) |
| **Mode**    | Berjalan terus-menerus (daemon) di background              |
| **File**    | `worker.php --daemon`                                      |
| **Restart** | Otomatis restart jika crash (via Supervisor)               |

---

### 8.2 Install Supervisor

Supervisor adalah process manager yang menjaga worker tetap berjalan.

```bash
# Login SSH ke server
ssh root@your-server-ip

# Install Supervisor (Ubuntu/Debian)
apt update
apt install supervisor -y

# Atau untuk CentOS/RHEL
yum install supervisor -y

# Start dan enable supervisor
systemctl enable supervisor
systemctl start supervisor

# Cek status
systemctl status supervisor
```

---

### 8.3 Buat Konfigurasi Supervisor

```bash
# Buat file konfigurasi untuk AIKAFLOW worker
nano /etc/supervisor/conf.d/aikaflow-worker.conf
```

**Isi dengan konfigurasi berikut:**

```ini
[program:aikaflow-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/local/lsws/lsphp81/bin/php /home/yourdomain.com/public_html/worker.php --daemon
directory=/home/yourdomain.com/public_html
autostart=true
autorestart=true
startsecs=1
startretries=3
user=yourdomain.yourdomain
numprocs=1
redirect_stderr=true
stdout_logfile=/home/yourdomain.com/public_html/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
stopwaitsecs=60
stopsignal=TERM
```

> [!IMPORTANT]
> **Ganti nilai berikut sesuai domain Anda:**
>
> - `yourdomain.com` → nama domain Anda (contoh: `apps.vandal.id`)
> - `lsphp81` → versi PHP yang terinstall (cek: `ls /usr/local/lsws/`)

**Simpan file:** `Ctrl+X`, lalu `Y`, lalu `Enter`

---

### 8.4 Penjelasan Konfigurasi

| Parameter        | Nilai                     | Penjelasan                              |
| ---------------- | ------------------------- | --------------------------------------- |
| `command`        | `php worker.php --daemon` | Menjalankan worker dalam mode daemon    |
| `directory`      | `/home/.../public_html`   | Working directory                       |
| `autostart`      | `true`                    | Otomatis start saat server boot         |
| `autorestart`    | `true`                    | Otomatis restart jika worker crash      |
| `startsecs`      | `1`                       | Worker dianggap running setelah 1 detik |
| `startretries`   | `3`                       | Coba restart maksimal 3x jika gagal     |
| `user`           | `yourdomain.yourdomain`   | User yang menjalankan worker            |
| `numprocs`       | `1`                       | Jumlah worker processes                 |
| `stdout_logfile` | `.../logs/worker.log`     | Lokasi log file                         |
| `stopwaitsecs`   | `60`                      | Waktu tunggu graceful shutdown          |

---

### 8.5 Aktifkan Worker

```bash
# Reload konfigurasi supervisor
supervisorctl reread

# Update dengan konfigurasi baru
supervisorctl update

# Start worker
supervisorctl start aikaflow-worker:*

# Cek status worker
supervisorctl status
```

**Output yang diharapkan:**

```
aikaflow-worker:aikaflow-worker_00   RUNNING   pid 12345, uptime 0:00:05
```

---

### 8.6 Perintah Supervisor yang Berguna

| Perintah                                                   | Fungsi                    |
| ---------------------------------------------------------- | ------------------------- |
| `supervisorctl status`                                     | Lihat status semua worker |
| `supervisorctl start aikaflow-worker:*`                    | Start worker              |
| `supervisorctl stop aikaflow-worker:*`                     | Stop worker               |
| `supervisorctl restart aikaflow-worker:*`                  | Restart worker            |
| `supervisorctl tail -f aikaflow-worker:aikaflow-worker_00` | Lihat log real-time       |
| `supervisorctl reload`                                     | Reload semua konfigurasi  |

---

### 8.7 Verifikasi Worker Berjalan

```bash
# Cek log worker
tail -f /home/yourdomain.com/public_html/logs/worker.log

# Contoh output yang benar:
# [2026-02-02 12:00:00] Worker started (ID: server_12345)
# [2026-02-02 12:00:05] No pending tasks
# [2026-02-02 12:00:10] Processing task #1: node_execution
# [2026-02-02 12:00:15] Task #1 completed
```

---

### 8.8 Alternatif: Tanpa Supervisor (Via Cron)

Jika tidak bisa install Supervisor, gunakan cron untuk menjaga worker tetap berjalan:

**Tambahkan cron job baru di CyberPanel:**

| Field                   | Nilai              |
| ----------------------- | ------------------ |
| **Pre-defined**         | `Setiap menit`     |
| **Menit**               | `*`                |
| **Jam**                 | `*`                |
| **Tanggal dalam bulan** | `*`                |
| **Bulan**               | `*`                |
| **Hari dalam pekan**    | `*`                |
| **Perintah**            | _(lihat di bawah)_ |

**Perintah:**

```bash
/usr/local/lsws/lsphp81/bin/php /home/yourdomain.com/public_html/worker.php >> /home/yourdomain.com/public_html/logs/worker.log 2>&1
```

> [!NOTE]
> Metode cron akan menjalankan worker setiap menit.
> Worker akan proses semua pending tasks lalu berhenti.
> Ini kurang ideal dibanding Supervisor, tapi tetap berfungsi.

---

### 8.9 Troubleshooting Worker

| Masalah                   | Solusi                                                                                             |
| ------------------------- | -------------------------------------------------------------------------------------------------- |
| Worker tidak start        | Cek log: `tail -100 /var/log/supervisor/supervisord.log`                                           |
| Permission denied         | Set owner: `chown -R yourdomain.yourdomain:yourdomain.yourdomain /home/yourdomain.com/public_html` |
| PHP path tidak ditemukan  | Cek path: `ls /usr/local/lsws/` dan sesuaikan                                                      |
| Worker crash terus        | Cek log worker: `tail -100 /home/.../logs/worker.log`                                              |
| Database connection error | Pastikan `.env` sudah benar                                                                        |

**Reset worker jika bermasalah:**

```bash
supervisorctl stop aikaflow-worker:*
supervisorctl reread
supervisorctl update
supervisorctl start aikaflow-worker:*
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
