# Config Manager – Zabbix Module

Enterprise Configuration Backup, Change Detection & Diff Viewer for network devices.

---

## Features

- **Device Inventory** — Cisco IOS/NX-OS, Fortinet, MikroTik, Juniper
- **Configuration Backup** — SSH via Netmiko, stored in `/opt/config-backups/`
- **Change Detection** — SHA256 hash comparison, line-level diff
- **Diff Viewer** — Side-by-side GitHub-style diff with search
- **Dashboard** — KPI cards, activity timeline, change summary

---

## Requirements

| Component  | Minimum     |
|------------|-------------|
| Zabbix     | 7.0+        |
| PHP        | 8.0+        |
| Python     | 3.8+        |
| Netmiko    | 4.0+        |
| MySQL      | 5.7+ / MariaDB 10.3+ |

---

## Installation

### 1. Copy module files

```bash
cp -r zabbix-config-module /usr/share/zabbix/modules/
```

### 2. Create backup directory

```bash
mkdir -p /opt/config-backups
chown www-data:www-data /opt/config-backups
chmod 750 /opt/config-backups
```

### 3. Install Python dependencies

```bash
pip3 install netmiko
# Verify
python3 -c "import netmiko; print('Netmiko OK:', netmiko.__version__)"
```

### 4. Create database tables

```bash
mysql -u <zabbix_user> -p <zabbix_database> \
  < /usr/share/zabbix/modules/zabbix-config-module/sql/schema.sql
```

Your DB credentials are in `/etc/zabbix/zabbix_server.conf`.

### 5. Enable module in Zabbix

1. Log in as Super Admin
2. Go to **Administration → General → Modules**
3. Click **Scan directory**
4. Find **Config Manager** and click **Enable**

### 6. Navigate to the module

Go to **Configuration → Config Manager** in the left sidebar.

---

## Backup Script Usage (manual test)

```bash
python3 /usr/share/zabbix/modules/zabbix-config-module/scripts/backup.py \
  --ip 192.168.1.1 \
  --vendor cisco_ios \
  --user admin \
  --pass mysecret \
  --port 22 \
  --out /tmp/test-backup.cfg
```

### Supported vendors

| Vendor Key    | Device Type       | Command              |
|---------------|-------------------|----------------------|
| `cisco_ios`   | Cisco IOS/IOS-XE  | `show running-config`|
| `cisco_nxos`  | Cisco NX-OS       | `show running-config`|
| `fortinet`    | FortiOS           | `show full-configuration` |
| `mikrotik`    | RouterOS          | `/export`            |
| `juniper`     | Junos OS          | `show configuration` |

---

## Backup Storage Structure

```
/opt/config-backups/
└── Router-01/
    ├── latest.cfg
    ├── 2026-06-20_0100.cfg
    └── 2026-06-21_0100.cfg
```

---

## Security Notes

- Device passwords are AES-256-CBC encrypted before storage
- CSRF validation is enforced on all write operations
- All DB inputs are escaped via Zabbix `zbx_dbstr()`
- Backup directory should not be web-accessible

---

## Troubleshooting

**"Tables missing" error on first load**
→ Run the SQL schema (Step 4 above)

**Backup fails with "Authentication failed"**
→ Verify username/password and that SSH is enabled on the device

**Backup fails with "Connection timed out"**
→ Check firewall rules between Zabbix server and device IP/port

**Empty config received**
→ Check that the user has privilege level 15 (Cisco) or equivalent read access

**`netmiko` not found**
→ Run: `pip3 install netmiko` — make sure pip3 installs for the same Python as `python3`

---

## Timezone Fix (Scheduler not running)

The scheduler compares `next_run_at` (stored by MySQL) with `NOW()` (MySQL server time).
If your Docker MySQL timezone differs from the Zabbix PHP container timezone, scheduled
backups will never fire.

### Diagnose

Open Config Manager → Dashboard. The status bar at the top shows:
- **MySQL time** — what the database thinks the time is
- **PHP time** — what the web server thinks the time is

If they differ by more than 2 minutes you have a timezone mismatch.

### Fix Option 1 — Set MySQL timezone in Docker Compose

Add to your MySQL service environment:

```yaml
environment:
  TZ: Asia/Kolkata          # match your server timezone
  MYSQL_ROOT_PASSWORD: ...
```

Or via my.cnf:

```ini
[mysqld]
default-time-zone = '+05:30'
```

Then restart: `docker-compose restart mysql-server`

### Fix Option 2 — Set timezone in running MySQL container

