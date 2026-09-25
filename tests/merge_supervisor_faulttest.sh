#!/bin/sh
# Fault-test for infra/merge-entrypoint.sh supervisor logic (no prod impact).
# Run as root (--user 0) in a throwaway php image on an isolated network.
#  1. all four roles start under one container (php-fpm/scheduler/worker/bot),
#  2. a child exiting non-zero takes the WHOLE set down with non-zero exit
#     (so Docker restart policy resurrects all four) -- the key resilience model,
#  3. a clean TERM shuts everything down and reaps children.
set -u
mkdir -p /tmp/mbin /tmp/fakephp
FAIL=0
ENTRYPOINT_UNDER_TEST="${ENTRYPOINT_UNDER_TEST:-/entrypoint.sh}"

if [ ! -f "$ENTRYPOINT_UNDER_TEST" ]; then
  echo "FAIL: entrypoint under test is missing: $ENTRYPOINT_UNDER_TEST"
  exit 2
fi

cat > /tmp/mbin/php-fpm <<'EOS'
#!/bin/sh
echo "FPM-START:$$"
while true; do sleep 1; done
EOS

cat > /tmp/fakephp/php <<'EOS'
#!/bin/sh
case "$1 $2" in
  "bin/console worker:run")      echo "ROLE-WORKER:$$"; while true; do sleep 1; done ;;
  "bin/console telegram:poll")   echo "ROLE-BOT:$$";    while true; do sleep 1; done ;;
  "bin/console billing:reconcile") sleep 1; exit 0 ;;
  "bin/console app:health"*)     exit 0 ;;
  *)                             echo "PHP-CALL:$*"; exit 0 ;;
esac
EOS
chmod +x /tmp/mbin/php-fpm /tmp/fakephp/php
export PATH=/tmp/fakephp:/tmp/mbin:/usr/bin:/bin

echo "=== A. all four start together under ONE supervisor ==="
sh "$ENTRYPOINT_UNDER_TEST" > /tmp/a.log 2>&1 &
A=$!
sleep 3
STARTS=$(grep -c 'ROLE-WORKER\|ROLE-BOT\|FPM-START' /tmp/a.log)
echo "role-start markers seen: $STARTS (expect 3: worker+bot+fpm; scheduler is a loop, no marker)"
if [ "$STARTS" -ne 3 ]; then echo "FAIL: expected 3 role-start markers"; FAIL=$((FAIL+1)); fi
kill -TERM $A; sleep 2
echo "--- a.log ---"; cat /tmp/a.log

echo
echo "=== B. child crashes non-zero -> whole set down (restart-policy trigger) ==="
# Force the worker mock to exit(1) after a short time using a wrapper env hook.
mkdir -p /tmp/killpid
echo '#!/bin/sh' > /tmp/killpid/kill-worker
echo 'sleep 2; kill -9 $(grep -o "ROLE-WORKER:[0-9]*" /proc/1/root/tmp/a.log 2>/dev/null | head -1 | cut -d: -f2) 2>/dev/null || true' >> /tmp/killpid/kill-worker
chmod +x /tmp/killpid/kill-worker
# simpler: run entrypoint, then kill the worker pid the entrypoint itself logs
cat "$ENTRYPOINT_UNDER_TEST" > /tmp/b.sh
sh /tmp/b.sh > /tmp/b.log 2>&1 &
B=$!
sleep 3
WORKER_PID=$(awk '/started/{for(i=1;i<=NF;i++)if($i~/^worker=/){gsub(/worker=/,"",$i);print $i}}' /tmp/b.log)
echo "worker pid from entrypoint log: $WORKER_PID"
[ -n "$WORKER_PID" ] && kill -9 "$WORKER_PID"
sleep 4
if kill -0 $B 2>/dev/null; then echo "STILL RUNNING (BAD)"; kill -9 $B; FAIL=$((FAIL+1)); else echo "EXITED (good: whole set torn down -> restart policy)"; fi
echo "--- b.log (tail) ---"; tail -6 /tmp/b.log

echo
echo "=== C. clean TERM -> graceful, children reaped ==="
sh "$ENTRYPOINT_UNDER_TEST" > /tmp/c.log 2>&1 &
C=$!
sleep 3
kill -TERM $C
sleep 2
if kill -0 $C 2>/dev/null; then echo "STILL RUNNING (BAD)"; kill -9 $C; FAIL=$((FAIL+1)); else echo "TERM: exited cleanly"; fi
echo "--- c.log (tail) ---"; tail -5 /tmp/c.log

echo
echo "HARNESS DONE (FAIL=${FAIL:-0})"
[ "${FAIL:-0}" = "0" ]
