#!/usr/bin/env python3
"""
Config Manager â€“ SSH WebSocket Bridge  (websockets >= 12 compatible)
Bridges a browser xterm.js terminal to a real SSH session via WebSocket.

Architecture:
  Browser (xterm.js) <--WebSocket--> ssh_ws_server.py <--SSH--> Network Device

Usage:
  python3 ssh_ws_server.py --host 0.0.0.0 --port 7681 \\
      --db-host 127.0.0.1 --db-name zabbix \\
      --db-user zabbix --db-pass iqlab@2025

Install:
  pip3 install "websockets>=12" paramiko cryptography mysql-connector-python
"""

import argparse
import asyncio
import hashlib
import json
import logging
import os
import select
import sys
import threading
import time

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(name)s: %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S',
)
log = logging.getLogger('ssh_ws')

# â”€â”€ websockets import â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
try:
    import websockets
    import websockets.exceptions
    # websockets >= 14 (new asyncio impl) has websockets.connection.State
    # websockets 12-13 (legacy impl) does not have it at top level
    try:
        from websockets.connection import State as _WS_State
        _HAS_STATE = True
    except ImportError:
        _HAS_STATE = False
except ImportError:
    log.error("websockets not installed: pip3 install 'websockets>=12'")
    sys.exit(1)

# â”€â”€ paramiko â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
try:
    import paramiko
except ImportError:
    log.error("paramiko not installed: pip3 install paramiko")
    sys.exit(1)

# â”€â”€ MySQL driver (prefer mysql-connector-python, fall back to pymysql) â”€â”€â”€â”€â”€â”€â”€â”€
try:
    import mysql.connector

    def db_connect(cfg: dict):
        return mysql.connector.connect(
            host=cfg['host'],
            database=cfg['database'],
            user=cfg['user'],
            password=cfg['password'],
            port=cfg['port'],
            autocommit=True,
            connection_timeout=10,
        )

    def db_fetchone(conn, sql: str, params=()):
        with conn.cursor(dictionary=True) as c:
            c.execute(sql, params)
            return c.fetchone()

    def db_execute(conn, sql: str, params=()):
        with conn.cursor() as c:
            c.execute(sql, params)
            conn.commit()
            return c.rowcount

    def db_last_id(conn) -> int:
        with conn.cursor() as c:
            c.execute('SELECT LAST_INSERT_ID() AS id')
            row = c.fetchone()
            # mysql-connector plain cursor returns a tuple
            return int(row[0] if isinstance(row, (list, tuple)) else row['id'])

except ImportError:
    try:
        import pymysql
        import pymysql.cursors

        def db_connect(cfg: dict):
            return pymysql.connect(
                host=cfg['host'],
                database=cfg['database'],
                user=cfg['user'],
                password=cfg['password'],
                port=cfg['port'],
                autocommit=True,
                cursorclass=pymysql.cursors.DictCursor,
            )

        def db_fetchone(conn, sql: str, params=()):
            with conn.cursor() as c:
                c.execute(sql, params)
                return c.fetchone()

        def db_execute(conn, sql: str, params=()):
            with conn.cursor() as c:
                c.execute(sql, params)
                return c.rowcount

        def db_last_id(conn) -> int:
            with conn.cursor() as c:
                c.execute('SELECT LAST_INSERT_ID() AS id')
                row = c.fetchone()
                return int(row['id'] if isinstance(row, dict) else row[0])

    except ImportError:
        log.error("Install mysql-connector-python or PyMySQL")
        sys.exit(1)


# â”€â”€ Password decrypt (mirrors Repository.php) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
def decrypt_password(stored: str,
                     secret: str = 'configmanager_default_key_change_me') -> str:
    try:
        from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
        from cryptography.hazmat.backends import default_backend
        import base64

        key    = hashlib.sha256(secret.encode()).digest()[:32]
        raw    = base64.b64decode(stored)
        iv     = raw[:16]
        enc    = raw[16:]
        cipher = Cipher(algorithms.AES(key), modes.CBC(iv),
                        backend=default_backend())
        dec    = cipher.decryptor()
        padded = dec.update(enc) + dec.finalize()
        pad    = padded[-1]
        return padded[:-pad].decode('utf-8', errors='replace')
    except Exception:
        log.exception("Password decrypt error")
        return ''


