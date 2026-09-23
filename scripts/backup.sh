#!/usr/bin/env bash
set -euo pipefail
umask 077
# Run from the project directory. Store output on an encrypted/offsite target.
archive_dir=${1:?Usage: scripts/backup.sh /secure/backup-directory}
mkdir -p "$archive_dir"
backup_name="billing-$(date -u +%Y%m%dT%H%M%SZ)"
work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT
docker compose exec -T db pg_dump -U billing_owner -Fc billing > "$work_dir/database.dump"
docker compose exec -T app cat /app/var/master.key > "$work_dir/master.key"
test "$(wc -c < "$work_dir/master.key" | tr -d ' ')" = 32
cp Gemfile.lock "$work_dir/Gemfile.lock"
printf '%s\n' 'Database and encryption key must be restored together. Archive contains secrets.' > "$work_dir/README"
tar -C "$work_dir" -czf "$archive_dir/$backup_name.tar.gz" .
chmod 600 "$archive_dir/$backup_name.tar.gz"
shasum -a 256 "$archive_dir/$backup_name.tar.gz" > "$archive_dir/$backup_name.sha256"
printf 'Backup created: %s\n' "$archive_dir/$backup_name.tar.gz"
