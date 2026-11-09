# Prime Mobile OS — правила разработки (для всех, кто пишет код)

Источник требований: `../docs/architecture.md`, `../docs/tz/*.md` (ТЗ), `../docs/design/` (макеты).
Код и комментарии — на английском; тексты интерфейса — на русском (позже kk через `lang/`).

## Стек
- PHP 8.3, Laravel 12, PostgreSQL 16, Redis (очереди, кэш).
- Админка/ERP — Filament 3 (Tailwind 3.4). Касса и мобильные экраны — Livewire 3 + Alpine.
- Витрина — Blade + Tailwind 3.4, отдельная группа маршрутов `storefront`.
- Всё должно работать в Chrome 109 (Windows 7). Никаких CSS-фич новее (color-mix, oklch, :has в критичных местах, container queries) и JS новее ES2020 без транспиляции.
- Тесты — Pest (или PHPUnit), база `prime_mobile_test` (PostgreSQL, не SQLite: нужны частичные индексы, триггеры, FOR UPDATE).

## Структура
```
app/Domain/<Module>/        модели, сервисы, действия, перечисления модуля
  Models/  Actions/  Enums/  Services/  Exceptions/
app/Support/                общий код: Money, Tenancy, Imei, DocumentNumber
app/Filament/               ресурсы и страницы ERP (по модулям в подпапках)
app/Livewire/               касса, мобильные экраны
app/Http/Controllers/Storefront/   витрина
database/migrations/        имя: YYYY_MM_DD_HHMMSS_<module>_<what>.php
tests/Feature/<Module>/  tests/Unit/
```
Модули: Tenancy, Catalog, Inventory, Sales, Money, Debts, Customers, Loyalty, TradeIn, Reports, Printing, Storefront, Approvals, Audit, Migration(sfon).

## Обязательные правила домена (из ТЗ 02)
1. **Мультифирменность.** У каждой бизнес-таблицы `organization_id` (NOT NULL, FK). Модели используют трейт `BelongsToOrganization` (глобальный скоуп по текущей фирме + автозаполнение). Текущая фирма — `App\Support\Tenancy\CurrentOrganization`.
2. **Деньги — целые числа в минорных единицах** (тиын, цент), `bigint`. Класс `App\Support\Money\Money`. Никаких float для денег. Курс — `numeric(14,4)`.
3. **Документы** (`documents` + детальные таблицы): статусы `draft → posted → reversed`. Проведение — одна транзакция через `App\Domain\Ledger\Posting\Poster`. Номер `ПРД-2026-000456` выдаётся при проведении из `document_counters` (FOR UPDATE). `idempotency_key` уникален.
4. **Журналы** `inventory_movements`, `money_entries`, `debt_entries`, `bonus_entries` — только INSERT. UPDATE/DELETE запрещены триггером PostgreSQL. Исправление — сторно (обратные записи, `reversal_of_entry_id`).
5. **Копии балансов** (`stock_units.status/location/cost`, `accessory_balances`, `accessory_costs`, `account_balances`, `counterparty_balances`, `customer_bonus_balances`) меняются только внутри проведения тем же кодом.
6. **IMEI**: `unit_identifiers (organization_id, value)` уникален; активная продажа — частичный уникальный индекс `sale_lines(stock_unit_id) WHERE is_active`; бронь — `unit_reservations` с частичным уникальным индексом; при продаже `SELECT ... FOR UPDATE` на `stock_units`. Проверка IMEI по Луну.
7. **Статусы экземпляра** и разрешённые переходы — единый список `App\Domain\Inventory\Enums\UnitStatus` + `UnitTransitions` (ТЗ 02 §10). Переход не из таблицы — исключение.
8. **Курс** в каждом документе с $; действующий курс = последняя запись `exchange_rates` с `valid_from ≤ момента`.
9. **Права** — spatie/laravel-permission в режиме teams (team = organization). Себестоимость/поставщик не отдаются в браузер без права `stock.view_cost`.
10. Порядок блокировок (против взаимоблокировок): stock_units (по id) → accessory_costs → accessory_balances → account_balances → counterparty_balances → customer_bonus_balances → document_counters.

## Дизайн
Цвета, шрифт и раскладки — как в `../docs/design/src/erp.py` (палитра в HEAD) и скриншотах `../docs/design/screens/`. Шрифт Onest, IMEI — JetBrains Mono. Акцент по умолчанию синий `#2A4BD7` (настройка фирмы). Названия разделов как в sfon: Статистика, Продажа, Склад, Долги, Баланс, Расходы.

## Перед сдачей своей части
- `php artisan migrate:fresh --seed` проходит на PostgreSQL.
- `php artisan test` зелёный (минимум: тесты своего модуля).
- Не трогать чужие модули; общие файлы (routes, providers, PanelProvider, DatabaseSeeder) — только добавлением строк.
