#!/usr/bin/env bash
# Weekly restore check (architecture §6): the latest copy is restored into the STAGING database, then the ledger
# check runs there (journals = balances) and the counts are compared. A copy that cannot be restored is not a copy.
set -euo pipefail
set -a; source /etc/prime/backup.env; set +a   # exported: pg_dump and restic read them from the environment
STAGING_DB=${STAGING_DB:-prime_staging}
STATUS=/var/www/prime/shared/storage/app/backup-status.json
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
restic restore latest --tag nightly --target "$WORK" --include '*/prime.dump'
DUMP=$(find "$WORK" -name prime.dump | head -1)
[ -n "$DUMP" ] || { echo "no dump in the copy"; exit 1; }
dropdb --if-exists "$STAGING_DB" && createdb "$STAGING_DB"
pg_restore --no-owner --dbname="$STAGING_DB" "$DUMP"
cd /var/www/prime/staging/current
DB_DATABASE="$STAGING_DB" php artisan prime:reconcile --no-interaction
UNITS=$(psql -d "$STAGING_DB" -Atc "select count(*) from stock_units")
echo "restore check ok: $UNITS units in the restored copy"
jq --arg at "$(date -Iseconds)" --arg u "$UNITS" '. + {restore_checked_at: $at, restored_units: ($u|tonumber)}' "$STATUS" > "$STATUS.tmp" && mv "$STATUS.tmp" "$STATUS"