```bash
# Connect to MySQL
sudo docker exec -it mysql-server mysql -uroot -p

# Check current timezone
SELECT NOW(), @@global.time_zone, @@session.time_zone;

# Set to UTC (recommended — then set PHP to UTC too)
SET GLOBAL time_zone = '+00:00';
SET SESSION time_zone = '+00:00';

# Or set to your local timezone (IST example)
SET GLOBAL time_zone = '+05:30';
```

### Fix Option 3 — Set PHP timezone to match MySQL

In your Zabbix PHP container, add to `php.ini` or `zabbix.conf.php`:

```ini
date.timezone = Asia/Kolkata
```

Or in `zabbix.conf.php`:
```php
date_default_timezone_set('Asia/Kolkata');
```

### Fix Option 4 — Reset next_run_at for all devices (quickest)

After fixing timezone, reset all next_run_at values so they recalculate from NOW():

```sql
UPDATE config_devices
SET next_run_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)
WHERE schedule_interval != 'disabled' AND enabled = 1;
```

Run via Docker:
```bash
sudo docker exec -i mysql-server mysql -uzabbix -piqlab@2025 zabbix << 'SQL'
UPDATE config_devices
SET next_run_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)
WHERE schedule_interval != 'disabled' AND enabled = 1;
SELECT device_id, name, schedule_interval, next_run_at, NOW() AS mysql_now FROM config_devices;
SQL
```

---

## Background Scheduler (Automatic Backups without Browser)

By default the scheduler only runs when someone has the Zabbix web UI open.
To make it run continuously in the background, set up one of these options:

---

### Option 1 — Cron inside the Zabbix web container (Recommended for Docker)

```bash
# Step 1: Install Python dependencies inside the container
sudo docker exec -it zabbix-web pip3 install mysql-connector-python cryptography

# Step 2: Install cron inside the container (if not present)
sudo docker exec -it zabbix-web apt-get install -y cron

# Step 3: Add the cron job (runs every minute)
sudo docker exec -it zabbix-web bash -c \
  'echo "* * * * * python3 /usr/share/zabbix/modules/ConfigManager/scripts/scheduler.py \
  --db-host mysql-server \
  --db-name zabbix \
  --db-user zabbix \
  --db-pass iqlab@2025 \
  >> /var/log/configmanager-scheduler.log 2>&1" \
  | crontab -'

# Step 4: Start cron inside the container
sudo docker exec -it zabbix-web service cron start

# Verify cron is running
sudo docker exec -it zabbix-web service cron status

# Watch the log
sudo docker exec -it zabbix-web tail -f /var/log/configmanager-scheduler.log
```

---

### Option 2 — Docker sidecar service via docker-compose

Add this service to your `docker-compose.yml`:

```yaml
  configmanager-scheduler:
    image: python:3.11-slim
    restart: unless-stopped
    volumes:
      - /usr/share/zabbix/modules/ConfigManager/scripts:/scripts:ro
      - /opt/config-backups:/opt/config-backups
    environment:
      DB_SERVER:   mysql-server
      DB_NAME:     zabbix
      DB_USER:     zabbix
      DB_PASSWORD: iqlab@2025
    depends_on:
      - mysql-server
    command: >
      bash -c "
        pip install mysql-connector-python cryptography netmiko -q &&
        while true; do
          python3 /scripts/scheduler.py
            --db-host mysql-server
            --db-name zabbix
            --db-user zabbix
            --db-pass iqlab@2025;
          sleep 60;
        done
      "
    networks:
      - zabbix-net
```

Then: `docker-compose up -d configmanager-scheduler`

---

### Option 3 — Cron on the Docker host

```bash
# On the Ubuntu host machine, run the scheduler via docker exec every minute
crontab -e

# Add this line:
* * * * * sudo docker exec zabbix-web python3 /usr/share/zabbix/modules/ConfigManager/scripts/scheduler.py --db-host mysql-server --db-name zabbix --db-user zabbix --db-pass iqlab@2025 >> /var/log/configmanager-scheduler.log 2>&1
```

---

### Test the scheduler manually

```bash
# Test inside the container
sudo docker exec -it zabbix-web python3 \
  /usr/share/zabbix/modules/ConfigManager/scripts/scheduler.py \
  --db-host mysql-server \
  --db-name zabbix \
  --db-user zabbix \
  --db-pass iqlab@2025 \
  --dry-run

# --dry-run shows which devices are due without running backups
```

### Install Python dependencies

```bash
sudo docker exec -it zabbix-web pip3 install \
  mysql-connector-python \
  cryptography \
  netmiko
```

