#!/bin/sh
# Merge supervisor for ZeleBoba: runs the PHP web role and any enabled
# background roles in ONE container. Background roles can be disabled
# independently while keeping php-fpm available during a staged Rails cutover.
# No rebuild / no new deps: uses only sh + php already in the php-fpm image.
#
# Resilience model: any child that exits reports through a tiny event file, the
# container stops, and the Docker restart policy brings the WHOLE set back up —
# so a dead worker is automatically resurrected (better than today's 4 isolated
# containers, where a dead worker stays dead until manual `docker restart`).
set -u
SLEEP_SECONDS="${RECONCILE_SECONDS:-60}"

die() { echo "[merge-entrypoint] FATAL: $*" >&2; exit 1; }

TMP_DIR="${TMPDIR:-/tmp}/merge-entrypoint.$$"
EVENT_FILE="$TMP_DIR/event"
mkdir -p "$TMP_DIR" || die "cannot create $TMP_DIR"
: > "$EVENT_FILE" || die "cannot create event file"

run_scheduler() {
    while true; do
        php bin/console billing:reconcile
        sleep "$SLEEP_SECONDS"
    done
}

run_supervised() {
    NAME="$1"
    PIDFILE="$2"
    shift 2
    "$@" &
    CHILD=$!
    echo "$CHILD" > "$PIDFILE"
    trap 'kill -TERM "$CHILD" 2>/dev/null; wait "$CHILD" 2>/dev/null; exit 0' TERM INT
    wait "$CHILD"
    STATUS=$?
    if [ ! -s "$EVENT_FILE" ]; then
        echo "$NAME:$STATUS" > "$EVENT_FILE"
    fi
    exit "$STATUS"
}

wait_for_pid() {
    PIDFILE="$1"
    while [ ! -s "$PIDFILE" ]; do
        sleep 0.1
    done
    cat "$PIDFILE"
}

# --- start enabled roles in the background, keep child and supervisor PIDs --
SUPERVISORS=""
ROLE_PIDS=""
start_role() {
    ROLE_NAME="$1"
    ROLE_PIDFILE="$2"
    shift 2
    run_supervised "$ROLE_NAME" "$ROLE_PIDFILE" "$@" &
    LAST_SUPERVISOR=$!
    SUPERVISORS="$SUPERVISORS $LAST_SUPERVISOR"
    LAST_CHILD=$(wait_for_pid "$ROLE_PIDFILE")
    ROLE_PIDS="$ROLE_PIDS $LAST_CHILD"
}

start_role fpm "$TMP_DIR/fpm.pid" php-fpm
FPM_PID=$LAST_CHILD
FPM_SUP=$LAST_SUPERVISOR

SCHED_PID=""; SCHED_SUP=""
WORKER_PID=""; WORKER_SUP=""
BOT_PID=""; BOT_SUP=""
if [ "${RUN_PHP_SCHEDULER:-1}" = "1" ]; then
    start_role scheduler "$TMP_DIR/scheduler.pid" run_scheduler
    SCHED_PID=$LAST_CHILD; SCHED_SUP=$LAST_SUPERVISOR
fi
if [ "${RUN_PHP_OUTBOX_WORKER:-1}" = "1" ]; then
    start_role worker "$TMP_DIR/worker.pid" php bin/console worker:run
    WORKER_PID=$LAST_CHILD; WORKER_SUP=$LAST_SUPERVISOR
fi
if [ "${RUN_PHP_TELEGRAM_POLL:-1}" = "1" ]; then
    start_role bot "$TMP_DIR/bot.pid" php bin/console telegram:poll
    BOT_PID=$LAST_CHILD; BOT_SUP=$LAST_SUPERVISOR
fi

echo "[merge-entrypoint] started php-fpm=$FPM_PID scheduler=${SCHED_PID:-disabled} worker=${WORKER_PID:-disabled} bot=${BOT_PID:-disabled}"

# --- lifecycle: forward signals, exit (for restart policy) when any dies ----
STOPPING=0
terminate_set() {
    STOPPING=1
    [ -z "$ROLE_PIDS" ] || kill -TERM $ROLE_PIDS 2>/dev/null
    [ -z "$SUPERVISORS" ] || kill -TERM $SUPERVISORS 2>/dev/null
}
trap 'terminate_set' TERM INT

# The event file is written by the first role wrapper whose child exits. A clean
# TERM sets STOPPING first, so operator-initiated shutdown exits 0 while
# unexpected child loss exits non-zero to trigger "on-failure" restart policies.
FAILED=0
EVENT=""
while [ ! -s "$EVENT_FILE" ] && [ "$STOPPING" -eq 0 ]; do
    sleep 1
done
if [ -s "$EVENT_FILE" ]; then
    read EVENT < "$EVENT_FILE"
fi

if [ -n "$EVENT" ] && [ "$STOPPING" -eq 0 ]; then
    ROLE=${EVENT%%:*}
    STATUS=${EVENT#*:}
    FAILED=1
    if [ "$STATUS" = "0" ]; then
        echo "[merge-entrypoint] $ROLE exited; taking down the set for restart" >&2
    else
        echo "[merge-entrypoint] $ROLE exited non-zero ($STATUS); taking down the set for restart" >&2
    fi
    terminate_set
fi

# reap remaining children after signal
sleep 1
for PID in $ROLE_PIDS; do kill -9 "$PID" 2>/dev/null; done
for PID in $SUPERVISORS; do wait "$PID" 2>/dev/null; done
rm -f "$EVENT_FILE" "$TMP_DIR"/*.pid 2>/dev/null
rmdir "$TMP_DIR" 2>/dev/null
exit $FAILED
