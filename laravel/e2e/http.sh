#!/bin/sh
set -eu

base=http://web
jar=/tmp/cookies.txt
headers=/tmp/headers.txt

for attempt in $(seq 1 30); do
  if curl -fsS "$base/up" | grep -q 'Laravel'; then break; fi
  [ "$attempt" = 30 ] && { echo 'Laravel health endpoint did not become ready' >&2; exit 1; }
  sleep 1
done

curl -fsS -c "$jar" "$base/register" >/dev/null
csrf=$(awk '$6 == "XSRF-TOKEN" { token=$7 } END { print token }' "$jar" | sed 's/%3D/=/g; s/%2F/\//g; s/%2B/+/g')
[ -n "$csrf" ]
curl -fsS -D "$headers" -o /dev/null -b "$jar" -c "$jar" -H "X-XSRF-TOKEN: $csrf" \
  --data 'email=e2e%40example.test&password=correct-horse-battery' "$base/register"
order_path=$(awk 'BEGIN { IGNORECASE=1 } /^location:/ { print $2 }' "$headers" | tr -d '\r' | tail -1)
[ "$order_path" = / ]

curl -fsS -b "$jar" -c "$jar" "$base/plans" | grep -q 'E2E Basic'
csrf=$(awk '$6 == "XSRF-TOKEN" { token=$7 } END { print token }' "$jar" | sed 's/%3D/=/g; s/%2F/\//g; s/%2B/+/g')
curl -fsS -D "$headers" -o /dev/null -b "$jar" -c "$jar" -H "X-XSRF-TOKEN: $csrf" \
  --data 'plan_id=e2ebasic&idempotency_key=e2e-checkout-key-1' "$base/orders"
order_path=$(awk 'BEGIN { IGNORECASE=1 } /^location:/ { print $2 }' "$headers" | tr -d '\r' | tail -1)
case "$order_path" in /orders/[a-f0-9][a-f0-9]*) ;; *) echo "Unexpected order redirect: $order_path" >&2; exit 1;; esac
order_id=${order_path#/orders/}
csrf=$(awk '$6 == "XSRF-TOKEN" { token=$7 } END { print token }' "$jar" | sed 's/%3D/=/g; s/%2F/\//g; s/%2B/+/g')
curl -fsS -D "$headers" -o /dev/null -b "$jar" -c "$jar" -H "X-XSRF-TOKEN: $csrf" -X POST "$base/orders/$order_id/demo-pay"
curl -fsS -b "$jar" "$base/orders/$order_id" | grep -q 'fulfilled'
echo 'Docker HTTP E2E passed'
