#!/bin/sh
# Merge supervisor for ZeleBoba: runs all four worker roles in ONE container
# (php-fpm web + outbox worker + reconcile scheduler + telegram long-poll) so a
# single deploy target replaces the 4-container topology (app/worker/scheduler/bot).
# No rebuild / no new deps: uses only sh + php already in the php-fpm image.
#
# Resilience model: any child that exits causes `wait` to return non-zero, the
# container stops, and the Docker restart policy brings the WHOLE set back up —
# so a dead worker is automatically resurrected (better than today's 4 isolated
# containers, where a dead worker stays dead until manual `docker restart`).
set -u
SLEEP_SECONDS="${RECONCILE_SECONDS:-60}"

die() { echo "[merge-entrypoint] FATAL: $*" >&2; exit 1; }

# --- start each role in the background, keep PIDs -------------------------
php-fpm &
FPM_PID=$!

( while true; do php bin/console billing:reconcile; sleep "$SLEEP_SECONDS"; done ) &
SCHED_PID=$!

php bin/console worker:run &
WORKER_PID=$!

php bin/console telegram:poll &
BOT_PID=$!

echo "[merge-entrypoint] started php-fpm=$FPM_PID scheduler=$SCHED_PID worker=$WORKER_PID bot=$BOT_PID"

# --- lifecycle: forward signals, exit (for restart policy) when any dies ----
trap 'kill -TERM $FPM_PID $SCHED_PID $WORKER_PID $BOT_PID 2>/dev/null' TERM INT

# wait for EACH; if any exits, log and terminate the rest so the restart
# policy resurrects the full set.
FAILED=0
for P in "$WORKER_PID" "$BOT_PID" "$SCHED_PID" "$FPM_PID"; do
    if kill -0 "$P" 2>/dev/null; then
        if ! wait "$P"; then
            echo "[merge-entrypoint] process $P exited non-zero; taking down the set for restart" >&2
            FAILED=1
            kill -TERM $FPM_PID $SCHED_PID $WORKER_PID $BOT_PID 2>/dev/null
            break
        fi
    fi
done

# If php-fpm itself died first (web path), that is fatal regardless.
if ! kill -0 "$FPM_PID" 2>/dev/null; then
    echo "[merge-entrypoint] php-fpm died; container will restart" >&2
    FAILED=1
fi

# reap remaining children after signal
sleep 1
kill -9 $FPM_PID $SCHED_PID $WORKER_PID $BOT_PID 2>/dev/null
exit $FAILED
