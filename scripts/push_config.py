#!/usr/bin/env python3
"""
Config Manager v1.2 – Push Config Engine
Accepts JSON on stdin, returns JSON on stdout.

Input:
    {
        "host": "192.168.1.1",
        "vendor": "cisco_ios",
        "username": "admin",
        "password": "secret",
        "port": 22,
        "commands": ["interface Gi0/1", "description Uplink", "no shutdown"],
        "save_config": true,
        "dry_run": false,
        "timeout": 30
    }

Output:
    {
        "success": true,
        "output": "...",
        "execution_time": 3.14,
        "error": null
    }
"""

import json
import sys
import time
import traceback

try:
    from netmiko import ConnectHandler
    from netmiko.exceptions import (
        NetmikoTimeoutException,
        NetmikoAuthenticationException,
    )
except ImportError:
    print(json.dumps({
        "success": False,
        "output": "",
        "execution_time": 0,
        "error": "netmiko not installed. Run: pip3 install netmiko"
    }))
    sys.exit(1)

VENDOR_MAP = {
    "cisco_ios":  "cisco_ios",
    "cisco_nxos": "cisco_nxos",
    "fortinet":   "fortinet",
    "mikrotik":   "mikrotik_routeros",
    "juniper":    "juniper_junos",
}


def push(cfg: dict) -> dict:
    host     = cfg.get("host", "")
    vendor   = cfg.get("vendor", "cisco_ios")
    username = cfg.get("username", "")
    password = cfg.get("password", "")
    port     = int(cfg.get("port", 22))
    commands = cfg.get("commands", [])
    do_save  = bool(cfg.get("save_config", True))
    dry_run  = bool(cfg.get("dry_run", False))
    timeout  = int(cfg.get("timeout", 30))

    device_type = VENDOR_MAP.get(vendor, "cisco_ios")

    if dry_run:
        preview = "--- DRY RUN — commands NOT sent to device ---\n\n"
        preview += "\n".join(commands)
        preview += "\n\n--- End of dry run preview ---"
        return {
            "success": True,
            "output": preview,
            "execution_time": 0.0,
            "error": None
        }

    if not commands:
        return {
            "success": False,
            "output": "",
            "execution_time": 0.0,
            "error": "No commands provided."
        }

    start = time.time()
    output_lines = []

    device_params = {
        "device_type":  device_type,
        "host":         host,
        "username":     username,
        "password":     password,
        "port":         port,
        "timeout":      timeout,
        "conn_timeout": timeout,
        "session_log":  None,
    }

    if vendor == "juniper":
        device_params["fast_cli"] = False

    with ConnectHandler(**device_params) as conn:
        output_lines.append(f"[INFO] Connected to {host} ({vendor})")

        # send_config_set handles entering config mode automatically
        output = conn.send_config_set(
            commands,
            read_timeout=60,
            strip_prompt=False,
            strip_command=False,
        )
        output_lines.append(output)

        if do_save:
            save_output = conn.save_config()
            output_lines.append("[INFO] Configuration saved.")
            output_lines.append(save_output)

    elapsed = round(time.time() - start, 3)
    return {
        "success": True,
        "output": "\n".join(output_lines),
        "execution_time": elapsed,
        "error": None
    }


def main():
    try:
        raw = sys.stdin.read().strip()
        if not raw:
            raise ValueError("No input provided on stdin.")
        cfg = json.loads(raw)
        result = push(cfg)
    except NetmikoAuthenticationException as e:
        result = {"success": False, "output": "", "execution_time": 0,
                  "error": f"Authentication failed: {e}"}
    except NetmikoTimeoutException as e:
        result = {"success": False, "output": "", "execution_time": 0,
                  "error": f"Connection timed out: {e}"}
    except Exception as e:
        result = {"success": False, "output": "", "execution_time": 0,
                  "error": f"{type(e).__name__}: {e}\n{traceback.format_exc()}"}

    print(json.dumps(result))


if __name__ == "__main__":
    main()
