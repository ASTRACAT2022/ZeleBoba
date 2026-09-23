#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
port=${E2E_PORT:-9493}
base="http://127.0.0.1:${port}"
db=$(mktemp "${TMPDIR:-/tmp}/zeleboba-ruby-e2e.XXXXXX.sqlite")
cookie_jar=$(mktemp)
headers=$(mktemp)
server_log=$(mktemp)
server_pid=""

cleanup() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -f "$db" "$cookie_jar" "$headers" "$server_log"
}
trap cleanup EXIT INT TERM

fail() {
  local status=$?
  if [[ $status -ne 0 && -s "$server_log" ]]; then
    printf '\nRuby server log:\n' >&2
    cat "$server_log" >&2
  fi
  exit "$status"
}
trap fail ERR

csrf_from() {
  grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/'
}

key_from() {
  grep -o 'name="idempotency_key" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/'
}

redirect_url() {
  local location
  location=$(awk 'tolower($1) == "location:" {print $2}' "$headers" | tr -d '\r')
  [[ "$location" == http://* || "$location" == https://* ]] && printf '%s' "$location" || printf '%s%s' "$base" "$location"
}

environment=(APP_ENV=dev PURCHASES_ENABLED=1 DATABASE_DSN="sqlite:${db}")
cd "$root"
env "${environment[@]}" ruby bin/console-ruby db:migrate >/dev/null
env "${environment[@]}" ruby bin/console-ruby db:seed >/dev/null
env "${environment[@]}" bundle exec rackup config.ru --host 127.0.0.1 --port "$port" >"$server_log" 2>&1 &
server_pid=$!
for _ in {1..30}; do
  curl -fsS "$base/health/live" >/dev/null 2>&1 && break
  sleep 0.1
done
curl -fsS "$base/health/live" >/dev/null

email="ruby-e2e-$(date +%s)-$RANDOM@example.test"
register=$(curl -fsS -c "$cookie_jar" "$base/register")
csrf=$(printf '%s' "$register" | csrf_from)
[[ -n "$csrf" ]]
[[ $(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" -X POST "$base/register" --data-urlencode "email=$email" --data-urlencode 'password=correct-horse-battery' --data-urlencode "_csrf=$csrf") == "303" ]]

plans=$(curl -fsS -b "$cookie_jar" "$base/plans")
csrf=$(printf '%s' "$plans" | csrf_from)
key=$(printf '%s' "$plans" | key_from)
[[ -n "$csrf" && -n "$key" ]]
[[ $(curl -sS -o /dev/null -D "$headers" -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" -X POST "$base/orders" --data-urlencode 'plan_id=basic' --data-urlencode "idempotency_key=$key" --data-urlencode "_csrf=$csrf") == "303" ]]
order_url=$(redirect_url)

env "${environment[@]}" ruby bin/worker-ruby --once >/dev/null || true
order=$(curl -fsS -b "$cookie_jar" "$order_url")
csrf=$(printf '%s' "$order" | csrf_from)
[[ $(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" -X POST "$order_url/demo-pay" --data-urlencode "_csrf=$csrf") == "303" ]]
env "${environment[@]}" ruby bin/worker-ruby --once >/dev/null || true
env "${environment[@]}" ruby bin/worker-ruby --once >/dev/null || true
curl -fsS -b "$cookie_jar" "$base/" | grep -q 'Активна'
[[ $(sqlite3 "$db" "SELECT COUNT(*) FROM orders WHERE status='fulfilled';") == "1" ]]
[[ $(sqlite3 "$db" "SELECT COUNT(*) FROM subscriptions WHERE status='active';") == "1" ]]

balance=$(curl -fsS -b "$cookie_jar" "$base/balance")
csrf=$(printf '%s' "$balance" | csrf_from)
key=$(printf '%s' "$balance" | key_from)
[[ $(curl -sS -o /dev/null -D "$headers" -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" -X POST "$base/balance/topup" --data-urlencode 'amount=500' --data-urlencode "idempotency_key=$key" --data-urlencode 'provider=demo' --data-urlencode "_csrf=$csrf") == "303" ]]
topup_url=$(redirect_url)
topup=$(curl -fsS -b "$cookie_jar" "$topup_url")
csrf=$(printf '%s' "$topup" | csrf_from)
[[ $(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" -X POST "$topup_url/demo-pay" --data-urlencode "_csrf=$csrf") == "303" ]]
env "${environment[@]}" ruby bin/worker-ruby --once >/dev/null || true
env "${environment[@]}" ruby bin/worker-ruby --once >/dev/null || true
curl -fsS -b "$cookie_jar" "$base/balance" | grep -q '500 ₽'
[[ $(sqlite3 "$db" "SELECT COUNT(*) FROM topups WHERE status='paid';") == "1" ]]
[[ $(sqlite3 "$db" "SELECT balance_kopeks FROM users WHERE email='${email}';") == "50000" ]]

printf 'Ruby E2E passed: registration, demo checkout, provisioning, and topup.\n'