# â”€â”€ Token store (in-memory) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
_tokens: dict = {}
_tokens_lock  = threading.Lock()


def register_token(token: str, device_id: int, user: str, ttl: int = 60):
    with _tokens_lock:
        _tokens[token] = {
            'device_id': device_id,
            'user':      user,
            'expires':   time.time() + ttl,
        }


def consume_token(token: str):
    with _tokens_lock:
        info = _tokens.pop(token, None)
        if info and info['expires'] < time.time():
            return None
        return info


def expire_tokens():
    """Purge expired tokens â€” called periodically."""
    with _tokens_lock:
        now     = time.time()
        expired = [k for k, v in _tokens.items() if v['expires'] < now]
        for k in expired:
            del _tokens[k]


# â”€â”€ WebSocket open-state helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
def _ws_is_open(ws) -> bool:
    """
    Return True only when the WebSocket connection is fully OPEN.

    Compatible with three API variants:
      websockets >= 14 (new asyncio impl):  ws.state is State.OPEN
      websockets 12-13 (legacy impl):       ws.open == True
      Unknown / fallback:                   True (send() will raise if closed)
    """
    if _HAS_STATE:
        try:
            return ws.state is _WS_State.OPEN
        except AttributeError:
            pass
    if hasattr(ws, 'open'):
        return bool(ws.open)
    return True   # let send() raise; caller catches ConnectionClosed


# â”€â”€ Safe WebSocket send â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
async def ws_safe_send(ws, payload: dict) -> bool:
    """
    Send a JSON message.  NEVER raises â€” guaranteed.
    Returns True on success, False if the connection is not open or on any error.
    """
    if not _ws_is_open(ws):
        return False
    try:
        await ws.send(json.dumps(payload))
        return True
    except websockets.exceptions.ConnectionClosed:
        return False
    except Exception as exc:
        log.debug("ws_safe_send suppressed: %r", exc)
        return False


# â”€â”€ SSH session handler â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
class SSHSession:
    """Wraps a Paramiko SSH channel for non-blocking I/O."""

    def __init__(self, device: dict, secret: str):
        self.device  = device
        self.secret  = secret
        self.client  = None
        self.channel = None
        self._closed = False

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
            banner_timeout=15,
        )
        self.channel = self.client.invoke_shell(
            term='xterm-256color', width=cols, height=rows,
        )
        self.channel.setblocking(False)
        vendor = self.device.get('vendor', '')
        return f"Connected to {host} ({vendor}) as {user}"

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

    def read_available(self):
        """
        Returns:
          str  â€” data read (may be '' if nothing available yet)
          None â€” channel closed; caller should exit the read loop
        """
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
            return ''
        except Exception:
            self._closed = True
            return None

    def is_closed(self) -> bool:
        if self._closed:
            return True
        if self.channel:
            if self.channel.closed:
                self._closed = True
                return True
            if self.channel.exit_status_ready():
                self._closed = True
                return True
        return False

    def close(self):
        self._closed = True
        for obj in (self.channel, self.client):
            try:
                if obj:
                    obj.close()
            except Exception:
                pass


