#!/bin/bash
# ============================================================
# Config Manager – Scheduler wrapper
# Place in cron to run every minute:
#   * * * * * /usr/share/zabbix/modules/ConfigManager/scripts/run_scheduler.sh
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_DIR="$(dirname "$SCRIPT_DIR")"
LOG_FILE="/var/log/configmanager-scheduler.log"
LOCK_FILE="/tmp/configmanager-scheduler.lock"

# Prevent overlapping runs
if [ -f "$LOCK_FILE" ]; then
    PID=$(cat "$LOCK_FILE")
    if kill -0 "$PID" 2>/dev/null; then
        echo "$(date '+%Y-%m-%d %H:%M:%S') [SKIP] Scheduler already running (PID $PID)" >> "$LOG_FILE"
        exit 0
    fi
fi

echo $$ > "$LOCK_FILE"
trap "rm -f '$LOCK_FILE'" EXIT

# Find DB credentials from environment or config files
CONF_ARGS=""

# Docker environment variables
if [ -n "$DB_SERVER" ]; then
    CONF_ARGS="--db-host $DB_SERVER --db-name ${DB_NAME:-zabbix} --db-user ${DB_USER:-zabbix} --db-pass ${DB_PASSWORD:-}"
fi

# Zabbix server config
if [ -z "$CONF_ARGS" ] && [ -f "/etc/zabbix/zabbix_server.conf" ]; then
    CONF_ARGS="--config /etc/zabbix/zabbix_server.conf"
fi

# Zabbix PHP web config
if [ -z "$CONF_ARGS" ] && [ -f "/usr/share/zabbix/conf/zabbix.conf.php" ]; then
    CONF_ARGS="--config /usr/share/zabbix/conf/zabbix.conf.php"
fi

# Encryption secret key
if [ -n "$ZBX_SECRET_KEY" ]; then
    CONF_ARGS="$CONF_ARGS --secret $ZBX_SECRET_KEY"
fi

python3 "$SCRIPT_DIR/scheduler.py" $CONF_ARGS >> "$LOG_FILE" 2>&1
