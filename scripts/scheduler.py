#!/usr/bin/env python3
"""
Config Manager – Background Scheduler
Runs independently of the Zabbix web UI.
Connects directly to the MySQL database, finds devices with due backups,
and executes backup.py for each one.

Run via cron every minute:
    * * * * * /usr/share/zabbix/modules/ConfigManager/scripts/scheduler.py \
              --config /etc/zabbix/zabbix_web.conf 2>&1 \
              | tee -a /var/log/configmanager-scheduler.log

Or run via Docker every minute — see README for docker-compose setup.
"""

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

try:
    import mysql.connector
except ImportError:
    # Try PyMySQL as fallback
    try:
        import pymysql
        import pymysql.cursors
        mysql = None
    except ImportError:
        print("[ERROR] Install mysql-connector-python or PyMySQL:")
        print("        pip3 install mysql-connector-python")
        print("        OR: pip3 install PyMySQL")
        sys.exit(1)


# ── Config file parsers ──────────────────────────────────────────────────────

def parse_zabbix_conf(path: str) -> dict:
    """Parse Zabbix server/web config file (key=value format)."""
    cfg = {}
    try:
        with open(path) as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                if '=' in line:
                    key, _, val = line.partition('=')
                    cfg[key.strip()] = val.strip()
    except FileNotFoundError:
        pass
    return cfg


def parse_php_conf(path: str) -> dict:
    """Parse Zabbix PHP config (zabbix.conf.php) for DB credentials."""
    cfg = {}
    patterns = {
        'DB_SERVER':   r"\$DB\['SERVER'\]\s*=\s*'([^']+)'",
        'DB_DATABASE': r"\$DB\['DATABASE'\]\s*=\s*'([^']+)'",
        'DB_USER':     r"\$DB\['USER'\]\s*=\s*'([^']+)'",
        'DB_PASSWORD': r"\$DB\['PASSWORD'\]\s*=\s*'([^']+)'",
        'DB_PORT':     r"\$DB\['PORT'\]\s*=\s*'?(\d+)'?",
    }
    try:
        with open(path) as f:
            content = f.read()
        for key, pat in patterns.items():
            m = re.search(pat, content)
            if m:
                cfg[key] = m.group(1)
    except FileNotFoundError:
        pass
    return cfg


def get_db_config(args) -> dict:
    """Resolve DB connection params from CLI args or config files."""
    # CLI args take priority
    if args.db_host and args.db_name and args.db_user:
        return {
            'host':     args.db_host,
            'database': args.db_name,
            'user':     args.db_user,
            'password': args.db_pass or '',
            'port':     args.db_port or 3306,
        }

    # Try Zabbix server config
    cfg_files = [
        args.config or '',
        '/etc/zabbix/zabbix_server.conf',
        '/etc/zabbix/web/zabbix.conf.php',
        '/usr/share/zabbix/conf/zabbix.conf.php',
        '/var/www/html/zabbix/conf/zabbix.conf.php',
    ]

    for path in cfg_files:
        if not path or not os.path.exists(path):
            continue

        if path.endswith('.php'):
            c = parse_php_conf(path)
            if c.get('DB_DATABASE'):
                return {
                    'host':     c.get('DB_SERVER',   '127.0.0.1'),
                    'database': c.get('DB_DATABASE',  'zabbix'),
                    'user':     c.get('DB_USER',      'zabbix'),
                    'password': c.get('DB_PASSWORD',  ''),
                    'port':     int(c.get('DB_PORT',  3306)),
                }
        else:
            c = parse_zabbix_conf(path)
            if c.get('DBName'):
                return {
                    'host':     c.get('DBHost',     '127.0.0.1'),
                    'database': c.get('DBName',      'zabbix'),
                    'user':     c.get('DBUser',      'zabbix'),
                    'password': c.get('DBPassword',  ''),
                    'port':     int(c.get('DBPort',  3306)),
                }

    # Environment variables (Docker-friendly)
    if os.environ.get('DB_SERVER'):
        return {
            'host':     os.environ.get('DB_SERVER',   '127.0.0.1'),
            'database': os.environ.get('DB_NAME',     'zabbix'),
            'user':     os.environ.get('DB_USER',     'zabbix'),
            'password': os.environ.get('DB_PASSWORD', ''),
            'port':     int(os.environ.get('DB_PORT', 3306)),
        }

    raise RuntimeError(
        "Cannot find DB credentials. Pass --db-host/--db-name/--db-user/--db-pass "
        "or set DB_SERVER/DB_NAME/DB_USER/DB_PASSWORD environment variables, "
        "or pass --config pointing to zabbix_server.conf or zabbix.conf.php"
    )


# ── DB connection ────────────────────────────────────────────────────────────

