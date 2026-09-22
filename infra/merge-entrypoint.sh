#!/bin/sh
# Merge supervisor for ZeleBoba: runs all four worker roles in ONE container
# (php-fpm web + outbox worker + reconcile scheduler + telegram long-poll) so a
# single deploy target replaces the 4-container topology (app/worker/scheduler/bot).
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

# --- start each role in the background, keep PIDs -------------------------
run_supervised fpm "$TMP_DIR/fpm.pid" php-fpm &
FPM_SUP=$!

run_supervised scheduler "$TMP_DIR/scheduler.pid" run_scheduler &
SCHED_SUP=$!

run_supervised worker "$TMP_DIR/worker.pid" php bin/console worker:run &
WORKER_SUP=$!

run_supervised bot "$TMP_DIR/bot.pid" php bin/console telegram:poll &
BOT_SUP=$!

FPM_PID=$(wait_for_pid "$TMP_DIR/fpm.pid")
SCHED_PID=$(wait_for_pid "$TMP_DIR/scheduler.pid")
WORKER_PID=$(wait_for_pid "$TMP_DIR/worker.pid")
BOT_PID=$(wait_for_pid "$TMP_DIR/bot.pid")

echo "[merge-entrypoint] started php-fpm=$FPM_PID scheduler=$SCHED_PID worker=$WORKER_PID bot=$BOT_PID"

# --- lifecycle: forward signals, exit (for restart policy) when any dies ----
STOPPING=0
terminate_set() {
    STOPPING=1
    kill -TERM $FPM_PID $SCHED_PID $WORKER_PID $BOT_PID 2>/dev/null
    kill -TERM $FPM_SUP $SCHED_SUP $WORKER_SUP $BOT_SUP 2>/dev/null
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
kill -9 $FPM_PID $SCHED_PID $WORKER_PID $BOT_PID 2>/dev/null
wait $FPM_SUP $SCHED_SUP $WORKER_SUP $BOT_SUP 2>/dev/null
rm -f "$EVENT_FILE" "$TMP_DIR/fpm.pid" "$TMP_DIR/scheduler.pid" "$TMP_DIR/worker.pid" "$TMP_DIR/bot.pid" 2>/dev/null
rmdir "$TMP_DIR" 2>/dev/null
exit $FAILED
