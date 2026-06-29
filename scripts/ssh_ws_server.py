#!/usr/bin/env python3
"""
Config Manager – SSH WebSocket Bridge
Bridges a browser xterm.js terminal to a real SSH session via WebSocket.

Architecture:
  Browser (xterm.js) <--WebSocket--> ssh_ws_server.py <--SSH--> Network Device

Usage:
  python3 ssh_ws_server.py --host 0.0.0.0 --port 7681 \
      --db-host mysql-server --db-name zabbix \
      --db-user zabbix --db-pass iqlab@2025

Install:
  pip3 install websockets paramiko cryptography mysql-connector-python
"""

import argparse
import asyncio
import hashlib
import json
import logging
import os
import re
import select
import socket
import sys
import threading
import time
import traceback
from datetime import datetime

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(name)s: %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
log = logging.getLogger('ssh_ws')

try:
    import websockets
except ImportError:
    log.error("websockets not installed: pip3 install websockets")
    sys.exit(1)

try:
    import paramiko
except ImportError:
    log.error("paramiko not installed: pip3 install paramiko")
    sys.exit(1)

try:
    import mysql.connector
    def db_connect(cfg):
        return mysql.connector.connect(
            host=cfg['host'],
            database=cfg['database'],
            user=cfg['user'],
            password=cfg['password'],
            port=cfg['port'],
            autocommit=True,
            connection_timeout=10
        )
    def db_fetchone(conn, sql, params=()):
        with conn.cursor(dictionary=True) as c:
            c.execute(sql, params)
            return c.fetchone()
    def db_execute(conn, sql, params=()):
        with conn.cursor() as c:
            c.execute(sql, params)
            return c.rowcount
except ImportError:
    try:
        import pymysql, pymysql.cursors
        def db_connect(cfg):
            return pymysql.connect(
                host=cfg['host'],
                database=cfg['database'],
                user=cfg['user'],
                password=cfg['password'],
                port=cfg['port'],
                autocommit=True,
                cursorclass=pymysql.cursors.DictCursor
            )
        def db_fetchone(conn, sql, params=()):
            with conn.cursor() as c:
                c.execute(sql, params)
                return c.fetchone()
        def db_execute(conn, sql, params=()):
            with conn.cursor() as c:
                c.execute(sql, params)
                return c.rowcount
    except ImportError:
        log.error("Install mysql-connector-python or PyMySQL")
        sys.exit(1)


# ── Password decrypt (mirrors Repository.php) ────────────────────────────────
def decrypt_password(stored: str, secret: str = 'configmanager_default_key_change_me') -> str:
    try:
        from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
        from cryptography.hazmat.backends import default_backend
        import base64
        key = hashlib.sha256(secret.encode()).digest()[:32]
        raw = base64.b64decode(stored)
        iv  = raw[:16]
        enc = raw[16:]
        cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
        dec    = cipher.decryptor()
        padded = dec.update(enc) + dec.finalize()
        pad    = padded[-1]
        return padded[:-pad].decode('utf-8', errors='replace')
    except Exception:
        log.exception("Password decrypt error")
        return ''


# ── Token store (in-memory, keyed by token string) ───────────────────────────
_tokens = {}   # token -> {'device_id': int, 'user': str, 'expires': float}
_tokens_lock = threading.Lock()

def register_token(token: str, device_id: int, user: str, ttl: int = 60):
    with _tokens_lock:
        _tokens[token] = {
            'device_id': device_id,
            'user': user,
            'expires': time.time() + ttl
        }

def consume_token(token: str) -> dict | None:
    with _tokens_lock:
        info = _tokens.pop(token, None)
        if info and info['expires'] < time.time():
            return None
        return info

def expire_tokens():
    """Purge expired tokens periodically."""
    with _tokens_lock:
        now = time.time()
        expired = [k for k, v in _tokens.items() if v['expires'] < now]
        for k in expired:
            del _tokens[k]


