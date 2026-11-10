#!/usr/bin/env bash
# Nightly backup (architecture §6): the database (pg_dump, custom format) + files (photos, private originals) into an
# encrypted restic repository at a SECOND provider in Kazakhstan. Writes storage/app/backup-status.json for /health
# and prime:backup-status. Secrets — only in /etc/prime/backup.env (chmod 600), never in git:
#   PGHOST PGUSER PGPASSWORD PGDATABASE  RESTIC_REPOSITORY  RESTIC_PASSWORD  (+ AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY for S3)
# Recommended upgrade for «на любую минуту»: pgBackRest with WAL archiving (see deploy/README.md).
set -euo pipefail
set -a; source /etc/prime/backup.env; set +a   # exported: pg_dump and restic read them from the environment
APP=/var/www/prime/current
STATUS=/var/www/prime/shared/storage/app/backup-status.json
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
fail() { echo "{\"ok\":false,\"at\":\"$(date -Iseconds)\",\"error\":\"$1\",\"last_ok\":$(jq '.last_ok' "$STATUS" 2>/dev/null || echo null)}" > "$STATUS"; echo "BACKUP FAILED: $1"; exit 1; }

pg_dump --format=custom --no-owner --file="$WORK/prime.dump" || fail "pg_dump"
restic backup --tag nightly "$WORK/prime.dump" /var/www/prime/shared/storage/app --exclude '*.log' || fail "restic backup"
restic forget --tag nightly --keep-daily 30 --keep-monthly 12 --prune || fail "restic forget"
NOW=$(date -Iseconds)
echo "{\"ok\":true,\"at\":\"$NOW\",\"last_ok\":\"$NOW\",\"size\":$(stat -c %s "$WORK/prime.dump")}" > "$STATUS"
echo "backup ok $NOW"