def connect_db(db_cfg: dict):
    """Return a DB connection using mysql-connector or PyMySQL."""
    global mysql
    if mysql is not None:
        return mysql.connector.connect(
            host=db_cfg['host'],
            database=db_cfg['database'],
            user=db_cfg['user'],
            password=db_cfg['password'],
            port=db_cfg['port'],
            connection_timeout=10,
            autocommit=True,
        )
    else:
        import pymysql
        return pymysql.connect(
            host=db_cfg['host'],
            db=db_cfg['database'],
            user=db_cfg['user'],
            password=db_cfg['password'],
            port=db_cfg['port'],
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=True,
            connect_timeout=10,
        )


def fetchall(conn, sql: str, params: tuple = ()) -> list:
    with conn.cursor() as cur:
        cur.execute(sql, params)
        rows = cur.fetchall()
        if rows and not isinstance(rows[0], dict):
            cols = [d[0] for d in cur.description]
            rows = [dict(zip(cols, r)) for r in rows]
        return rows or []


def execute(conn, sql: str, params: tuple = ()) -> None:
    with conn.cursor() as cur:
        cur.execute(sql, params)


# ── Password decryption (mirrors Repository.php) ─────────────────────────────

def decrypt_password(stored: str, secret_key: str = 'configmanager_default_key_change_me') -> str:
    """AES-256-CBC decrypt — mirrors Repository.php decryptPassword()."""
    try:
        from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
        from cryptography.hazmat.backends import default_backend
        import base64

        key = hashlib.sha256(secret_key.encode()).digest()[:32]
        raw = base64.b64decode(stored)
        iv  = raw[:16]
        enc = raw[16:]

        cipher  = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
        dec     = cipher.decryptor()
        padded  = dec.update(enc) + dec.finalize()

        # Remove PKCS7 padding
        pad_len = padded[-1]
        return padded[:-pad_len].decode('utf-8', errors='replace')

    except ImportError:
        print("[WARN] cryptography package not installed — passwords cannot be decrypted.")
        print("       pip3 install cryptography")
        return ''
    except Exception as e:
        print(f"[WARN] Password decrypt failed: {e}")
        return ''


# ── Noise stripping ──────────────────────────────────────────────────────────

NOISE_PATTERNS = [
    'Building configuration',
    'Current configuration',
    'Load for five',
    'Time source is',
    '! Last configuration change',
    '! NVRAM config last updated',
    '# generated by',
    '# by RouterOS',
    '# Config Manager',
    '# Device :',
    '# Vendor :',
    '# Time   :',
    '# ===',
]


def strip_noise(text: str) -> str:
    result = []
    for line in text.splitlines():
        stripped = line.strip()
        if not any(stripped.startswith(p) or p in stripped for p in NOISE_PATTERNS):
            result.append(line)
    return '\n'.join(result)


# ── Backup a single device ───────────────────────────────────────────────────