# ── SSH session handler ───────────────────────────────────────────────────────
class SSHSession:
    def __init__(self, device: dict, secret: str):
        self.device   = device
        self.secret   = secret
        self.client   = None
        self.channel  = None
        self._closed  = False

    def connect(self, cols: int = 120, rows: int = 35) -> str:
        password = decrypt_password(self.device['password'], self.secret)
        host     = self.device['ip_address']
        port     = int(self.device['port'])
        user     = self.device['username']

        self.client = paramiko.SSHClient()
        self.client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        self.client.connect(
            hostname=host, port=port, username=user, password=password,
            timeout=15, look_for_keys=False, allow_agent=False,
            banner_timeout=15
        )
        self.channel = self.client.invoke_shell(
            term='xterm-256color', width=cols, height=rows
        )
        self.channel.setblocking(False)
        return f"Connected to {host} ({self.device['vendor']}) as {user}"

    def send(self, data: str):
        if self.channel and not self._closed:
            try:
                self.channel.send(data)
            except Exception:
                self._closed = True

    def resize(self, cols: int, rows: int):
        if self.channel and not self._closed:
            try:
                self.channel.resize_pty(width=cols, height=rows)
            except Exception:
                self._closed = True

    def read_available(self) -> str | None:
        if self._closed or not self.channel:
            return None
        try:
            ready = select.select([self.channel], [], [], 0.05)[0]
            if ready:
                data = self.channel.recv(4096)
                if not data:
                    self._closed = True
                    return None
                return data.decode('utf-8', errors='replace')
        except Exception:
            self._closed = True
            return None
        return ''

    def is_closed(self) -> bool:
        if self._closed:
            return True
        if self.channel and self.channel.closed:
            self._closed = True
            return True
        if self.channel and self.channel.exit_status_ready():
            self._closed = True
            return True
        return False

    def close(self):
        self._closed = True
        try:
            if self.channel:
                self.channel.close()
            if self.client:
                self.client.close()
        except Exception:
            pass


# ── WebSocket handler ─────────────────────────────────────────────────────────
async def handle_connection(websocket, db_cfg: dict, secret: str):
    remote = websocket.remote_address
    log.info(f"WS connection from {remote}")

    ssh        = None
    session_id = None
    conn       = None

    async def send(msg: dict):
        if websocket.closed:
            return
        try:
            await websocket.send(json.dumps(msg))
        except websockets.exceptions.ConnectionClosed:
            pass
        except Exception:
            log.exception("Error sending message to websocket")

    try:
        conn = db_connect(db_cfg)

        # Expect first message to be connect+token within 10s
        try:
            raw = await asyncio.wait_for(websocket.recv(), timeout=10)
        except asyncio.TimeoutError:
            await send({'type': 'error', 'message': 'Authentication timeout'})
            return

        try:
            msg = json.loads(raw)
        except json.JSONDecodeError:
            await send({'type': 'error', 'message': 'Invalid JSON'})
            return

        if not isinstance(msg, dict) or msg.get('type') != 'connect':
            await send({'type': 'error', 'message': 'Expected connect message'})
            return

        token = msg.get('token', '')

        # Validate token from DB
        row = db_fetchone(conn,
            "SELECT t.token, t.device_id, t.zabbix_user, "
            "       d.name, d.ip_address, d.username, d.password, d.port, d.vendor "
            "FROM config_ssh_tokens t "
            "INNER JOIN config_devices d ON d.device_id = t.device_id "
            "WHERE t.token = %s AND t.expires_at > NOW() AND t.used = 0",
            (token,))

        if not row:
            await send({'type': 'error', 'message': 'Invalid or expired token'})
            return

        # Mark token used
        db_execute(conn, "UPDATE config_ssh_tokens SET used=1 WHERE token=%s", (token,))

        device      = row
        zabbix_user = row.get('zabbix_user', 'admin')

        # Log session start
        db_execute(conn,
            "INSERT INTO config_ssh_sessions (device_id, zabbix_user, client_ip) "
            "VALUES (%s, %s, %s)",
            (device['device_id'], zabbix_user, str(remote[0])))
        
        with conn.cursor() as c:
            c.execute('SELECT LAST_INSERT_ID() AS id')
            r = c.fetchone()
            session_id = int(r[0] if isinstance(r, (list, tuple)) else r['id'])

        # Initial terminal size
        cols = int(msg.get('cols', 120))
        rows = int(msg.get('rows', 35))

        # Connect SSH
        await send({'type': 'output', 'data': f'\r\n\x1b[32m⚡ Connecting to {device["ip_address"]}...\x1b[0m\r\n'})

        ssh = SSHSession(device, secret)
        try:
            banner = ssh.connect(cols, rows)
            await send({'type': 'connected', 'message': banner})
            await send({'type': 'output', 'data': f'\x1b[32m✓ {banner}\x1b[0m\r\n\r\n'})
        except Exception as e:
            log.exception(f"SSH connection failed for device ID {device['device_id']}")
            await send({'type': 'error', 'message': f'SSH connection failed: {e}'})
            return

        log.info(f"SSH connected: {device['name']} ({device['ip_address']}) by {zabbix_user}")

        # I/O loops
        async def read_ssh():
            """Continuously read from SSH and send to WebSocket."""
            while ssh and not ssh.is_closed():
                data = ssh.read_available()
                if data is None:   # channel closed
                    break
                if data:
                    await send({'type': 'output', 'data': data})
                else:
                    await asyncio.sleep(0.02)

        async def read_ws():
            """Continuously read from WebSocket and send to SSH."""
            try:
                async for raw_msg in websocket:
                    try:
                        m = json.loads(raw_msg)
                    except Exception:
                        continue

                    if not isinstance(m, dict):
                        continue

                    if m.get('type') == 'input':
                        ssh.send(m.get('data', ''))
                    elif m.get('type') == 'resize':
                        ssh.resize(int(m.get('cols', 120)), int(m.get('rows', 35)))
            except websockets.exceptions.ConnectionClosed:
                pass

        # Run both tasks concurrently and cleanly manage termination
        read_ssh_task = asyncio.create_task(read_ssh())
        read_ws_task = asyncio.create_task(read_ws())

        done, pending = await asyncio.wait(
            [read_ssh_task, read_ws_task],
            return_when=asyncio.FIRST_COMPLETED
        )

        for task in pending:
            task.cancel()

    except Exception:
        log.exception("Handler error")
        await send({'type': 'error', 'message': 'Internal connection error occurred'})

    finally:
        if ssh:
            ssh.close()
        if session_id and conn:
            try:
                db_execute(conn,
                    "UPDATE config_ssh_sessions SET ended_at=NOW(), "
                    "duration_sec=TIMESTAMPDIFF(SECOND, started_at, NOW()) "
                    "WHERE session_id=%s", (session_id,))
            except Exception:
                log.exception("Failed to update session end details")
        if conn:
            try:
                conn.close()
            except Exception:
                pass

        log.info(f"WS disconnected from {remote} (session={session_id})")
        await send({'type': 'closed', 'message': 'SSH session closed'})


# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description='Config Manager SSH WebSocket Bridge')
    parser.add_argument('--host',    default='0.0.0.0',     help='Listen host')
    parser.add_argument('--port',    default=7681, type=int, help='Listen port')
    parser.add_argument('--db-host', default='127.0.0.1')
    parser.add_argument('--db-name', default='zabbix')
    parser.add_argument('--db-user', default='zabbix')
    parser.add_argument('--db-pass', default='')
    parser.add_argument('--db-port', default=3306, type=int)
    parser.add_argument('--secret',  default='configmanager_default_key_change_me')
    args = parser.parse_args()

    db_cfg = {
        'host':     args.db_host,
        'database': args.db_name,
        'user':     args.db_user,
        'password': args.db_pass,
        'port':     args.db_port,
    }

    log.info(f"SSH WebSocket bridge starting on ws://{args.host}:{args.port}")
    log.info(f"DB: {args.db_user}@{args.db_host}:{args.db_port}/{args.db_name}")

    async def serve():
        async with websockets.serve(
            lambda ws: handle_connection(ws, db_cfg, args.secret),
            args.host,
            args.port,
            ping_interval=20,
            ping_timeout=10,
            max_size=10 * 1024 * 1024,
        ):
            log.info(f"WebSocket server ready — ws://{args.host}:{args.port}")
            # Periodic token cleanup
            async def cleanup():
                while True:
                    await asyncio.sleep(30)
                    expire_tokens()
            asyncio.create_task(cleanup())
            await asyncio.Future()  # run forever

    try:
        asyncio.run(serve())
    except KeyboardInterrupt:
        log.info("Server stopped by user interrupt")


if __name__ == '__main__':
    main()
