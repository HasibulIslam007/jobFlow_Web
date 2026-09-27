#!/usr/bin/env bash
#
# Manages the project-local PostgreSQL cluster used for local development.
#
# Why a separate cluster: a system PostgreSQL 18 install already owns port 5432
# on this machine (password-protected), so JobFlow runs its own PostgreSQL 16
# cluster on port 5433 using the Homebrew binaries. No system changes required.
#
# Usage:
#   ./scripts/db.sh start      # start the cluster
#   ./scripts/db.sh stop       # stop the cluster
#   ./scripts/db.sh status     # cluster + database overview
#   ./scripts/db.sh psql       # open psql against the development database
#   ./scripts/db.sh reset      # drop and recreate dev + test databases
#
set -euo pipefail

PGBIN="${PGBIN:-/opt/homebrew/opt/postgresql@16/bin}"
PGDATA="${PGDATA:-/opt/homebrew/var/postgresql@16}"
PGPORT="${PGPORT:-5433}"
PGLOG="${PGLOG:-/tmp/jobflow-pg16.log}"
DEV_DB="${DEV_DB:-jobflow_api_development}"
TEST_DB="${TEST_DB:-jobflow_api_test}"
APP_ROLE="${APP_ROLE:-jobflow}"

command="${1:-status}"

case "$command" in
  start)
    "$PGBIN/pg_ctl" -D "$PGDATA" -o "-p $PGPORT -k /tmp" -l "$PGLOG" start
    ;;
  stop)
    "$PGBIN/pg_ctl" -D "$PGDATA" stop
    ;;
  status)
    "$PGBIN/pg_ctl" -D "$PGDATA" status || true
    "$PGBIN/psql" -w -h 127.0.0.1 -p "$PGPORT" -U "$APP_ROLE" -d "$DEV_DB" -tAc \
      "select current_user || '@' || current_database() || ' on ' || current_setting('port')" </dev/null
    ;;
  psql)
    shift || true
    exec "$PGBIN/psql" -w -h 127.0.0.1 -p "$PGPORT" -U "$APP_ROLE" -d "$DEV_DB" "$@" < /dev/null
    ;;
  reset)
    echo "Recreating $DEV_DB and $TEST_DB ..."
    "$PGBIN/dropdb" -w -h 127.0.0.1 -p "$PGPORT" -U "$APP_ROLE" --if-exists "$DEV_DB" </dev/null
    "$PGBIN/dropdb" -w -h 127.0.0.1 -p "$PGPORT" -U "$APP_ROLE" --if-exists "$TEST_DB" </dev/null
    "$PGBIN/createdb" -w -h 127.0.0.1 -p "$PGPORT" -U "$APP_ROLE" -O "$APP_ROLE" "$DEV_DB" </dev/null
    "$PGBIN/createdb" -w -h 127.0.0.1 -p "$PGPORT" -U "$APP_ROLE" -O "$APP_ROLE" "$TEST_DB" </dev/null
    echo "Done. Run: php artisan migrate"
    ;;
  *)
    echo "Unknown command: $command" >&2
    echo "Usage: $0 {start|stop|status|psql|reset}" >&2
    exit 1
    ;;
esac
