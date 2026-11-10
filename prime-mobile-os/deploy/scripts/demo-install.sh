#!/usr/bin/env bash
# Prime Mobile OS — a TEST / DEMO server in one command (fresh Ubuntu 24.04, 4 GB RAM or more, run as root).
# The demo firm «Prime Mobile» with demo phones, sales and placeholder photos. NOT the shop's real server:
# the real one is installed by deploy/README.md without demo data.
#
#   curl -fsSL <raw link to this file> -o demo-install.sh && bash demo-install.sh
#
# Running it again updates the code to the latest commit of the branch (data and passwords stay).
# No domain needed: the addresses are <ip>.sslip.io (a free public name that points at the server's IP).
# Passwords are generated here and stay on this server (/root/prime-demo.txt); nothing goes to git or chat.
#
# Settings (optional, as environment variables):
#   REF=<branch>  REPO_URL=<git url>  SHOP_DOMAIN=<domain>  ERP_DOMAIN=<domain>  HTTPS=0 (plain http)  IP=<public ip>
set -euo pipefail

REPO_URL=${REPO_URL:-https://github.com/aztenel/landing.git}
REF=${REF:-claude/nifty-johnson-nwlyrg}
BASE=/var/www/prime
SHARED=$BASE/shared
INFO=/root/prime-demo.txt
export DEBIAN_FRONTEND=noninteractive

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mОШИБКА: %s\033[0m\n' "$*"; exit 1; }
as_prime() { sudo -u prime -H bash -c "$*"; }
svc() {   # svc <name>: (re)start a service and start it on boot — systemd on a VPS, «service» in a container
    if [ -d /run/systemd/system ]; then systemctl enable "$1" >/dev/null 2>&1 || true; systemctl restart "$1"; else service "$1" restart >/dev/null 2>&1 || service "$1" start >/dev/null 2>&1 || warn "$1: перезапуск не подтвердился"; fi
}
envget() { grep -E "^$1=" "$SHARED/.env" | head -1 | cut -d= -f2-; }

[ "$(id -u)" = 0 ] || die "запустите от root: sudo bash demo-install.sh"
grep -q 'VERSION_ID="24.04"' /etc/os-release || warn "проверено только на Ubuntu 24.04 — на другой системе может не встать"
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
[ "$MEM_MB" -ge 3500 ] || warn "памяти ${MEM_MB} МБ — нужно от 4 ГБ, система может тормозить"

# ---------------------------------------------------------------- 1. programs
say "1/9 Программы (5–10 минут)"
apt-get update -qq
apt-get install -y -qq nginx postgresql redis-server supervisor git curl jq unzip openssl restic sudo ca-certificates \
    php8.3-fpm php8.3-cli php8.3-pgsql php8.3-redis php8.3-gd php8.3-intl php8.3-mbstring php8.3-xml php8.3-zip \
    php8.3-bcmath php8.3-curl composer nodejs npm certbot python3-certbot-nginx >/dev/null
ln -sf /usr/share/zoneinfo/UTC /etc/localtime          # the server in UTC, the app counts the firm's time (Asia/Almaty)
if [ "$(swapon --show | wc -l)" = 0 ] && [ "$MEM_MB" -lt 7000 ] && [ ! -f /swapfile ]; then
    # a spare 2 GB so that the build (npm) never runs out of memory; ignored where swap is not allowed (containers)
    { fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile \
        && echo '/swapfile none swap sw 0 0' >> /etc/fstab; } 2>/dev/null || { rm -f /swapfile; warn "swap не создан — не страшно"; }
fi
svc postgresql; svc redis-server
if ! redis-cli ping 2>/dev/null | grep -q PONG && [ ! -d /run/systemd/system ]; then
    redis-server /etc/redis/redis.conf --daemonize yes >/dev/null 2>&1 || true    # containers without systemd
fi
for i in $(seq 1 15); do pg_isready -q -h 127.0.0.1 && redis-cli ping 2>/dev/null | grep -q PONG && break; sleep 1; done
pg_isready -q -h 127.0.0.1 || die "PostgreSQL не запустился: systemctl status postgresql"
redis-cli ping 2>/dev/null | grep -q PONG || die "Redis не запустился: systemctl status redis-server"

# ---------------------------------------------------------------- 2. addresses
say "2/9 Адреса"
IP=${IP:-$(curl -4fsS --max-time 10 https://api.ipify.org || hostname -I | awk '{print $1}')}
SHOP=${SHOP_DOMAIN:-$(echo "$IP" | tr . -).sslip.io}
ERP=${ERP_DOMAIN:-erp.$SHOP}
echo "IP $IP · магазин $SHOP · ERP $ERP"

# ---------------------------------------------------------------- 3. user, folders, code access
say "3/9 Пользователь prime и доступ к коду"
id prime >/dev/null 2>&1 || useradd -m -s /bin/bash -G www-data prime
mkdir -p $BASE/releases $SHARED/storage/{app/public,framework/cache,framework/sessions,framework/views,logs}
chown -R prime:www-data $BASE
if ! as_prime "GIT_TERMINAL_PROMPT=0 git ls-remote --exit-code '$REPO_URL' '$REF'" >/dev/null 2>&1; then
    # a private repository: a read-only «deploy key» of this server, added once in GitHub
    SSH_URL=$(echo "$REPO_URL" | sed -E 's#^https://github.com/#git@github.com:#')
    [ -f /home/prime/.ssh/id_ed25519 ] || as_prime "mkdir -p ~/.ssh && ssh-keygen -q -t ed25519 -N '' -C prime-demo -f ~/.ssh/id_ed25519"
    as_prime "ssh-keyscan -t ed25519 github.com >> ~/.ssh/known_hosts 2>/dev/null; sort -u -o ~/.ssh/known_hosts ~/.ssh/known_hosts"
    if as_prime "GIT_TERMINAL_PROMPT=0 git ls-remote --exit-code '$SSH_URL' '$REF'" >/dev/null 2>&1; then
        REPO_URL=$SSH_URL
    else
        echo
        echo "Репозиторий закрытый. Один раз добавьте ключ этого сервера в GitHub (только чтение):"
        echo "  GitHub → репозиторий → Settings → Deploy keys → Add deploy key"
        echo "  Title: prime-demo   ·   «Allow write access» НЕ ставить   ·   Key — строка ниже целиком:"
        echo
        cat /home/prime/.ssh/id_ed25519.pub
        echo
        echo "Это открытый ключ, он не секретный. Потом запустите скрипт ещё раз: bash demo-install.sh"
        exit 2
    fi
fi
echo "$REPO_URL" > $SHARED/repo-url
echo "$ERP" > $SHARED/erp-host

# ---------------------------------------------------------------- 4. nginx + HTTPS
say "4/9 Веб-сервер и HTTPS"
write_nginx() {   # http only; certbot adds https and the redirect to these two blocks
    cat > /etc/nginx/sites-available/prime.conf <<NGINX
# Prime Mobile OS — demo (deploy/scripts/demo-install.sh). Production: deploy/nginx/prime.conf
limit_req_zone \$binary_remote_addr zone=site:10m rate=10r/s;
limit_req_zone \$binary_remote_addr zone=login:10m rate=10r/m;

server {   # ERP
    listen 80;
    server_name $ERP;
    root $BASE/current/public;
    index index.php;
    client_max_body_size 30m;
    add_header X-Content-Type-Options nosniff always;
    location = /login { limit_req zone=login burst=10 nodelay; try_files \$uri /index.php?\$query_string; }
    location /build/   { expires 1y; access_log off; add_header Cache-Control "public, immutable"; }
    location /storage/ { expires 30d; access_log off; }
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/prime-erp.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }
    location ~ /\.(?!well-known) { deny all; }
}

server {   # shop
    listen 80;
    server_name $SHOP;
    root $BASE/current/public;
    index index.php;
    client_max_body_size 1m;
    add_header X-Content-Type-Options nosniff always;
    location /build/        { expires 1y; access_log off; add_header Cache-Control "public, immutable"; }
    location /storage/site/ { expires 1y; access_log off; add_header Cache-Control "public, immutable"; }
    location /storage/      { deny all; }
    location / { limit_req zone=site burst=40 nodelay; try_files \$uri /index.php?\$query_string; }
    location ~ \.php\$ {
        limit_req zone=site burst=40 nodelay;
        fastcgi_pass unix:/run/php/prime-site.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 30;
    }
    location ~ /\.(?!well-known) { deny all; }
}
NGINX
}
if [ ! -f /etc/nginx/sites-available/prime.conf ] || ! grep -q "server_name $ERP;" /etc/nginx/sites-available/prime.conf; then
    write_nginx
fi
ln -sf /etc/nginx/sites-available/prime.conf /etc/nginx/sites-enabled/prime.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t -q && svc nginx
SCHEME=http
if [ "${HTTPS:-1}" = 1 ]; then
    if certbot certificates 2>/dev/null | grep -q "Domains: .*$ERP" \
        || certbot --nginx --non-interactive --agree-tos --register-unsafely-without-email --redirect -d "$SHOP" -d "$ERP" >/dev/null 2>&1; then
        SCHEME=https
    else
        warn "HTTPS не получился (Let's Encrypt). Работаем по http — для проверки хватит, но камера и «установить на экран» на iPhone без https не работают"
    fi
fi

# ---------------------------------------------------------------- 5. settings (.env) and database
say "5/9 Настройки и база"
if [ ! -f $SHARED/.env ]; then
    DB_PASS=$(openssl rand -hex 24)
    cat > $SHARED/.env <<ENV
# Demo server. Generated by deploy/scripts/demo-install.sh — stays on this server only.
APP_NAME="Prime Mobile"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=$SCHEME://$ERP
APP_LOCALE=ru
APP_FALLBACK_LOCALE=en
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
PRIME_ERP_DOMAIN=$ERP
PRIME_SHOP_DOMAIN=$SHOP
BLIND_INDEX_KEY=$(openssl rand -hex 32)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=prime
DB_USERNAME=prime
DB_PASSWORD=$DB_PASS
DB_SITE_USERNAME=prime_site
DB_SITE_PASSWORD=$(openssl rand -hex 24)
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=$([ $SCHEME = https ] && echo true || echo false)
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
MAIL_MAILER=log
FILESYSTEM_DISK=local
ENV
fi
# the scheme may change between runs (https appeared later) — keep the addresses right
sed -i -E "s#^APP_URL=.*#APP_URL=$SCHEME://$ERP#; s#^PRIME_ERP_DOMAIN=.*#PRIME_ERP_DOMAIN=$ERP#; s#^PRIME_SHOP_DOMAIN=.*#PRIME_SHOP_DOMAIN=$SHOP#; s#^SESSION_SECURE_COOKIE=.*#SESSION_SECURE_COOKIE=$([ $SCHEME = https ] && echo true || echo false)#" $SHARED/.env
chown prime:prime $SHARED/.env && chmod 600 $SHARED/.env
DB_PASS=$(envget DB_PASSWORD)
if ! sudo -u postgres psql -tAc "select 1 from pg_roles where rolname = 'prime'" | grep -q 1; then
    sudo -u postgres psql -q -c "create role prime login password '$DB_PASS'"
fi
sudo -u postgres psql -tAc "select 1 from pg_database where datname = 'prime'" | grep -q 1 || sudo -u postgres createdb prime --owner prime

# ---------------------------------------------------------------- 6. PHP pools (smaller for a 4 GB server) and the code
say "6/9 Код (5–10 минут: сборка)"
PHPV=8.3
NEW=$BASE/releases/$(date +%Y%m%d%H%M%S)
PREV=$(readlink -f $BASE/current 2>/dev/null || true)
as_prime "git clone -q --depth 1 --branch '$REF' '$REPO_URL' '$NEW.src'"
as_prime "mv '$NEW.src/prime-mobile-os' '$NEW' && rm -rf '$NEW.src'"
# PHP-FPM pools from the repository, with fewer processes for a 4 GB server
sed 's/^pm.max_children = 16/pm.max_children = 8/; s/^pm.max_spare_servers = 6/pm.max_spare_servers = 4/' \
    "$NEW/deploy/php-fpm/prime-erp.conf" > /etc/php/$PHPV/fpm/pool.d/prime-erp.conf
sed 's/^pm.max_children = 8/pm.max_children = 4/' "$NEW/deploy/php-fpm/prime-site.conf" > /etc/php/$PHPV/fpm/pool.d/prime-site.conf
[ -f /etc/php/$PHPV/fpm/pool.d/www.conf ] && mv /etc/php/$PHPV/fpm/pool.d/www.conf /etc/php/$PHPV/fpm/pool.d/www.conf.off
as_prime "cd '$NEW' && ln -s $SHARED/.env .env && rm -rf storage && ln -s $SHARED/storage storage \
    && composer install -q --no-dev --optimize-autoloader --no-interaction \
    && npm ci --no-audit --no-fund --loglevel=error && npm run build >/dev/null && rm -rf node_modules \
    && php artisan migrate --force -q && php artisan storage:link -q \
    && php artisan config:cache -q && php artisan route:cache -q && php artisan view:cache -q"

# ---------------------------------------------------------------- 7. demo data, the site's own database role
say "7/9 Демо-данные"
if [ ! -f $SHARED/.seeded ]; then
    for seeder in RolesAndPermissionsSeeder CatalogSeeder PrimeMobileSeeder DemoSiteSeeder; do
        as_prime "cd '$NEW' && php artisan db:seed --class=$seeder --force -q"
    done
    DEMO_PASS="pm-$(openssl rand -hex 3)-$(openssl rand -hex 3)"
    # the seeded «password» is replaced by a random one (the demo server is open to the internet)
    HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT, ["cost" => 12]);' "$DEMO_PASS")
    echo "update users set password = :'h', password_changed_at = now() where phone in ('+77010000001', '+77010000002', '+77010000003');" \
        | sudo -u postgres psql -q -v ON_ERROR_STOP=1 -d prime -v h="$HASH"
    umask 077
    cat > $INFO <<TXT
Prime Mobile OS — тестовый сервер (демо-данные, не настоящий магазин)
ERP:      $SCHEME://$ERP
Магазин:  $SCHEME://$SHOP
Пароль у всех трёх демо-входов: $DEMO_PASS
  Кайрат (владелец)   77010000001
  Айгерим (продавец)  77010000002
  Данияр (продавец)   77010000003
Владельцу при первом входе система предложит включить вход с кодом из приложения (Google Authenticator) —
это часть защиты, сохраните запасные коды.
TXT
    umask 022
    touch $SHARED/.seeded
fi
# the site reads the database under its own role: only the shop window, never cost, suppliers or customers.
# Run every time: new tables from new migrations get their grants too.
SITE_PASS=$(envget DB_SITE_PASSWORD)
SQL=$(mktemp)
if sudo -u postgres psql -tAc "select 1 from pg_roles where rolname = 'prime_site'" | grep -q 1; then
    sed '/^CREATE ROLE/d' "$NEW/deploy/postgres/site_role.sql" > "$SQL"
    echo "ALTER ROLE prime_site PASSWORD '$SITE_PASS';" >> "$SQL"
else
    sed "s/CHANGE_ME_ON_THE_SERVER/$SITE_PASS/" "$NEW/deploy/postgres/site_role.sql" > "$SQL"
fi
chmod 644 "$SQL"
sudo -u postgres psql -q -v ON_ERROR_STOP=1 -d prime -f "$SQL"
rm -f "$SQL"

# ---------------------------------------------------------------- 8. switch, background workers, schedule, copies
say "8/9 Запуск"
ln -sfn "$NEW" $BASE/current.tmp && mv -Tf $BASE/current.tmp $BASE/current
chown -h prime:www-data $BASE/current
svc php$PHPV-fpm
cp "$NEW/deploy/supervisor/prime.conf" /etc/supervisor/conf.d/prime.conf
svc supervisor
for i in $(seq 1 15); do supervisorctl status >/dev/null 2>&1; [ $? -ne 4 ] && [ -S /var/run/supervisor.sock ] && break; sleep 1; done
if [ ! -S /var/run/supervisor.sock ] && [ ! -d /run/systemd/system ]; then
    supervisord -c /etc/supervisor/supervisord.conf && sleep 2                     # containers without systemd
fi
[ -S /var/run/supervisor.sock ] || die "supervisor не запустился: systemctl status supervisor"
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart 'prime-queue:*' prime-outbox >/dev/null || warn "фоновые задачи не перезапустились — проверьте: supervisorctl status"
# copies: on THIS server only (a demo). The real server keeps them at a second provider — deploy/README.md §6.
mkdir -p /etc/prime /var/backups/prime-restic && chown prime:prime /var/backups/prime-restic
if [ ! -f /etc/prime/backup.env ]; then
    umask 077
    printf 'PGHOST=127.0.0.1\nPGUSER=prime\nPGPASSWORD=%s\nPGDATABASE=prime\nRESTIC_REPOSITORY=/var/backups/prime-restic\nRESTIC_PASSWORD=%s\n' \
        "$DB_PASS" "$(openssl rand -hex 24)" > /etc/prime/backup.env
    umask 022
    chown prime:prime /etc/prime/backup.env
    as_prime "set -a; source /etc/prime/backup.env; restic init -q"
fi
crontab -u prime - <<CRON
* * * * * cd $BASE/current && php artisan schedule:run >> /dev/null 2>&1
30 2 * * * $BASE/current/deploy/backup/backup.sh >> $SHARED/storage/logs/backup.log 2>&1
CRON
command -v cron >/dev/null && { [ -d /run/systemd/system ] || service cron start >/dev/null 2>&1 || true; }
as_prime "set -a; source /etc/prime/backup.env; $BASE/current/deploy/backup/backup.sh" >/dev/null || warn "первая копия не сделалась — смотрите $SHARED/storage/logs"
ls -1dt $BASE/releases/*/ | tail -n +4 | xargs -r rm -rf

# ---------------------------------------------------------------- 9. check
say "9/9 Проверка"
CODE=$(curl -s -o /dev/null -w '%{http_code}' --resolve "$ERP:80:127.0.0.1" --resolve "$ERP:443:127.0.0.1" -k "$SCHEME://$ERP/health" || true)
if [ "$CODE" = 200 ]; then
    echo "ERP отвечает: всё в порядке"
else
    warn "проверка /health ответила $CODE"
    curl -s --resolve "$ERP:80:127.0.0.1" --resolve "$ERP:443:127.0.0.1" -k "$SCHEME://$ERP/health" || true
    if [ -n "$PREV" ] && [ "$PREV" != "$NEW" ]; then
        warn "возвращаю прошлую версию"; ln -sfn "$PREV" $BASE/current.tmp && mv -Tf $BASE/current.tmp $BASE/current; svc php$PHPV-fpm
    fi
fi
echo
echo "==================================================================="
cat $INFO
echo "==================================================================="
echo "Это же записано в $INFO (только для root). Пароль не пересылайте в чаты."
echo "Обновить код до последней версии: снова bash demo-install.sh"
