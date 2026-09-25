#!/usr/bin/env bash
set -euo pipefail
umask 077

# Run from the project directory. Store output on an encrypted/offsite target.
# Defaults preserve the PHP Compose deployment. Rails deployments can override
# the service, database, key path, and DB role without changing this script.
usage() {
  cat >&2 <<'USAGE'
Usage: scripts/backup.sh /secure/backup-directory

Optional environment overrides:
  BACKUP_DB_SERVICE=db
  BACKUP_APP_SERVICE=app
  BACKUP_DB_NAME=billing
  BACKUP_DB_USER=billing_owner
  BACKUP_MASTER_KEY_PATH=/app/var/master.key
USAGE
}

if [[ $# -ne 1 || -z ${1:-} ]]; then
  usage
  exit 2
fi

archive_dir=$1
db_service=${BACKUP_DB_SERVICE:-db}
app_service=${BACKUP_APP_SERVICE:-app}
db_name=${BACKUP_DB_NAME:-billing}
db_user=${BACKUP_DB_USER:-billing_owner}
master_key_path=${BACKUP_MASTER_KEY_PATH:-/app/var/master.key}

# Restrict Compose service and PostgreSQL identifiers to conservative names;
# all values are still passed as individual argv elements, never evaluated.
identifier_re='^[A-Za-z_][A-Za-z0-9_.-]*$'
for entry in "db service:$db_service" "app service:$app_service" "database:$db_name" "database user:$db_user"; do
  label=${entry%%:*}
  value=${entry#*:}
  if [[ ! $value =~ $identifier_re || ${#value} -gt 63 ]]; then
    printf 'Invalid %s: %q\n' "$label" "$value" >&2
    exit 2
  fi
done
if [[ $master_key_path != /* || $master_key_path == *$'\n'* || $master_key_path == *$'\r'* ]]; then
  printf 'BACKUP_MASTER_KEY_PATH must be an absolute container path without newlines.\n' >&2
  exit 2
fi
if [[ $archive_dir != /* ]]; then
  printf 'Backup directory must be an absolute host path.\n' >&2
  exit 2
fi

project_dir=$(pwd -P)
lockfiles=()
[[ ! -f "$project_dir/composer.lock" ]] || lockfiles+=("$project_dir/composer.lock")
[[ ! -f "$project_dir/rails_backend/Gemfile.lock" ]] || lockfiles+=("$project_dir/rails_backend/Gemfile.lock")
if [[ ${#lockfiles[@]} -eq 0 ]]; then
  printf 'No supported lockfile found (expected composer.lock or rails_backend/Gemfile.lock).\n' >&2
  exit 2
fi

if [[ -L $archive_dir ]]; then
  printf 'Backup directory must not be a symbolic link.\n' >&2
  exit 2
fi
mkdir -p "$archive_dir"
if [[ ! -d $archive_dir || ! -w $archive_dir ]]; then
  printf 'Backup directory is not a writable directory.\n' >&2
  exit 2
fi
archive_dir=$(cd "$archive_dir" && pwd -P)

command -v docker >/dev/null 2>&1 || { printf 'docker is required.\n' >&2; exit 127; }
if command -v sha256sum >/dev/null 2>&1; then
  checksum_tool=sha256sum
elif command -v shasum >/dev/null 2>&1; then
  checksum_tool='shasum -a 256'
else
  printf 'sha256sum or shasum is required.\n' >&2
  exit 127
fi

work_dir=$(mktemp -d "$archive_dir/.zeleboba-backup.XXXXXX")
chmod 700 "$work_dir"
cleanup() { rm -rf "$work_dir"; }
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

docker compose exec -T "$db_service" pg_dump -U "$db_user" -Fc "$db_name" > "$work_dir/database.dump"
docker compose exec -T "$app_service" cat -- "$master_key_path" > "$work_dir/master.key"
if [[ ! -s $work_dir/database.dump ]]; then
  printf 'Database dump is empty; refusing to create archive.\n' >&2
  exit 1
fi
if [[ $(wc -c < "$work_dir/master.key" | tr -d '[:space:]') != 32 ]]; then
  printf 'Master key must be exactly 32 bytes; refusing to create archive.\n' >&2
  exit 1
fi

mkdir -p "$work_dir/lockfiles"
for lockfile in "${lockfiles[@]}"; do
  case "$lockfile" in
    */composer.lock) cp "$lockfile" "$work_dir/lockfiles/composer.lock" ;;
    */rails_backend/Gemfile.lock) cp "$lockfile" "$work_dir/lockfiles/rails_Gemfile.lock" ;;
  esac
done
cat > "$work_dir/README" <<EOF
ZeleBoba PostgreSQL backup
Database: $db_name
Encryption key and database dump must be restored together.
The archive contains secrets and is not encrypted by this script.
Lockfiles are stored under lockfiles/.
Restore only into a newly created isolated database; see docs/operations.md.
EOF

backup_name="zeleboba-$(date -u +%Y%m%dT%H%M%SZ)-$(basename "$work_dir" | sed 's/^.*\.//')"
archive_tmp="$archive_dir/.$backup_name.tar.gz.tmp"
archive_path="$archive_dir/$backup_name.tar.gz"
checksum_tmp="$archive_dir/.$backup_name.sha256.tmp"
checksum_path="$archive_dir/$backup_name.sha256"
if [[ -e $archive_path || -e $checksum_path ]]; then
  printf 'Refusing to overwrite an existing backup.\n' >&2
  exit 1
fi

tar -C "$work_dir" -czf "$archive_tmp" .
chmod 600 "$archive_tmp"
if [[ $checksum_tool == 'shasum -a 256' ]]; then
  shasum -a 256 "$archive_tmp" | sed "s|$(basename "$archive_tmp")|$backup_name.tar.gz|" > "$checksum_tmp"
else
  sha256sum "$archive_tmp" | sed "s|$(basename "$archive_tmp")|$backup_name.tar.gz|" > "$checksum_tmp"
fi
chmod 600 "$checksum_tmp"
mv "$archive_tmp" "$archive_path"
mv "$checksum_tmp" "$checksum_path"
printf 'Backup created: %s\n' "$archive_path"
