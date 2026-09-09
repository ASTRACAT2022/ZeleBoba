#!/bin/sh
set -eu
psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v runtime_password="$BILLING_RUNTIME_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE billing LOGIN PASSWORD %L', :'runtime_password') \gexec
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO billing;
SQL
