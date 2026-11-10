#!/usr/bin/env bash
# Zero-downtime release (architecture §6): a new folder → composer, build, migrations → switch the «current» link →
# /health → if it fails, the link goes back to the previous release. 5 releases are kept.
# Usage on the server as the «prime» user:  deploy/scripts/release.sh <git ref>
set -euo pipefail
REF=${1:-main}
BASE=/var/www/prime
NEW=$BASE/releases/$(date +%Y%m%d%H%M%S)
PREV=$(readlink -f $BASE/current || true)
git clone --depth 1 --branch "$REF" "$(cat $BASE/shared/repo-url)" "$NEW.src"
mv "$NEW.src/prime-mobile-os" "$NEW" && rm -rf "$NEW.src"
ln -s $BASE/shared/.env "$NEW/.env"
rm -rf "$NEW/storage" && ln -s $BASE/shared/storage "$NEW/storage"
cd "$NEW"
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build && rm -rf node_modules
php artisan migrate --force          # migrations are backward compatible: the previous code still works with them
php artisan config:cache && php artisan route:cache && php artisan view:cache
ln -sfn "$NEW" $BASE/current.tmp && mv -Tf $BASE/current.tmp $BASE/current
sudo systemctl reload php8.3-fpm
sudo supervisorctl restart prime-queue:* prime-outbox
sleep 3
if ! curl -fsS --max-time 10 "https://$(cat $BASE/shared/erp-host)/health" > /dev/null; then
    echo "HEALTH FAILED — rolling back to $PREV"
    [ -n "$PREV" ] && ln -sfn "$PREV" $BASE/current.tmp && mv -Tf $BASE/current.tmp $BASE/current
    sudo systemctl reload php8.3-fpm
    exit 1
fi
ls -1dt $BASE/releases/*/ | tail -n +6 | xargs -r rm -rf
echo "released $REF → $NEW"