# â”€â”€ WebSocket connection handler â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
async def handle_connection(websocket, db_cfg: dict, secret: str):
    """
    One coroutine per browser WebSocket connection.

    Phases:
      1. DB connect
      2. Auth â€” receive 'connect' message, validate token
      3. SSH connect
      4. Bidirectional I/O (read_ssh + read_ws tasks via asyncio.wait)
      5. Cleanup â€” SSH close, session record update, DB close
    """
    remote = getattr(websocket, 'remote_address', ('unknown', 0))
    log.info("WS connection from %s", remote)

    ssh        = None
    session_id = None
    conn       = None

    # Short alias always safe to call
    async def send(msg: dict) -> bool:
        return await ws_safe_send(websocket, msg)

    # â”€â”€ Phase 1: Database â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    try:
        conn = db_connect(db_cfg)
    except Exception as exc:
        log.error("DB connect failed: %s", exc)
        await send({'type': 'error', 'message': 'Database connection failed'})
        return

    # â”€â”€ Phase 2: Authentication â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    msg = None
    try:
        # Wait up to 10 s for the browser to send the connect message
        try:
            raw = await asyncio.wait_for(websocket.recv(), timeout=10.0)
        except asyncio.TimeoutError:
            await send({'type': 'error', 'message': 'Authentication timeout (10 s)'})
            return
        except websockets.exceptions.ConnectionClosed:
            log.debug("WS closed during auth from %s", remote)
            return

        try:
            msg = json.loads(raw)
        except (json.JSONDecodeError, ValueError):
            await send({'type': 'error', 'message': 'Invalid JSON in connect message'})
            return

        if not isinstance(msg, dict) or msg.get('type') != 'connect':
            await send({'type': 'error', 'message': "Expected {type: 'connect'} message"})
            return

        token = str(msg.get('token', ''))
        if not token:
            await send({'type': 'error', 'message': 'Missing token'})
            return

        row = db_fetchone(
            conn,
            "SELECT t.token, t.device_id, t.zabbix_user, "
            "       d.name, d.ip_address, d.username, d.password, d.port, d.vendor "
            "FROM config_ssh_tokens t "
            "INNER JOIN config_devices d ON d.device_id = t.device_id "
            "WHERE t.token = %s AND t.expires_at > NOW() AND t.used = 0",
            (token,),
        )

        if not row:
            await send({'type': 'error', 'message': 'Invalid or expired token'})
            return

        db_execute(conn,
                   "UPDATE config_ssh_tokens SET used=1 WHERE token=%s",
                   (token,))

        device      = row
        zabbix_user = row.get('zabbix_user', 'admin')
        client_ip   = str(remote[0]) if isinstance(remote, (tuple, list)) else str(remote)

        db_execute(
            conn,
            "INSERT INTO config_ssh_sessions (device_id, zabbix_user, client_ip) "
            "VALUES (%s, %s, %s)",
            (device['device_id'], zabbix_user, client_ip),
        )
        session_id = db_last_id(conn)

    except websockets.exceptions.ConnectionClosed:
        log.debug("WS closed during auth phase from %s", remote)
        return
    except Exception:
        log.exception("Error in auth phase from %s", remote)
        # Do NOT call send() inside an exception block â€” use a direct try/except
        try:
            await ws_safe_send(websocket, {'type': 'error', 'message': 'Authentication error'})
        except Exception:
            pass
        return
    finally:
        if conn and session_id is None:
            # Auth failed before we could create a session â€” clean up DB conn
            try:
                conn.close()
            except Exception:
                pass
            conn = None

    if conn is None:
        return   # auth failed; conn already closed in finally above

    # â”€â”€ Phase 3: SSH connect â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    cols = int(msg.get('cols', 120))
    rows = int(msg.get('rows', 35))

    await send({
        'type': 'output',
        'data': f'\r\n\x1b[32m\u26a1 Connecting to {device["ip_address"]}\u2026\x1b[0m\r\n',
    })

    ssh = SSHSession(device, secret)
    try:
        banner = ssh.connect(cols, rows)
    except Exception as exc:
        log.error("SSH connect failed for device %s: %s", device.get('device_id'), exc)
        try:
            await ws_safe_send(websocket,
                               {'type': 'error', 'message': f'SSH connection failed: {exc}'})
        except Exception:
            pass
        ssh = None
        # Fall through to cleanup
    else:
        log.info("SSH connected: %s (%s) by %s",
                 device.get('name'), device.get('ip_address'), zabbix_user)
        await send({'type': 'connected', 'message': banner})
        await send({'type': 'output', 'data': f'\x1b[32m\u2713 {banner}\x1b[0m\r\n\r\n'})

        # â”€â”€ Phase 4: Bidirectional I/O bridge â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        _stop = asyncio.Event()

        async def read_ssh():
            """SSH channel -> WebSocket pump."""
            try:
                while not _stop.is_set() and not ssh.is_closed():
                    data = ssh.read_available()
                    if data is None:
                        break                   # SSH EOF
                    if data:
                        ok = await ws_safe_send(websocket, {'type': 'output', 'data': data})
                        if not ok:
                            break               # WebSocket gone
                    else:
                        await asyncio.sleep(0.02)
            except asyncio.CancelledError:
                pass
            except Exception:
                log.exception("Unexpected error in read_ssh task")
            finally:
                _stop.set()

        async def read_ws():
            """WebSocket -> SSH channel pump."""
            try:
                while not _stop.is_set():
                    try:
                        raw_msg = await asyncio.wait_for(
                            websocket.recv(), timeout=30.0
                        )
                    except asyncio.TimeoutError:
                        # Idle timeout â€” just loop again to check _stop
                        continue
                    except websockets.exceptions.ConnectionClosed:
                        break   # Normal browser close/refresh

                    try:
                        m = json.loads(raw_msg)
                    except (json.JSONDecodeError, ValueError):
                        continue

                    if not isinstance(m, dict):
                        continue

                    mtype = m.get('type')
                    if mtype == 'input':
                        ssh.send(m.get('data', ''))
                    elif mtype == 'resize':
                        try:
                            ssh.resize(int(m.get('cols', 120)),
                                       int(m.get('rows', 35)))
                        except (TypeError, ValueError):
                            pass

            except asyncio.CancelledError:
                pass
            except Exception:
                log.exception("Unexpected error in read_ws task")
            finally:
                _stop.set()

        ssh_task = asyncio.create_task(read_ssh(), name="read_ssh")
        ws_task  = asyncio.create_task(read_ws(),  name="read_ws")

        try:
            done, pending = await asyncio.wait(
                {ssh_task, ws_task},
                return_when=asyncio.FIRST_COMPLETED,
            )
            # Cancel the surviving task and wait for it to acknowledge
            for task in pending:
                task.cancel()
                try:
                    await task
                except (asyncio.CancelledError, Exception):
                    pass
            # Surface any unexpected exceptions for logging
            for task in done:
                if not task.cancelled():
                    exc = task.exception()
                    if exc:
                        log.error("I/O task %s raised: %r", task.get_name(), exc)

        except asyncio.CancelledError:
            for task in (ssh_task, ws_task):
                task.cancel()
            raise

    # â”€â”€ Phase 5: Cleanup â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    # The WebSocket is already closed here â€” do NOT call send() / ws_safe_send()
    if ssh:
        ssh.close()

    if session_id and conn:
        try:
            db_execute(
                conn,
                "UPDATE config_ssh_sessions "
                "SET ended_at = NOW(), "
                "    duration_sec = TIMESTAMPDIFF(SECOND, started_at, NOW()) "
                "WHERE session_id = %s",
                (session_id,),
            )
        except Exception:
            log.exception("Failed to update session record %s", session_id)

    if conn:
        try:
            conn.close()
        except Exception:
            pass

    log.info("WS disconnected from %s (session=%s)", remote, session_id)