def backup_device(conn, device: dict, script_dir: str, secret_key: str) -> bool:
    device_id = device['device_id']
    name      = device['name']
    password  = decrypt_password(device['password'], secret_key)

    ts       = datetime.now().strftime('%Y-%m-%d_%H%M')
    safe_name = re.sub(r'[^a-zA-Z0-9_\-]', '_', name)
    out_dir   = Path(f'/opt/config-backups/{safe_name}')
    out_dir.mkdir(parents=True, exist_ok=True)
    filepath  = str(out_dir / f'{ts}.cfg')
    filename  = f'{ts}.cfg'

    # Create backup record with status=running
    execute(conn,
        "INSERT INTO config_backups (device_id, filename, filepath, status) "
        "VALUES (%s, %s, %s, 'running')",
        (device_id, filename, filepath))

    with conn.cursor() as cur:
        cur.execute('SELECT LAST_INSERT_ID() AS id')
        row = cur.fetchone()
        backup_id = int(row['id'] if isinstance(row, dict) else row[0])

    # Run backup.py
    backup_script = os.path.join(script_dir, 'backup.py')
    cmd = [
        'python3', backup_script,
        '--ip',     device['ip_address'],
        '--vendor', device['vendor'],
        '--user',   device['username'],
        '--pass',   password,
        '--port',   str(device['port']),
        '--out',    filepath,
    ]

    start = time.time()
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        exit_code = result.returncode
        output    = result.stdout + result.stderr
    except subprocess.TimeoutExpired:
        exit_code = 1
        output    = 'Backup timed out after 60 seconds'
    except Exception as e:
        exit_code = 1
        output    = str(e)

    elapsed = round(time.time() - start, 2)

    if exit_code == 0 and os.path.exists(filepath):
        content = open(filepath).read()
        size    = len(content.encode())
        sha256  = hashlib.sha256(content.encode()).hexdigest()
        shutil.copy2(filepath, str(out_dir / 'latest.cfg'))

        # Compare with previous backup
        prev_rows = fetchall(conn,
            "SELECT * FROM config_backups "
            "WHERE device_id=%s AND backup_id!=%s AND status='success' "
            "ORDER BY backed_up_at DESC LIMIT 1",
            (device_id, backup_id))

        changed = False
        added   = 0
        removed = 0
        prev_id = None

        if prev_rows and os.path.exists(prev_rows[0]['filepath']):
            prev_content = strip_noise(open(prev_rows[0]['filepath']).read())
            new_content  = strip_noise(content)
            changed      = prev_content != new_content
            prev_id      = prev_rows[0]['backup_id']
            if changed:
                old_lines = prev_content.splitlines()
                new_lines = new_content.splitlines()
                old_set   = set(old_lines)
                new_set   = set(new_lines)
                added     = len([l for l in new_lines if l not in old_set])
                removed   = len([l for l in old_lines if l not in new_set])

        execute(conn,
            "UPDATE config_backups SET status='success', file_size=%s, sha256=%s "
            "WHERE backup_id=%s",
            (size, sha256, backup_id))

        prev_sql = prev_id if prev_id else 'NULL'
        execute(conn,
            f"INSERT INTO config_changes "
            f"(device_id, backup_id_old, backup_id_new, changed, lines_added, lines_removed) "
            f"VALUES (%s, {prev_sql}, %s, %s, %s, %s)",
            (device_id, backup_id, int(changed), added, removed))

        # Advance next_run_at
        intervals = {
            'hourly':    3600,
            'every_6h':  21600,
            'every_12h': 43200,
            'daily':     86400,
            'weekly':    604800,
        }
        secs = intervals.get(device['schedule_interval'], 0)
        if secs:
            execute(conn,
                "UPDATE config_devices "
                "SET next_run_at = DATE_ADD(NOW(), INTERVAL %s SECOND) "
                "WHERE device_id = %s",
                (secs, device_id))

        change_str = f'+{added}/-{removed}' if changed else 'no changes'
        print(f"[OK]  {name} — {filename} ({size} B) {change_str} in {elapsed}s")
        return True

    else:
        execute(conn,
            "UPDATE config_backups SET status='failed', error_message=%s "
            "WHERE backup_id=%s",
            (output[:2000], backup_id))
        execute(conn,
            "UPDATE config_devices "
            "SET next_run_at = DATE_ADD(NOW(), INTERVAL 300 SECOND) "
            "WHERE device_id = %s",
            (device_id,))
        print(f"[FAIL] {name} — {output.strip()[:120]}")
        return False


# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='Config Manager Background Scheduler')
    parser.add_argument('--config',   help='Path to zabbix_server.conf or zabbix.conf.php')
    parser.add_argument('--db-host',  help='MySQL host')
    parser.add_argument('--db-name',  help='MySQL database name')
    parser.add_argument('--db-user',  help='MySQL username')
    parser.add_argument('--db-pass',  help='MySQL password', default='')
    parser.add_argument('--db-port',  help='MySQL port', type=int, default=3306)
    parser.add_argument('--secret',   help='Encryption secret key', default='configmanager_default_key_change_me')
    parser.add_argument('--dry-run',  action='store_true', help='Show due devices, do not run backups')
    args = parser.parse_args()

    script_dir = os.path.dirname(os.path.abspath(__file__))
    ts_str     = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

    try:
        db_cfg = get_db_config(args)
    except RuntimeError as e:
        print(f"[ERROR] {e}")
        sys.exit(1)

    try:
        conn = connect_db(db_cfg)
    except Exception as e:
        print(f"[ERROR] Cannot connect to DB ({db_cfg['host']}:{db_cfg['port']}): {e}")
        sys.exit(1)

    # Find due devices
    due = fetchall(conn,
        "SELECT * FROM config_devices "
        "WHERE enabled=1 "
        "  AND schedule_interval != 'disabled' "
        "  AND next_run_at IS NOT NULL "
        "  AND next_run_at <= NOW() "
        "ORDER BY next_run_at ASC")

    if not due:
        print(f"[{ts_str}] No devices due for backup.")
        conn.close()
        return

    print(f"[{ts_str}] {len(due)} device(s) due for backup:")
    for d in due:
        print(f"  - {d['name']} ({d['ip_address']}) interval={d['schedule_interval']} due={d['next_run_at']}")

    if args.dry_run:
        conn.close()
        return

    ok = fail = 0
    for device in due:
        success = backup_device(conn, device, script_dir, args.secret)
        if success:
            ok += 1
        else:
            fail += 1

    conn.close()
    print(f"[{ts_str}] Done — {ok} succeeded, {fail} failed.")


if __name__ == '__main__':
    main()
