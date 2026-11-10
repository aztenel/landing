# Установка Prime Mobile OS на сервер в Казахстане

Для интегратора. По архитектуре §6. Все ключи и пароли — только на сервере (`/var/www/prime/shared/.env`,
`/etc/prime/backup.env`, chmod 600) и в менеджере паролей. **В git и в чат — никогда.**

## 1. Сервер (договор — на Кайрата или его ИП)
- VPS в Казахстане (ps.kz или hoster.kz): **4 ядра, 8 ГБ, 100+ ГБ NVMe**, Ubuntu 24.04 LTS.
- Второе место для копий — **другой провайдер в Казахстане** (S3 или маленький VPS).
- DNS: `erp.<домен>`, `<домен>`, `www.<домен>`, `staging.<домен>` → IP сервера.

## 2. Программы
```bash
apt install nginx postgresql-16 redis-server supervisor php8.3-fpm php8.3-{cli,pgsql,redis,gd,intl,mbstring,xml,zip,bcmath,curl} composer nodejs npm restic jq certbot python3-certbot-nginx fail2ban
```
- SSH только по ключу, нестандартный порт, fail2ban. Наружу — только 80 и 443. PostgreSQL и Redis слушают 127.0.0.1.
- Часовой пояс сервера — UTC (приложение само считает время фирмы `Asia/Almaty`).

## 3. База
```bash
sudo -u postgres createuser prime --pwprompt
sudo -u postgres createdb prime --owner prime
sudo -u postgres createdb prime_staging --owner prime
sudo -u postgres psql prime -f deploy/postgres/site_role.sql      # роль сайта: только витрина (сменить пароль в файле перед запуском!)
```

## 4. Папки и первый релиз
```
/var/www/prime/releases/      релизы (5 последних)
/var/www/prime/shared/.env    из deploy/.env.production.example, заполнить на сервере
/var/www/prime/shared/storage файлы, фото, логи (общие для релизов)
/var/www/prime/current        ссылка на действующий релиз
```
- `shared/repo-url` — адрес репозитория, `shared/erp-host` — `erp.<домен>`.
- Первый раз: `deploy/scripts/release.sh main`, затем `php artisan storage:link`, `php artisan db:seed --class=RolesAndPermissionsSeeder` и `--class=CatalogSeeder` (**демо-фирму на рабочем сервере не сеять**).

## 5. nginx, PHP, фоновые задачи
- `deploy/nginx/prime.conf` → `/etc/nginx/sites-enabled/`, домен заменить; сертификаты: `certbot --nginx -d <домен> -d www.<домен> -d erp.<домен>`.
- `deploy/php-fpm/prime-erp.conf` и `prime-site.conf` → `/etc/php/8.3/fpm/pool.d/` (два пула: касса не тормозит, если сайт штурмуют).
- `deploy/supervisor/prime.conf` → `/etc/supervisor/conf.d/`: очередь и **`prime:outbox --loop`** (продажа уходит с сайта за секунды).
- `deploy/cron.txt` → crontab пользователя `prime`: планировщик каждую минуту, бэкап в 02:30, проверка восстановления по воскресеньям.

## 6. Резервные копии
- `/etc/prime/backup.env`: `PGHOST PGUSER PGPASSWORD PGDATABASE RESTIC_REPOSITORY RESTIC_PASSWORD` (+ ключи S3). Ключ шифрования restic —
  у Кайрата в запечатанном конверте и у интегратора в менеджере паролей.
- Первый раз: `restic init`.
- `deploy/backup/backup.sh` — каждую ночь: база + фото, хранение 30 дневных и 12 месячных, статус в `storage/app/backup-status.json`.
- `deploy/backup/restore-check.sh` — раз в неделю копия поднимается в `prime_staging`, сверяются журналы и балансы.
- Если копия не прошла: `/health` отвечает 503 сразу; владельцу уведомление, если не исправлено за 2 часа.
- **Рекомендуется** перейти на pgBackRest с архивом WAL (восстановление «на любую минуту»), когда сервер будет готов.

## 7. Выпуск новой версии
`deploy/scripts/release.sh <ветка или тег>` — без простоя: новая папка, миграции, переключение ссылки, проверка `/health`;
если проверка не прошла — автоматический возврат на прошлую версию. Выпускать вне рабочих часов магазина.

## 8. Мониторинг
- Внешняя проверка `https://erp.<домен>/health` каждую минуту (UptimeRobot или аналог): база, Redis, диск, очередь сайта, свежесть копии.
- Логи — на сервере (`storage/logs`). Внешние сервисы ошибок — только без персональных данных.

## 9. Перенос из sfon
`SFON_API_KEY` — в `.env` только на время переноса; после отключения sfon строку удалить.

## Что не проверено
Ничего из этого раздела ещё не запускалось на настоящем сервере: проверить на первом развёртывании (staging), особенно права
роли сайта `prime_site` и восстановление копии.
