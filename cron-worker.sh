#!/bin/bash
# AIKAFLOW - Worker Runner Script
# 
# Usage: ./cron-worker.sh start|stop|status|restart

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="${SCRIPT_DIR}/worker.pid"
LOG_FILE="${SCRIPT_DIR}/logs/worker.log"
PHP_BIN=$(which php)

start() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p "$PID" > /dev/null 2>&1; then
            echo "Worker is already running (PID: $PID)"
            return 1
        fi
    fi
    
    echo "Starting worker..."
    nohup "$PHP_BIN" "${SCRIPT_DIR}/worker.php" --daemon >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"
    echo "Worker started (PID: $(cat $PID_FILE))"
}

stop() {
    if [ ! -f "$PID_FILE" ]; then
        echo "Worker is not running (no PID file)"
        return 1
    fi
    
    PID=$(cat "$PID_FILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        echo "Stopping worker (PID: $PID)..."
        kill -TERM "$PID"
        sleep 2
        
        if ps -p "$PID" > /dev/null 2>&1; then
            echo "Force killing worker..."
            kill -9 "$PID"
        fi
    fi
    
    rm -f "$PID_FILE"
    echo "Worker stopped"
}

status() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p "$PID" > /dev/null 2>&1; then
            echo "Worker is running (PID: $PID)"
            return 0
        else
            echo "Worker is not running (stale PID file)"
            return 1
        fi
    else
        echo "Worker is not running"
        return 1
    fi
}

restart() {
    stop
    sleep 1
    start
}

case "$1" in
    start)
        start
        ;;
    stop)
        stop
        ;;
    status)
        status
        ;;
    restart)
        restart
        ;;
    *)
        echo "Usage: $0 {start|stop|status|restart}"
        exit 1
        ;;
esac