#!/bin/bash
# ============================================================
# Config Manager – Install cron job
# Run once as root to set up the scheduler
# ============================================================

SCRIPT="/usr/share/zabbix/modules/ConfigManager/scripts/run_scheduler.sh"
CRON_LINE="* * * * * $SCRIPT"
CRON_USER="${1:-www-data}"

echo "Installing cron job for user: $CRON_USER"
echo "Script: $SCRIPT"
echo ""

# Add to crontab if not already present
(crontab -u "$CRON_USER" -l 2>/dev/null | grep -v "configmanager"; echo "$CRON_LINE") \
    | crontab -u "$CRON_USER" -

echo "✓ Cron job installed:"
crontab -u "$CRON_USER" -l | grep configmanager

echo ""
echo "The scheduler will run every minute and backup any devices that are due."
echo "Logs: /var/log/configmanager-scheduler.log"
