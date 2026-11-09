#!/usr/bin/env bash
# Prepare an isolated copy of Prime Mobile OS inside a git worktree.
# Usage (run from anywhere inside the worktree):
#   bash wt-setup.sh <slug> <branch> [base-ref]
#   slug     short lowercase module id, e.g. pos  -> DBs prime_mobile_pos / prime_mobile_test_pos
#   branch   branch to create in this worktree, e.g. wip/pos
#   base-ref commit/branch to start from (default: claude/nifty-johnson-nwlyrg)
set -euo pipefail
SLUG="$1"; BRANCH="$2"; BASE="${3:-claude/nifty-johnson-nwlyrg}"
MAIN=/home/user/landing/prime-mobile-os
ROOT="$(git rev-parse --show-toplevel)"
if [ "$ROOT" = "/home/user/landing" ]; then
  echo "Refusing: this is the main tree, not a worktree." >&2; exit 1
fi
cd "$ROOT"
git checkout -q -B "$BRANCH" "$BASE"
APP="$ROOT/prime-mobile-os"
cd "$APP"
# vendor: real copy without git history (symlink would make Composer load the MAIN tree's app/)
rm -rf "$APP/vendor" "$APP/node_modules"
tar -C "$MAIN" --exclude=.git -cf - vendor | tar -C "$APP" -xf -
tar -C "$MAIN" -cf - node_modules | tar -C "$APP" -xf -
cp "$MAIN/.env" "$APP/.env"
sed -i "s/^DB_DATABASE=.*/DB_DATABASE=prime_mobile_${SLUG}/" "$APP/.env"
sed -i "s|^APP_URL=.*|APP_URL=http://127.0.0.1|" "$APP/.env"
for db in "prime_mobile_${SLUG}" "prime_mobile_test_${SLUG}"; do
  su postgres -c "psql -q -c 'DROP DATABASE IF EXISTS ${db} WITH (FORCE)'" >/dev/null
  su postgres -c "createdb -O prime ${db}"
done
composer dump-autoload -q
php artisan migrate:fresh --seed -q
echo "READY"
echo "  app dir:        $APP"
echo "  branch:         $BRANCH (from $BASE @ $(git rev-parse --short HEAD))"
echo "  dev database:   prime_mobile_${SLUG}  (php artisan ... uses it via .env)"
echo "  test command:   DB_DATABASE=prime_mobile_test_${SLUG} php artisan test"
