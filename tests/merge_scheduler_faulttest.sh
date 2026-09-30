#!/bin/sh
set -eu

TASK_TMP=$(mktemp -d)
APP_PID=
cleanup() {
    if [ -n "$APP_PID" ]; then
        kill -TERM "$APP_PID" 2>/dev/null || true
        wait "$APP_PID" 2>/dev/null || true
    fi
    rm -rf "$TASK_TMP"
}
trap cleanup EXIT INT TERM
mkdir "$TASK_TMP/bin"

cat > "$TASK_TMP/bin/php-fpm" <<'EOF'
#!/bin/sh
touch "$TEST_TMP/web-running"
exec sleep 30
EOF
cat > "$TASK_TMP/bin/php" <<'EOF'
#!/bin/sh
case "$2" in
    billing:reconcile) exit 0 ;;
    subscriptions:sync)
        count=$(cat "$TEST_TMP/sync-count" 2>/dev/null || echo 0)
        count=$((count + 1))
        echo "$count" > "$TEST_TMP/sync-count"
        [ "$count" -gt 1 ]
        exit $? ;;
    worker:run|telegram:poll) exec sleep 30 ;;
    *) exit 1 ;;
esac
EOF
chmod +x "$TASK_TMP/bin/php" "$TASK_TMP/bin/php-fpm"

export TEST_TMP="$TASK_TMP"
PATH="$TASK_TMP/bin:$PATH" RECONCILE_SECONDS=1 sh infra/merge-entrypoint.sh > "$TASK_TMP/app.log" 2>&1 &
APP_PID=$!

attempt=0
while [ "$(cat "$TASK_TMP/sync-count" 2>/dev/null || echo 0)" -lt 2 ]; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 100 ] || { cat "$TASK_TMP/app.log"; exit 1; }
    sleep 0.1
done

kill -0 "$APP_PID"
[ -f "$TASK_TMP/web-running" ]
grep -q '\[scheduler\] subscriptions:sync failed; retrying next cycle' "$TASK_TMP/app.log"
echo 'scheduler retry kept web process running'