# â”€â”€ Main â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
def main():
    parser = argparse.ArgumentParser(
        description='Config Manager SSH WebSocket Bridge'
    )
    parser.add_argument('--host',    default='0.0.0.0',      help='Listen host')
    parser.add_argument('--port',    default=7681,  type=int, help='Listen port')
    parser.add_argument('--db-host', default='127.0.0.1',    dest='db_host')
    parser.add_argument('--db-name', default='zabbix',       dest='db_name')
    parser.add_argument('--db-user', default='zabbix',       dest='db_user')
    parser.add_argument('--db-pass', default='',             dest='db_pass')
    parser.add_argument('--db-port', default=3306,  type=int, dest='db_port')
    parser.add_argument('--secret',
                        default='configmanager_default_key_change_me')
    args = parser.parse_args()

    db_cfg = {
        'host':     args.db_host,
        'database': args.db_name,
        'user':     args.db_user,
        'password': args.db_pass,
        'port':     args.db_port,
    }

    log.info("SSH WebSocket bridge starting on ws://%s:%d", args.host, args.port)
    log.info("DB: %s@%s:%d/%s",
             args.db_user, args.db_host, args.db_port, args.db_name)

    async def serve():
        async with websockets.serve(
            lambda ws: handle_connection(ws, db_cfg, args.secret),
            args.host,
            args.port,
            ping_interval=20,
            ping_timeout=10,
            max_size=10 * 1024 * 1024,
        ):
            log.info("WebSocket server ready â€” ws://%s:%d",
                     args.host, args.port)

            async def cleanup():
                while True:
                    await asyncio.sleep(30)
                    expire_tokens()

            asyncio.create_task(cleanup(), name="token_cleanup")
            await asyncio.Future()   # run forever

    try:
        asyncio.run(serve())
    except KeyboardInterrupt:
        log.info("Server stopped by user interrupt")


if __name__ == '__main__':
    main()

