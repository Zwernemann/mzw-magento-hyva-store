#!/bin/bash
# Runs bin/magento cron:run every minute in the background.
# Started automatically via postStartCommand in devcontainer.json.

LOGFILE="/workspace/var/log/cron.log"
PIDFILE="/tmp/magento-cron.pid"

# Avoid duplicate processes on container restart
if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
    exit 0
fi

(
    while true; do
        /workspace/bin/magento cron:run >> "$LOGFILE" 2>&1
        sleep 60
    done
) &

echo $! > "$PIDFILE"
echo "[$(date)] Magento cron loop started (PID $(cat $PIDFILE))" >> "$LOGFILE"
