# Ядро Prime Mobile OS — как устроено и как на нём строить модули

Версия ядра 1.1 · 09.11.2026 (после security‑ и integrity‑ревью: `migrate:fresh --seed` проходит, `php artisan test` — 122 теста зелёные, демо‑данные сходятся по ночным проверкам ТЗ 02 §13.2; итог — §13). Источник требований: `../docs/tz/02-uchet-i-zhurnaly.md` (учёт), `../docs/tz/03-roli-i-prava.md` (права), `../docs/architecture.md` §2. Правила разработки — `ENGINEERING.md`.

Этот документ — всё, что нужно разработчику модуля (касса, приход, деньги/долги, отчёты, витрина, Filament‑экраны), чтобы не читать код ядра.

---

## 0. Главное за минуту

1. **Любое изменение остатков, денег, долгов, бонусов — только проведением документа** через `Poster::post($document, $actor)`. Никаких `update` копий балансов и `insert` в журналы из модулей.
2. **Журналы** `inventory_movements`, `money_entries`, `debt_entries`, `bonus_entries` (+ `bonus_allocations`, `exchange_rates`, `imei_checks`, `price_changes`) — только INSERT. UPDATE/DELETE/TRUNCATE падают триггером БД. Исправление — `Reverser::reverse()` (сторно).
3. **Фирма** — всегда в контексте: `CurrentOrganization` (middleware ставит сам; в джобах/командах/сидерах — `CurrentOrganization::instance()->run($org, fn () => …)`). Запрос к модели фирмы без контекста — исключение.
4. **Деньги — `App\Support\Money\Money`** (целые тиыны/центы). Никаких float. На экране — `$money->format()` → «489 000 ₸».
5. **Права** — `AccessChecker::decide($user, 'sale.discount', Limit::DiscountPerCheck, $amount)` → разрешено / нужна заявка / нельзя. Внутри проведения — `$ctx->authorize(...)`.
6. **Себестоимость и поставщик не уходят в браузер** без права: `$unit->forViewer($user)`, `StockUnit::hideForViewer($units, $user)`, `StockUnit::hiddenFieldsFor($user)`.
7. **Свой тип документа** = свои детальные таблицы + класс `DocumentHandler` + одна строка регистрации (раздел 4).
8. **Id из запроса не доверяем**: экземпляры, счета, контрагентов и клиентов бонусов кладите в `LockPlan` — `LockManager` сам проверит, что они этой фирмы (§11). Свои детальные таблицы — с составным FK `(organization_id, x_id)`.
9. **Тест модуля** заканчивается `expect(Reconciliation::problems())->toBe([])` после сценария и после его сторно (§12).

---

## 1. Карта кода

```
app/Support/
  Money/          Money, Currency, Rate (курс ×10 000), Rounding (HalfUp | Down | Up)
  Imei/Imei       нормализация, 15 цифр + Луна, формат «35 693812 4471 206», serial
  Iin/Iin         ИИН/БИН (12 цифр + контрольная), маска
  Phone/Phone     +77010000001 (E.164), формат, маска
  DocumentNumber/ префиксы ТЗ 02 §2.3, format/parse «ПРД-2026-000456»
  Idempotency/    ключи повтора (ULID), derived('reversal', 42)
  Crypto/BlindIndex  HMAC для поиска по ИИН
  Tenancy/        CurrentOrganization, BelongsToOrganization, OrganizationScope, TracksCreator, исключения
  Exceptions/UserFacingException   базовое «понятное пользователю» исключение (reason, context)

app/Domain/
  Tenancy/        Organization, Store, Membership, RoleLimit, OrganizationSettings,
                  Access/{PermissionCatalog, RoleTemplates, Limit, AccessChecker, AccessDecision,
                          RoleProvisioner, HasSensitiveFields, Exceptions/{AccessDenied, ApprovalRequired}}
                  Services/OrganizationProvisioner
  Catalog/        ProductType, Family, ProductModel(table models), Memory, Color, Ram, Variant,
                  AccessoryCategory, Accessory (глобальные), VariantPrice, AccessoryPrice (по фирме)
  Counterparties/ Customer, Supplier, Enums/CounterpartyType
  Inventory/      StockUnit, UnitIdentifier, UnitReservation, ImeiCheck, PriceChange, AccessoryBalance,
                  AccessoryCost, Purchase*, Location, UnitTransitions, Enums/{UnitStatus, UnitAction, …},
                  Services/{UnitIntake, ReservationService, UnitStatusService, ImeiCheckService, PriceService},
                  Posting/PurchaseHandler, Drafts/PurchaseDraft
  Ledger/         Document, DocumentCounter, InventoryMovement, MoneyEntry, DebtEntry, BonusEntry,
                  BonusAllocation, AccountBalance, CounterpartyBalance, CustomerBonusBalance, Shift,
                  AccountingPeriod, OpeningBalanceLine
                  Enums/{DocumentType, DocumentStatus, MovementKind, DebtKind, BonusKind, ReversalReason}
                  Posting/{Poster, Reverser, TransactionRetry, DocumentHandler, AbstractDocumentHandler,
                           HandlerRegistry, NotImplementedHandler, LockPlan, LockSet, LockManager, PostingContext,
                           ApprovalGate, NoApprovals, Handlers/OpeningBalanceHandler,
                           Ledgers/{InventoryLedger, MoneyLedger, DebtLedger, BonusLedger}}
                  Services/{DocumentNumberAllocator, AccountingPeriods, ChainVerifier}
                  Drafts/{DraftBuilder, OpeningBalanceDraft}, Events/{DocumentPosted, DocumentReversed}
                  LedgerServiceProvider
  Sales/          Sale, SaleLine, SalePayment, CustomerReturn*, Posting/{SaleHandler, CustomerReturnHandler},
                  Services/ReturnCalculator, Drafts/{SaleDraft, CustomerReturnDraft}
  Money/          MoneyAccount, PaymentMethod, MoneyArticle, ExchangeRate, Expense*, FxConversion,
                  Enums/{AccountType, PaymentMethodCode, ArticleCode}, Services/ExchangeRates,
                  Posting/{ExpenseHandler, FxConvertHandler}, Drafts/{ExpenseDraft, FxConvertDraft}
  Debts/          DebtPayment, Posting/DebtPaymentHandler, Drafts/DebtPaymentDraft

app/Http/Middleware/SetCurrentOrganization   выбор фирмы (alias `organization`, подключён в Filament и как
                                             persistent middleware Livewire — TenancyServiceProvider)
app/Http/Controllers/Tenancy/OrganizationSwitchController   смена фирмы (только активное членство в активной фирме)
app/Support/Tenancy/TenancyServiceProvider   Livewire persistent middleware, сброс фирмы перед джобой воркера,
                                             activity_log.organization_id
app/Filament/Pages/Auth/PhoneLogin           вход в ERP по телефону + паролю
config/prime.php                             blind_index_key (BLIND_INDEX_KEY, в production обязателен), chain_lock_key
tests/Support/World.php                      фикстура «готовая фирма» для тестов модулей
tests/Support/Reconciliation.php             ночные проверки ТЗ 02 §13.2 одним вызовом (§12)
```

---

## 2. Таблицы

Деньги — `bigint` в минорных единицах (тиын/цент), курс — `numeric(14,4)`, время — `timestamptz` (UTC). У каждой таблицы фирмы — `organization_id NOT NULL` (+ `created_by`, где это бизнес‑данные).

| Группа | Таблица | Назначение / ключевые ограничения |
|---|---|---|
| Tenancy | `organizations` | фирма: name, legal_name, bin_iin, requisites JSON, **settings JSON** (см. §6), timezone, **closed_until** |
| | `stores` | магазины фирмы |
| | `users` | глобальные: **phone UNIQUE** (логин), name, password, is_superadmin, locale |
| | `memberships` | user × organization: status (invited/active/blocked/dismissed), store_ids JSON, **limits JSON** (личные лимиты) |
| | `roles`, `permissions`, `model_has_roles`, … | spatie, **teams = organizations** (`organization_id`); у `roles` добавлены `title`, `is_owner` |
| | `role_limits` | лимиты роли: code `L1…L18` или имя права со значением 0 («только заявкой») |
| Catalog (глобально) | `product_types`, `families`, `models`, `memories`, `colors`, `rams` | справочник (Д1) |
| | `variants` | model + memory + color + ram, UNIQUE NULLS NOT DISTINCT |
| | `accessory_categories`, `accessories` | sku UNIQUE, barcode UNIQUE, name_ru/kk |
| Цены фирмы | `variant_prices`, `accessory_prices` | (organization_id, variant/accessory) UNIQUE |
| Counterparties | `customers` | (organization_id, phone) UNIQUE; iin зашифрован + iin_hash (blind index) + iin_last4; согласие на ПДн |
| | `suppliers` | (organization_id, name) UNIQUE; is_repair_master |
| Money refs | `money_accounts` | type cash/bank/installment_bank/safe/technical, **одна валюта**, allow_negative, code |
| | `payment_methods` | code → счёт + % комиссии (по фирме или магазину) |
| | `money_articles` | статьи (системные + расходы), affects_profit |
| | `exchange_rates` | **append-only**, действующий = последний `valid_from ≤ момент` |
| Inventory | `stock_units` | один телефон. Копии: **status** (NULL = «нет в учёте»), location_type/id, **cost_kzt**, cost_currency/amount_orig/rate, last_movement_id. **public_code UNIQUE глобально** (`PM-10001`, sequence) |
| | `unit_identifiers` | IMEI1/IMEI2/serial, **(organization_id, value) UNIQUE** — слой 1 защиты IMEI |
| | `unit_reservations` | бронь корзины, **одна активная** (partial unique WHERE released_at IS NULL) — слой 2 |
| | `imei_checks` | отметки госбазы, append-only |
| | `price_changes` | история цен, append-only |
| | `accessory_balances` | копия: qty по месту (CHECK qty ≥ 0) |
| | `accessory_costs` | копия: total_qty, **avg_cost_kzt** по фирме |
| Ledger | `documents` | шапка: type, number (+number_year), status draft/posted/reversed, store, occurred_at, posted_*, reversal links, reason, exchange_rate(+manual), **idempotency_key UNIQUE в фирме**, approval_request_id, exchange_group_id, fiscal_receipt_no, comment, meta JSON |
| | `document_counters` | (organization_id, series=префикс, year) UNIQUE, last_number |
| | `inventory_movements` | журнал товара: ровно один из stock_unit_id/accessory_id; qty ±1/0 у экземпляра; unit/total cost; status_before/after; location_before; avg_cost_before/after (аксессуар — средняя до/после; экземпляр — его себестоимость до/после, если движение её задаёт); kind |
| | `money_entries` | журнал денег: account_id + currency (составной FK на счёт), amount ≠ 0, exchange_rate, amount_kzt (знак = знак amount), article_id, counterparty, sale_payment_id/expense_payment_id |
| | `debt_entries` | журнал долгов: counterparty × currency, amount (+ нам должны больше), amount_kzt, kind, due_date (обязателен у *_credit), fx_diff_kzt |
| | `bonus_entries`, `bonus_allocations` | журнал бонусов (целые бонусы), is_source, available_from/expires_at; разнесение FIFO |
| | `account_balances` | копия: balance, balance_kzt_hist, CHECK `allow_negative OR balance ≥ 0` |
| | `counterparty_balances` | копия: balance, balance_kzt, avg_rate, nearest_due_date |
| | `customer_bonus_balances` | копия: available, pending (CHECK ≥ 0), next_expire_at |
| | `shifts` | смены, **одна открытая на счёт кассы** (partial unique) |
| | `accounting_periods`, `period_snapshots` | закрытие месяца |
| Детали ядра | `sales`, `sale_lines`, `sale_payments` | продажа; **partial unique `sale_lines(stock_unit_id) WHERE is_active`** — слой 4 |
| | `customer_returns`, `customer_return_lines`, `customer_return_payments` | возврат покупателя |
| | `purchases`, `purchase_lines`, `purchase_payments` | приход |
| | `expenses`, `expense_payments` | расход |
| | `debt_payments` | оплата долга in/out |
| | `fx_conversions` | конвертация |
| | `opening_balance_lines` | входящие остатки |

Все журнальные строки имеют `document_id, store_id, user_id, occurred_at, recorded_at, reversal_of_entry_id (UNIQUE), chain_seq, hash`.

### Триггеры и проверки в БД (миграция `…_ledger_create_guard_triggers`)

| Что | Как |
|---|---|
| Журналы только добавляются | `pm_append_only()` на UPDATE/DELETE (row) и TRUNCATE (statement) |
| Хэш‑цепочка | `pm_journal_chain()` BEFORE INSERT: advisory‑lock фирмы, `chain_seq = prev+1`, `hash = sha256(prev_hash ‖ jsonb_strip_nulls(to_jsonb(row) − 'hash'))` — NULL‑поля не входят, поэтому новая NULL‑колонка журнала (миграция модуля) не «рвёт» цепочку старых строк; колонка с NOT NULL DEFAULT — рвёт, так не делать. Проверка — `ChainVerifier::firstBrokenLink($journal)` |
| Проведённый документ неизменен | `pm_documents_guard()`: менять можно только `status → reversed`, `reversed_by_document_id`, `comment`, `fiscal_receipt_no`; удалить нельзя |
| Закрытый период | `pm_documents_period_guard()`: документ не станет `posted`, если дата ≤ `organizations.closed_until` (в часовом поясе фирмы) |
| Строки проведённого документа | `pm_posted_detail_guard(<разрешённые колонки>)` на всех детальных таблицах ядра; для `sale_lines` разрешены `is_active`, `returned_qty`. Перенести строку черновика в проведённый документ (`UPDATE … SET document_id`) тоже нельзя |
| Связь сторно | CHECK `documents_reversed_link_check`: `status = reversed` ⇔ `reversed_by_document_id` заполнен (миграция `…_ledger_integrity_guards`) |
| CHECK’и §11.7 | money_entries amount ≠ 0 и знак ₸ = знак суммы; ровно один субъект в inventory_movements; qty ∈ {−1,0,1} у экземпляра; balance ≥ 0; qty ≥ 0; bonus available ≥ 0; sale_payments change ≥ 0 |

> Модуль, добавляющий свои детальные таблицы, вешает на них тот же триггер: `CREATE TRIGGER my_lines_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON my_lines FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard('col_a', 'col_b');` (в скобках — колонки, которые можно менять после проведения; таблица обязана иметь `document_id`).

---

## 3. Проведение и сторно

### 3.1 Poster

```php
use App\Domain\Ledger\Posting\Poster;
$posted = app(Poster::class)->post(Document $draft, User $actor): Document;
```

Одна транзакция `TransactionRetry::run()` (ТЗ 02 §12.3: повтор до 3 раз с паузой 100–300 мс при deadlock `40P01`, таймауте блокировки `55P03` — `lock_timeout = 5s` — и `40001`; внутри внешней транзакции не повторяет). Первой берётся строка `organizations` `FOR KEY SHARE`: закрытие периода (`FOR UPDATE` этой строки) ждёт проводки в полёте, а проводка, начатая во время закрытия, видит новый `closed_until` (ТЗ 02 §12.1). То же в `Reverser`.

0. До транзакции: документ чужой фирмы → `CrossOrganizationViolation`; тип `reversal` → «через `Reverser`».
1. `SELECT … FOR UPDATE` документа. Уже `posted` → вернуть его (**идемпотентность**). `reversed` → ошибка.
1a. **Кто проводит** (независимо от handler’а): только активный член фирмы (`AccessDenied` «Доступ закрыт» — интегратор, уволенный, заблокированный, чужой); магазин документа — этой фирмы и из `memberships.store_ids` сотрудника.
2. Дата: не в будущем (допуск `documents.backdate_grace_minutes`); **открытый период**; задним числом — право `doc.backdate`, ≤ `backdate_max_days` (3), обязательна `reason_text`.
3. Курс документа = действующий курс на момент проведения (ручной — только владелец, `exchange_rate_manual`). Нет курса, а `handler->requiresExchangeRate()` → ошибка «Не задан курс $».
4. `handler->validate()` → `handler->lockPlan()` → `LockManager::acquire()` (порядок блокировок ниже) → `handler->post()`.
5. Номер из `document_counters` (без дыр), `status = posted`, `posted_at`, `posted_by`, запись в activity log (`log_name = documents`). `approval_request_id` из черновика остаётся, только если `ApprovalGate` что‑то разрешил в этом проведении (`$ctx->approvalUsed()`), иначе обнуляется.
6. После коммита — событие `DocumentPosted($documentId, $organizationId, $type)`.

Любое исключение откатывает всё: документ остаётся черновиком без номера.

**Порядок блокировок** (ENGINEERING.md п.10, ТЗ 02 §12.3), внутри группы по id:
`organizations` (`FOR KEY SHARE`) → `documents (сам документ)` → `stock_units` → `accessory_costs` → `accessory_balances` → `account_balances` → `counterparty_balances` → `customer_bonus_balances` → `document_counters` → advisory‑lock хэш‑цепочки фирмы.
Недостающие строки копий создаются `INSERT … ON CONFLICT DO NOTHING` в фиксированном порядке ключей и сразу блокируются. Перед созданием строк `counterparty_balances` / `customer_bonus_balances` проверяется, что клиент/поставщик — этой фирмы.

```php
// своя транзакция модуля с той же политикой повтора (вне Poster/Reverser — например, ночная задача)
App\Domain\Ledger\Posting\TransactionRetry::run(fn () => …, attempts: 3);   // 40P01 / 40001 / 55P03, пауза 100–300 мс; во внешней транзакции не повторяет
```

### 3.2 Reverser

```php
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Ledger\Enums\ReversalReason; // input_error, wrong_item, wrong_amount, duplicate, system_error, other
$reversal = app(Reverser::class)->reverse(Document $original, ReversalReason|string $reason, ?string $text, User $actor): Document; // СТН-…
```

- Только `posted`; сторно сторно запрещено; одно сторно на документ; «другое» — текст ≥ 5 символов.
- Право `doc.reverse` с лимитом L18 (дней с проведения, календарные дни в часовом поясе фирмы); проверка членства и магазина документа — внутри `authorize`.
- `handler->assertReversible()` (например, у продажи есть проведённый возврат → «Сначала сторнируйте: Возврат покупателя ВЗК-…»).
- **Экземпляр менялся после документа** (его последнее «живое» движение принадлежит другому документу) → `ReversalBlocked` «Сначала сторнируйте: Продажа ПРД-…». Пары «документ + его сторно» не считаются.
- Обратные записи **ко всем** строкам всех журналов документа (через те же ledgers, с `reversal_of_entry_id`), статусы/места экземпляров — ровно `status_before/location_before`. Экземпляр, чей приход сторнирован, получает `status = NULL` («нет в учёте»), его IMEI освобождаются.
- Дата записей = дата исходного документа, если его период открыт; иначе — сейчас.
- Себестоимость: сторно движения, которое задало себестоимость экземпляра (приход, вход. остатки, trade‑in известного телефона), возвращает **прежнюю** себестоимость (`avg_cost_before` строки журнала) и валюту закупа предыдущего живого прихода; сторно создания → `cost_kzt = 0`, `status = NULL`.
- `handler->afterReverse()` — побочные эффекты в детальных таблицах (например, `sale_lines.is_active = false`).
- После коммита — `DocumentReversed`.

### 3.3 Ledgers — единственные писатели журналов и копий

Вызываются **только из handler’ов** внутри `post()`/`afterReverse()`; все строки должны быть в `LockPlan`, иначе `LogicException` (защита порядка блокировок).

```php
// App\Domain\Ledger\Posting\Ledgers\InventoryLedger
unitIn(PostingContext $ctx, StockUnit $unit, Location $to, MovementKind $kind, UnitAction $action, UnitStatus $statusAfter, ?int $unitCostKzt = null, array $costOrig = []): InventoryMovement
unitOut(PostingContext $ctx, StockUnit $unit, MovementKind $kind, UnitAction $action, UnitStatus $statusAfter): InventoryMovement
unitMove(PostingContext $ctx, StockUnit $unit, Location $to, MovementKind $kind, UnitAction $action, UnitStatus $statusAfter): array // [out, in]
unitCost(PostingContext $ctx, StockUnit $unit, int $deltaKzt, MovementKind $kind): InventoryMovement   // qty 0 (затраты на подготовку, КСБ)
accessoryIn(PostingContext $ctx, int $accessoryId, Location $place, int $qty, int $unitCostKzt, MovementKind $kind, array $costOrig = []): InventoryMovement // пересчёт средней
accessoryOut(PostingContext $ctx, int $accessoryId, Location $place, int $qty, MovementKind $kind): InventoryMovement // по средней, не ниже 0
reverse(PostingContext $ctx, InventoryMovement $original): InventoryMovement

// App\Domain\Ledger\Posting\Ledgers\MoneyLedger
record(PostingContext $ctx, int $accountId, int $amount /* ± минорные единицы валюты счёта */, ArticleCode|string|int $article, array $attributes = [], ?int $amountKzt = null, ?int $reversalOfEntryId = null): MoneyEntry
        // счёт магазина (money_accounts.store_id) — только для сотрудника этого магазина; «не хватает денег» по безналу без money.view_bank_balances — без сумм
reverse(PostingContext $ctx, MoneyEntry $original): MoneyEntry
account(int $accountId): MoneyAccount   // кэш синглтона с ключом фирмы
// $attributes: counterparty_type, counterparty_id, sale_payment_id, expense_payment_id, comment, exchange_rate

// App\Domain\Ledger\Posting\Ledgers\DebtLedger   (+ контрагент должен нам больше / − мы должны больше)
record(PostingContext $ctx, CounterpartyType $type, int $id, Currency $currency, int $amount, DebtKind $kind, ?CarbonInterface $dueDate = null, ?Rate $rate = null, ?int $settledKzt = null, ?string $comment = null): DebtEntry
reverse(PostingContext $ctx, DebtEntry $original): DebtEntry
// USD-долг: рост — по курсу операции, погашение — по avg_rate; $settledKzt = ₸ денег, погасивших долг (со знаком суммы) → fx_diff_kzt

// App\Domain\Ledger\Posting\Ledgers\BonusLedger   (целые бонусы)
accrue(PostingContext $ctx, int $customerId, int $bonuses, CarbonInterface $availableFrom, ?CarbonInterface $expiresAt, BonusKind $kind = Accrual, ?int $saleLineId = null, ?string $comment = null): BonusEntry
spend(PostingContext $ctx, int $customerId, int $bonuses, BonusKind $kind = Spend, ?int $saleLineId = null): BonusEntry          // FIFO по сроку, только доступные
consume(PostingContext $ctx, int $customerId, int $bonuses, BonusKind $kind, array $preferredSourceIds = [], bool $includePending = true, bool $allowShortfall = false, ?int $saleLineId = null, ?int $reversalOf = null): array // ['entry', 'shortfall']
restore(PostingContext $ctx, BonusEntry $consumer, int $bonuses, BonusKind $kind = ReturnOfSpent, ?int $reversalOf = null): BonusEntry // «те же» бонусы с их сроком
reverse(PostingContext $ctx, BonusEntry $original): BonusEntry
refresh(PostingContext|CarbonInterface $ctxOrNow, int $customerId, ?int $lastEntryId = null): CustomerBonusBalance // для ночной активации/сгорания (модуль Loyalty)
sources(int $customerId): Collection // остатки начислений (текущей фирмы)
// consume(): «предпочтительные» источники (отменяемое начисление) берутся даже pending/просроченные — живые бонусы клиента не трогаются
```

### 3.4 PostingContext (что доступно handler’у)

```php
$ctx->document, $ctx->actor, $ctx->organization, $ctx->now (CarbonImmutable), $ctx->isReversal, $ctx->locks (LockSet)
$ctx->settings(): OrganizationSettings
$ctx->occurredAt(), $ctx->businessDate(), $ctx->storeId(), $ctx->requireStoreId()
$ctx->rate(): ?Rate, $ctx->requireRate(): Rate
$ctx->isOwner(): bool, $ctx->can(string $permission): bool (без лимитов — например, что показать в ошибке)
$ctx->actorMayUseStore(int $storeId): bool
$ctx->approvalUsed(): bool   // ApprovalGate разрешил что‑то в этом проведении
$ctx->entryDefaults(): array // общие колонки журнальной строки (ledgers)
$ctx->authorize(string $permission, ?Limit $limit = null, ?int $value = null, ?string $approvalType = null, ?string $message = null): void
        // нет права → AccessDenied; сверх лимита / «З» → ApprovalRequired (если ApprovalGate не подтвердил)
$ctx->requireApprovalUnlessOwner(string $permission, string $approvalType, string $message): void
$ctx->locks->unit($id), ->accessoryCost($accId), ->accessoryBalance(Location, $accId), ->account($id),
          ->counterparty($type, $id, $currency), ->bonus($customerId), ->putUnit($newUnit)
```

---

## 4. Как модулю добавить свой тип документа

Пример — «Перемещение: отправка» (`transfer_out`, префикс ПРМ). Тип уже есть в `DocumentType` и в CHECK таблицы `documents`; сейчас он отвечает `NotImplementedHandler` («Проведение документа «…» ещё не реализовано»).

1. **Миграция детальных таблиц** `database/migrations/YYYY_MM_DD_HHMMSS_inventory_create_transfers.php`: `transfers (document_id UNIQUE, to_store_id, …)`, `transfer_lines (document_id, stock_unit_id | accessory_id, qty)`; `organization_id NOT NULL`; повесьте `pm_posted_detail_guard(...)`.
2. **Модели** с `BelongsToOrganization` (и `HasSensitiveFields`, если есть себестоимость).
3. **Handler**:

```php
final class TransferOutHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly InventoryLedger $inventory) {}

    public function type(): DocumentType { return DocumentType::TransferOut; }

    public function validate(Document $doc, PostingContext $ctx): void
    {
        // структура черновика, без блокировок; бросайте PostingException::because('code', 'Понятный текст')
    }

    public function lockPlan(Document $doc, PostingContext $ctx): LockPlan
    {
        $plan = new LockPlan;
        foreach ($this->lines($doc) as $line) {
            $line->stock_unit_id
                ? $plan->unit($line->stock_unit_id)
                : $plan->accessory($line->accessory_id, Location::store($ctx->requireStoreId()))
                       ->accessory($line->accessory_id, Location::transit($doc->id));
        }
        return $plan;
    }

    public function post(Document $doc, PostingContext $ctx): void
    {
        $ctx->authorize('transfer.send');
        foreach ($this->lines($doc) as $line) {
            $unit = $ctx->locks->unit($line->stock_unit_id);          // перечитано под блокировкой
            $this->inventory->unitMove($ctx, $unit, Location::transit($doc->id),
                MovementKind::Transfer, UnitAction::TransferSend, UnitStatus::InTransit);
        }
    }

    // при необходимости: assertReversible(), afterReverse(), reversalLockPlan(), requiresExchangeRate()
}
```

4. **Регистрация** в сервис‑провайдере модуля (`bootstrap/providers.php` — добавить строку):

```php
public function register(): void
{
    $this->app->afterResolving(HandlerRegistry::class, fn (HandlerRegistry $r) =>
        $r->register(DocumentType::TransferOut, TransferOutHandler::class));
}
```

5. **Черновик**: свой builder на базе `App\Domain\Ledger\Drafts\DraftBuilder` (`type()` + `writeDetails()`), либо `Document` + строки вручную (`status = draft`, `idempotency_key` обязателен).
6. **Тесты** в `tests/Feature/<Module>/` с фикстурой `world()` (см. §8).

Правила для handler’а:
- Писать в журналы и копии **только** через ledgers. Сами детальные строки можно обновлять (`$line->forceFill([...])->save()`) пока документ ещё draft — это происходит внутри `post()`.
- Все проверки «хватает ли / свободен ли» — **в `post()` после блокировок**, повторным чтением из `$ctx->locks`.
- Статус экземпляра меняется только через ledger (с `UnitAction` из таблицы переходов). Переходы без журнала («Готов к продаже», бронь заказа) — `UnitStatusService::change()`; корзина — `ReservationService`.
- Ничего внешнего (push, печать, витрина) внутри транзакции — слушайте `DocumentPosted` / `DocumentReversed`.
- Нужен USD — верните `true` из `requiresExchangeRate()` и берите `$ctx->requireRate()`.

---

## 5. Реализованные типы документов (handlers ядра)

| Тип | Builder | Что делает |
|---|---|---|
| `opening_balance` ВХО | `OpeningBalanceDraft::make($store)->unit()->accessory()->money()->customerDebt()->supplierDebt()->debt()->bonus()` | день X: телефоны (`in_stock`/`preparing`, себестоимость ₸ или $ по курсу), аксессуары, деньги по счетам, долги (знак §6.1), бонусы. Право `doc.opening_balance` (только владелец) |
| `purchase` ПРХ | `PurchaseDraft::make($store, $supplier, Currency::USD)->unit($variant, $imei, Money::usd(950), [...])->accessory()->payFrom($account, $money)->onCredit($due)` | IMEI (Луна, дубли в документе и в фирме), поштучная себестоимость = цена × курс, средняя аксессуаров, оплата со счетов ₸/$ и/или долг поставщику в валюте прихода. Новые → `in_stock`, б/у → `preparing`. Без цены (продавец) — `cost_unknown`, без оплат/долга |
| `sale` ПРД | `SaleDraft::make($store, $seller)->customer()->unit($unit, $price)->accessory($acc, $qty, $price)->gift()->discount()->cash($amount, $tendered)->pay('kaspi_qr', …)->debt($amount, $due)->bonus($amount)->fromOrder($orderDocId)` | §6.2 №10–17: сплит, сдача, комиссия банка, рассрочка, долг (срок), бонусы (FIFO, ≤ 20 %), зачёт trade‑in (тех. счёт, только ₸), предоплата (не больше аванса клиента — баланс < 0 — под блокировкой); скидка чека — наибольшие остатки; себестоимость строки; бонусы к начислению; лимиты L1–L6, ниже себестоимости, госбаза IMEI (≤ 7 дней), 4 слоя защиты IMEI |
| `customer_return` ВЗК | `CustomerReturnDraft::make($saleDoc)->line($saleLine, $qty, UnitStatus::OnCheck)->refund('cash', $money)->refundToDebt($money)` | полный/частичный; товар по себестоимости продажи; телефон → in_stock/preparing/on_check/defective; отмена начисленных бонусов, возврат потраченных; нехватка бонусов удерживается из денег; лимиты L7–L9. Предпросмотр суммы: `ReturnCalculator::quote($draft)` (`refund`, `to_debt_min`). Правила: сумма строки ≤ ещё не возвращённой части её суммы; возврат деньгами — только на счёт ₸; **неоплаченная часть продажи в долг** (`sale_lines.debt_share` возвращаемых штук, но не больше текущего долга клиента) возвращается только уменьшением долга (`refundToDebt`, ТЗ 02 №20 «Л»); бонусы и долговая часть частичных возвратов считаются нарастающим итогом (Σ по всем возвратам строки = потрачено / начислено / в долг ровно); количество перепроверяется под блокировками, продажа блокируется `FOR SHARE` (её сторно ждёт возврат) |
| `expense` РСХ | `ExpenseDraft::make(ArticleCode::Courier, $store)->pay($account, $money)->onCredit($supplier, $money, $due)->description()` | с одного/нескольких счетов и/или в долг; L10/L11 для кассы |
| `debt_payment_in` ОДК | `DebtPaymentDraft::fromCustomer($customer, $account, $money, $store, '1.00')` | + счёт, − комиссия, − долг клиента |
| `debt_payment_out` ОДП | `DebtPaymentDraft::toSupplier($supplier, $account, $money, Currency::USD, Money::usd(4000))` | − счёт, + долг поставщику; $‑долг по среднему курсу, курсовая разница в `debt_entries.fx_diff_kzt` |
| `fx_convert` КНВ | `FxConvertDraft::make($fromAcc, Money::usd(1000), $toAcc, Money::kzt(512000))` | две записи по курсу документа; разница = Σ amount_kzt → `fx_conversions.fx_diff_kzt` |

Все builder’ы: `->occurredAt()`, `->idempotencyKey()`, `->comment()`, `->reason()`, `->author()`, `->manualRate()`, `->approvalRequest($id)`, `->save(): Document` (черновик; повтор с тем же ключом вернёт существующий), `->post(User $actor): Document`.

**NotImplemented (делают модули):** `tradein` ТРД, `prep_cost` ЗПД, `unit_edit` ИЗХ, `cost_adjustment` КСБ, `transfer_out`/`transfer_in` ПРМ, `write_off` СПС, `supplier_return` ВЗП, `revision` РЕВ, `debt_adjustment` КДЛ, `cash_move` ДДС, `shift_close` СМН, `bonus_manual` БНС, `order` ЗАК. Ledgers для них уже есть (`unitMove`, `unitCost`, `UnitAction::TradeInKnown`, `WriteOff`, `SupplierReturn`, …).

---

## 6. Справочники и сервисы

### Tenancy
```php
CurrentOrganization::instance()->set(Organization|int), ->get(), ->id(), ->require(), ->requireId(), ->clear(), ->has()
CurrentOrganization::instance()->run(Organization|int $org, callable $fn)   // джобы, команды, сидеры
Model::withoutOrganizationScope()                                          // только системный код (интегратор, ночная сверка)
app(OrganizationProvisioner::class)->create(string $name, string $storeName, ?User $owner, array $settings = [], array $attributes = []): Organization
                                  ->addMember(Organization, User, string $role, array $storeIds = [], array $limits = [], ?string $position = null): Membership
                                  ->installStarterAccounts(Store, array $commissions = []): array<code, MoneyAccount>
$organization->settings(): OrganizationSettings  // accent(), bonus*(), requireImeiCheck(), imeiCheckMaxAgeDays(), reservationMinutes(), backdateMaxDays()…
```
`organizations.settings` (по умолчанию, `OrganizationSettings::DEFAULTS`): `accent` (`blue`|`graphite`), `bonus.{enabled, phone_percent 1.00, accessory_percent 3.00, activation_days 14, lifetime_days 365, max_pay_percent 20.00}`, `sales.{require_imei_check true, imei_check_max_age_days 7, reservation_minutes 30, order_hold_days 3}`, `documents.{backdate_max_days 3, backdate_grace_minutes 10}`, `cash.discrepancy_threshold`, `prices.fx_round_step`, `rates.confirm_change_percent 5.00`, `approvals.confirm_threshold`.

Стартовые счета (`MoneyAccount::byCode()`): `cash_kzt` «Касса ₸ — {магазин}», `cash_usd`, `safe_kzt`, `safe_usd`, `kaspi_gold`, `kaspi_qr`, `kaspi_pay` (рассрочки), `halyk_terminal`, `tradein_offset` (тех., allow_negative, скрыт). Способы оплаты → счета: cash→cash_kzt, card→halyk_terminal, kaspi_qr→kaspi_qr, kaspi_transfer→kaspi_gold, kaspi_red/kaspi_installment→kaspi_pay, halyk_installment→halyk_terminal, tradein→tradein_offset; debt/bonus/prepayment без счёта.

### Права (ТЗ 03)
- Коды — **как в ТЗ 03** (`sale.create`, `sale.discount`, `stock.receive`, `doc.reverse`, …) — полный список в `PermissionCatalog::MATRIX` (группа, название, ячейки 5 ролей). Добавлены переключатели «Видимость» §4.1/§16.1: `stock.view_cost`, `stock.view_supplier`, `profit.view`, `customer.view_iin`, `money.view_bank_balances`, и `doc.opening_balance`.
- Роли фирмы: `owner`, `admin`, `senior_seller`, `seller`, `accountant` (`RoleTemplates`). Owner — все права без лимитов (`roles.is_owner`).
- Лимиты `Limit::L1…L18` (`Limit::DiscountPerCheck`, …), значения в `role_limits`, личные — `memberships.limits['L1']`. Единицы: деньги — тиыны, % — ×100, дни. NULL = ∞, 0 = всегда заявка.
- `app(AccessChecker::class)->decide(User, string $permission, ?Limit, ?int $value, ?int $storeId): AccessDecision` (`isAllowed()`, `needsApproval()`, `isDenied()`, `limit`, `limitValue`, `actualValue`, `message`); `->can(...)`, `->isOwner(User)`, `->membership(User): ?Membership`, `->role(User)`, `->limitValue(Membership, Role, Limit)`. Учитывает статус членства и магазины сотрудника — **вне HTTP используйте его, а не `$user->can()`**.
- `$user->hasActiveMembershipIn($orgId)`, `$user->activeOrganizations()`, `$membership->isActive()`, `->allowsStore($storeId)` (пустой `store_ids` = все магазины). Выключенная фирма (`organizations.is_active = false`) — как отсутствие членства.
- `ApprovalRequired` (`approvalType`, `decision`, `context`) — модуль Approvals ловит его и создаёт заявку; затем ставит `documents.approval_request_id` и **биндит свою реализацию `ApprovalGate`** (`isApproved(Document, string $type, AccessDecision): bool`). По умолчанию `NoApprovals` — заявок нет.
- spatie: `$user->can('stock.view_cost')` работает для текущей фирмы (team id ставит `CurrentOrganization`; роль другой фирмы ничего не даёт), но **не смотрит статус членства**.
- Видимость полей: модели с `HasSensitiveFields` (`StockUnit`, `SaleLine`, `Purchase`, `PurchaseLine`, `PurchasePayment`, `CustomerReturnLine`, `OpeningBalanceLine`, `InventoryMovement`, `AccessoryCost`) — `->forViewer($user)`, `::hideForViewer($models, $user)`, `::hiddenFieldsFor($user)`. `Customer.iin` скрыт всегда; маска — `$customer->maskedIin()`; поиск — `Customer::whereIin($iin)`.

### Склад
```php
app(ReservationService::class)->reserve(StockUnit|int, User $seller, ?string $cartKey = null): UnitReservation  // «Уже в корзине у …»
                              ->release(UnitReservation, ?User $by = null, string $reason = 'removed')
                              ->releaseExpired(): int   // планировщик раз в минуту (в контексте фирмы)
app(UnitStatusService::class)->markReady(StockUnit, User), ->sendToPreparation(), ->decideCheck(StockUnit, UnitStatus, User), ->toRepair(),
                             ->change(StockUnit, UnitStatus $to, UnitAction, User, string $permission, array $extra = [])
                             // только UnitStatusService::WITHOUT_JOURNAL (готов, на подготовку, решение по проверке, ремонт,
                             // заказ / отмена заказа); остальное — документом через InventoryLedger, иначе LogicException
app(ImeiCheckService::class)->record(StockUnit, ImeiCheckResult, User, string $source = 'manual', ?string $comment = null, ?CarbonInterface $at = null): ImeiCheck
                             // телефон другой фирмы → CrossOrganizationViolation
app(PriceService::class)->setUnitPrice(StockUnit, Money, User, $reason), ->setVariantPrice(int, Money, User), ->setAccessoryPrice(int, Money, User) // L14, price_changes
StockUnit::findByIdentifier($imeiOrSerial), $unit->latestImeiCheck(), $unit->location(), $unit->isInStore($id), scopes inStore(), withStatus()
UnitTransitions::allowed(?UnitStatus $from, ?UnitStatus $to, UnitAction): bool, ::assert(...), ::pairs()
```
Статусы — **единый список** `UnitStatus` (§10.1): `in_stock, reserved, ordered, in_transit, preparing, on_check, defective, sold, returned_to_supplier, written_off`; `NULL` = «нет в учёте» (создание сторнировано). Таблица переходов — `UnitTransitions::TABLE` (ровно §10.3, тест `tests/Unit/Inventory/UnitTransitionsTest`).

### Деньги
```php
Money::kzt(489000), Money::kzt('2433.33'), Money::kztMinor($tiyn), Money::usd('1.20'), Money::usdMinor($cents), Money::of($minor, 'USD'), Money::zero()
->plus(), ->minus(), ->multiply(int), ->negate(), ->abs(), Money::sum([...])
->convert(Currency::KZT, Rate::of('512.40'))          // half-up до тиына
->percent('12.00', Rounding::HalfUp, Money::TENGE)     // комиссия до целого ₸; бонусы — Rounding::Down
->allocate([$w1, $w2], Money::TENGE)                   // метод наибольших остатков (скидка чека)
->roundTo(Money::TENGE * 1000, Rounding::Up), ->isWhole(), ->format() // «489 000 ₸», «$1 140» (NBSP, минус U+2212)
Rate::of('512,40'), ->toString() «512.4000», ->format() «512,40»
app(ExchangeRates::class)->current(?CarbonInterface $at = null): ?Rate, ->set($rate, User $actor, ?string $comment, bool $confirmed = false)  // >5 % → «Вы уверены?»
```

### Учёт
```php
app(AccountingPeriods::class)->closedUntil(), ->isOpen($moment), ->assertOpen($moment), ->close(CarbonInterface $month, User $owner), ->reopenLast(User, string $reason)
app(DocumentNumberAllocator::class)->allocate('ГРО', 2026)   // нумерация не‑документов (внутри транзакции); ->issue(DocumentCounter) — для Poster
app(ChainVerifier::class)->firstBrokenLink('money_entries'): ?int   // ChainVerifier::JOURNALS; null = цепочка цела
Document::findByIdempotencyKey($key), $doc->title() «Продажа ПРД-2026-000012», ->isDraft()/isPosted()/isReversed(), ->rate()
App\Support\Idempotency\Idempotency::newKey()
App\Support\DocumentNumber\DocumentNumber::format('ПРД', 2026, 12), ::parse(), DocumentType::Sale->prefix()
```

### Исключения (показывать `getMessage()` как есть)
`App\Support\Exceptions\UserFacingException` (поля `reason`, `context`) ← `PostingException` ← `InsufficientStock`, `InsufficientFunds`, `InsufficientBonuses`, `PeriodClosed`, `ReversalBlocked`, `DocumentNotDraft`, `NotImplementedDocumentType`; `AccessDenied`, `ApprovalRequired`, `InvalidUnitTransition`. Тенанси: `NoOrganizationSelected`, `CrossOrganizationViolation` (это ошибки программиста).

---

## 7. Учётные правила, зашитые в ядро (кратко)

- Деньги на экране/в чеке — целые тенге: цены, скидки, оплаты в продаже проверяются на `% 100 == 0`.
- Пересчёт $→₸ — half‑up до тиына; комиссия — до целого ₸ half‑up; бонусы — до целого вниз; средняя аксессуара — half‑up до тиына.
- Себестоимость телефона поштучная (`stock_units.cost_kzt`), аксессуара — скользящая средняя по фирме (`accessory_costs`), расход по средней; возврат — по себестоимости из продажи с пересчётом средней; сторно прихода — по формуле §4.2 (запрещено, если продано).
- Продажа: Σ оплат = итог; сдача только у наличных; бонусы ≤ `max_pay_percent` чека; база бонусов = сумма строки − оплачено бонусами − в долг; бонусы активируются через `activation_days`, живут `lifetime_days`.
- Долг: `+` нам должны; у клиента срок обязателен; просроченный долг блокирует новый (кроме владельца); USD‑долг — `avg_rate`, курсовая разница при погашении.
- Касса и счета без `allow_negative` не уходят в минус (и в PHP, и CHECK).
- Сторно = обратные записи; Σ журнала по паре «документ + сторно» = 0.

---

## 8. Тесты

- `php artisan test` — Pest, **PostgreSQL `prime_mobile_test`** (phpunit.xml), `RefreshDatabase` для `tests/Feature`. Сейчас 122 теста (≈50 с): `Unit/Support` (Money, Imei, идентификаторы, TransactionRetry), `Unit/Inventory/UnitTransitionsTest`, `Feature/{Ledger, Inventory, Sales, Money, Tenancy, Seeders}`.
- **Два прогона одновременно на одной базе** (два разработчика/агента) дают ложные падения и взаимоблокировки на `migrate:fresh` (`drop table`): второму прогону — своя база, `createdb -U prime prime_mobile_test_<имя>` и `DB_DATABASE=prime_mobile_test_<имя> php artisan test`.
- `Tests\Support\Reconciliation::problems()` — список расхождений копий с журналами (ТЗ 02 §13.2 № 1–8, 10–12); пустой = всё сходится. Вызывать в контексте фирмы.
- Фикстура `world(array $settings = [], string $name = 'Prime Mobile'): Tests\Support\World` — фирма с ролями, магазином, счетами, пользователями `owner/admin/seller/seller2/accountant`, поставщиком, клиентом, вариантами `iphone`/`usedIphone`, аксессуарами `case`/`glass`. Помощники: `->rate('515')`, `->phone(?Variant, ?Money $cost, array $attrs, bool $imeiCheck = true): StockUnit`, `->accessories($acc, $qty, $cost)`, `->cash(Money, $accountCode)`, `->balance($code)`, `->debt($type, $id, $cur)`, `->bonuses($customer)`, `->accessoryQty()`, `->accessoryAvg()`, `World::imei()`.
- Ошибки БД внутри теста оборачивайте в `DB::transaction(...)` (savepoint), иначе транзакция теста станет «aborted».
- Настоящую параллельность двух соединений в `RefreshDatabase` не проверить (данные не закоммичены): двойная продажа проверяется последовательным проведением + частичным индексом + проверкой `FOR UPDATE` в логе запросов.

## 9. Демо‑данные (`php artisan migrate:fresh --seed`)

`CatalogSeeder` (≈22 модели: iPhone 13–17, Air, 16e, Galaxy S25/S25 Ultra/A35/A55, Redmi Note 14/14 Pro, iPad Air 11 M2; память/цвета/ОЗУ; 11 аксессуаров) и `PrimeMobileSeeder`: фирма «Prime Mobile» (акцент blue), магазин ТРЦ «Керуен», пользователи **Кайрат +77010000001** (владелец), **Айгерим +77010000002**, **Данияр +77010000003** (продавцы), пароль `password`; поставщики Dubai Mobile, ТехноОпт, сервис‑мастер; 9 клиентов; курсы до **512,40**; входящие остатки 45 дней назад, 6 приходов, ~23 продажи за 14 дней (5 — сегодня, как на экране «Статистика»), расходы, оплата поставщику, конвертация, 43 телефона (2 без отметки госбазы, 2 в корзине), долги клиентов (3 просрочены, 1 284 000 ₸), бонусы.

## 10. Допущения ядра (можно поменять)

1. RLS PostgreSQL ещё не включён: изоляция — глобальный скоуп + проверки при записи + тесты. Включать вместе с отдельным пользователем БД приложения (ТЗ 03 §12 п.3).
2. Курсовая разница при конвертации не пишется третьей «денежной» записью (иначе баланс счёта перестал бы совпадать с реальными деньгами): она равна Σ `amount_kzt` записей документа и хранится в `fx_conversions.fx_diff_kzt`; по долгам — `debt_entries.fx_diff_kzt`.
3. Сторно прихода телефона переводит экземпляр в `status = NULL` («нет в учёте») и освобождает его IMEI.
4. Продажа зарезервированного (`reserved`) или забронированного по заказу (`ordered`) экземпляра «съедает» бронь: в журнале остаётся `status_before = reserved|ordered`, но сторно такой продажи возвращает экземпляр в `in_stock` (бронь не восстанавливается — модуль заказов при необходимости бронирует заново).
5. Номер документа: год — по хоз. дате в часовом поясе фирмы; `transfer_out`/`transfer_in` делят один счётчик ПРМ.
6. Курс документа берётся на момент проведения; ручной курс — только владелец.
7. Хэш‑цепочка сериализует проведения одной фирмы (advisory‑lock) — для 1–2 магазинов это незаметно.
8. Начисление бонусов на долговую часть при погашении долга и активация/сгорание бонусов — модуль Loyalty (`BonusLedger::refresh()`, `BonusKind::Expire`).
9. Услуги (поклейка стекла) как строки чека — модуль Sales добавит тип строки.
10. Продавец может провести приход телефонов без цены (`cost_unknown`), аксессуары — только с ценой (иначе испортится средняя).

## 11. Изоляция фирм и права — что гарантирует ядро (security‑ревью 09.11.2026)

Тесты — `tests/Feature/Tenancy/SecurityHardeningTest.php`.

**Чужие id в документе (ТЗ 03 §18 п.4).** Id из черновика — это данные из запроса, им не доверяем:
- экземпляры и счета проверяются scoped‑блокировкой `LockManager` («Телефон/Счёт не найден в этой фирме»);
- контрагенты (`LockPlan::counterparty()`) и клиенты бонусов (`LockPlan::bonus()`) — явной проверкой в `LockManager` («Клиент/Поставщик не найден в этой фирме»). **Модулю достаточно положить контрагента в LockPlan** — проверка выполнится сама;
- `Poster` до handler’а: проводит только активный член фирмы (интегратор, уволенный, заблокированный, человек другой фирмы — `AccessDenied` «Доступ закрыт», даже если handler забыл `authorize`), магазин документа — этой фирмы и из магазинов сотрудника;
- `MoneyLedger::record()`: счёт, привязанный к магазину (`money_accounts.store_id`), — только для сотрудника этого магазина;
- продажа: продавец чека — активный член фирмы; возврат: клиент возврата = клиент продажи; входящие остатки: поставщик телефона — этой фирмы;
- `documents.approval_request_id` сохраняется, только если `ApprovalGate` действительно что‑то разрешил в этом проведении (иначе выдуманный id выводил бы чек из дневных лимитов L3/L11).

**В самой БД** (миграция `…_tenancy_cross_organization_guards`, пока RLS выключен): составные FK `(organization_id, X_id) → X(organization_id, id)` у шапки документа (магазин, сторно‑ссылки), журналов, копий балансов, экземпляров/IMEI/броней/госбазы, счетов и способов оплаты, смен; триггер `pm_counterparty_same_org()` на `counterparty_balances`, `debt_entries`, `money_entries` (customer/supplier той же фирмы). Детальные строки черновиков проверяет handler при проведении. Свои таблицы модуля: добавьте такой же составной FK — уникальный ключ `(organization_id, id)` уже есть у `stores, documents, stock_units, suppliers, customers, money_accounts, money_articles, bonus_entries, accounting_periods`.

**Контекст фирмы:** `SetCurrentOrganization` — persistent middleware Livewire (действия Filament и кассы получают фирму и перепроверку членства); выключенная фирма (`organizations.is_active = false`) закрывает доступ и живой сессии; воркер очереди очищает фирму перед каждой задачей (задача ставит свою через `CurrentOrganization::run()`); `activity_log.organization_id` заполняется сам. Сырые SQL (`DB::select`) глобальный фильтр не получают — фильтруйте `organization_id` сами (как `BonusLedger::sources()`); кэши в синглтонах — с ключом фирмы (как `MoneyLedger::account()`).

**Видимость:** `HasSensitiveFields` есть и у `Purchase` (total, credit_amount, supplier_id, invoice_no), `PurchasePayment` (amount, covered_amount), `CustomerReturnLine` (cost_kzt), `OpeningBalanceLine` (себестоимость, поставщик). Ошибка «не хватает денег» по безналичному счёту без права `money.view_bank_balances` — без сумм. `forViewer()` только добавляет скрытые поля: не переиспользуйте один экземпляр модели для разных зрителей.

**Массовое присваивание:** журналы и копии балансов — `$guarded = ['*']` (только `forceFill` в ledgers); у `StockUnit` через `fill()` меняются лишь описательные поля (grade, battery_percent, year, defects, description, show_on_site) — IMEI/модель/поставщик/цена только документами и `PriceService`; `organizations.closed_until` — только `AccountingPeriods`.

**ИИН:** шифрование (cast `encrypted`, ключ APP_KEY вне БД), отпечаток HMAC (`BLIND_INDEX_KEY`; в production без него или при совпадении с APP_KEY — исключение), `iin`/`iin_hash` не сериализуются.

**Ещё не сделано (модули Security/Staff):** RLS + отдельный пользователь БД; блокировка 15 мин после 5 неверных паролей по телефону (сейчас — встроенный лимит Filament 5/мин на IP); 2FA, устройства; сессии в Redis и удаление сессий при блокировке; `no-store` для ERP; spatie `$user->can()` не смотрит статус членства — вне HTTP (джобы, рассылки «кому можно») используйте `AccessChecker`.

## 12. Учётная целостность — integrity‑ревью 09.11.2026

Тесты — `tests/Feature/Ledger/IntegrityReviewTest.php`, помощник **`Tests\Support\Reconciliation::problems()`** — ночные проверки ТЗ 02 §13.2 (№ 1–8, 10–12: копии = журналы, средняя ±1 ₸, место/себестоимость экземпляра, sold ⇔ одна активная строка, пары «документ + сторно» = 0, цепочки). Модулям: после своего сценария и его сторно — `expect(Reconciliation::problems())->toBe([])`. Демо‑данные сидера проверяются тем же помощником.

Исправлено в ядре:
1. Сторно прихода/вход. остатков/trade‑in восстанавливает прежнюю себестоимость экземпляра (раньше оставалась себестоимость сторнированного документа — ломало проверку №4 и возврат после сторно trade‑in).
2. Отмена/сторно начисления берёт бонусы из **своего** начисления, даже просроченного (раньше списывались другие, живые бонусы клиента).
3. Частичные возвраты: бонусы (возврат потраченных, отмена начисленных) — нарастающим итогом; раньше 3 возврата по 1 шт. давали 17+17+17 из 50 и третий падал `LogicException`, а отмена теряла 1 бонус.
4. Возврат продажи в долг: неоплаченная часть — только уменьшением долга; сумма строки ≤ невозвращённой части; деньги — только на счёт ₸; количество перепроверяется под блокировкой; продажа — `FOR SHARE`.
5. Продажа «из аванса» — не больше аванса (раньше превращалась в долг без срока и проверок лимитов).
6. Ключ повтора: одновременная вставка того же `idempotency_key` (двойной тап) возвращает документ «победителя» вместо ошибки 23505.
7. Блокировки: `organizations FOR KEY SHARE` в Poster/Reverser (закрытие периода не «проскакивает» между проверкой даты и коммитом сторно); повтор транзакции при `55P03`/`40P01`/`40001` с паузой; недостающие строки копий вставляются в фиксированном порядке ключей.
8. БД: CHECK `reversed ⇔ reversed_by_document_id`; `pm_posted_detail_guard` проверяет и новый `document_id`; хэш‑цепочка без NULL‑полей.
9. L18 (дней с проведения для сторно) считается в часовом поясе фирмы.
10. `UnitStatusService::change()` принимает только переходы без журнала (`UnitStatusService::WITHOUT_JOURNAL`: готов к продаже, на подготовку, решение по проверке, в ремонт, заказ / отмена заказа); продажа/возврат/перемещение и т.п. «голой» сменой статуса — `LogicException`, корзина — только `ReservationService`.

Осталось (не ядро / решение за владельцем):
- Хвост журнала, удалённый с отключёнными триггерами, `ChainVerifier` не видит (нет внешней «головы» цепочки) — хранить последний `chain_seq/hash` в `reconciliation_runs` ночной сверки.
- Отдельный пользователь БД без `ALTER`/`DISABLE TRIGGER` (ТЗ 02 §2.5 п.2) — на деплое.
- Сторно продажи из корзины/заказа бронь не восстанавливает; сторно возврата после «Решения по проверке» возвращает `sold` (переходы без журнала не блокируют сторно, кроме `reserved`/`ordered`).
- Курсовая разница при конвертации и оплате $‑долга — в `fx_conversions.fx_diff_kzt` / `debt_entries.fx_diff_kzt`, а не третьей денежной записью (§10 п.2) — отчёт прибыли должен брать её оттуда.


## 13. Итоговое состояние ядра 1.1 и известные ограничения (сводка)

**Проверено 09.11.2026 после слияния обоих ревью:** `php artisan migrate:fresh --seed` на `prime_mobile` — OK (18 миграций, 3 сидера); `php artisan test` — 122 теста / 629 проверок зелёные; на демо‑данных `Reconciliation::problems() = []`, `ChainVerifier` — все 4 цепочки целы. Конфликтов между правками двух ревьюеров нет (общие файлы `LockManager`, `Poster`, `Reverser`, `BonusLedger`, `SaleHandler`, `CustomerReturnHandler` содержат обе группы исправлений).

**Публичный API ядра для модулей** (подробно — §3, §6):
- проведение и сторно: `Poster::post()`, `Reverser::reverse()`, `TransactionRetry::run()`; builder’ы черновиков §5 (`->save()`, `->post()`);
- расширение: `DocumentHandler` / `AbstractDocumentHandler` + `HandlerRegistry::register()`, `LockPlan`, `PostingContext`, `ApprovalGate` (биндит модуль Approvals);
- писатели журналов (только из handler’ов): `InventoryLedger`, `MoneyLedger`, `DebtLedger`, `BonusLedger` (+ `BonusLedger::refresh()` для Loyalty);
- сервисы: `ReservationService`, `UnitStatusService`, `ImeiCheckService`, `PriceService`, `ExchangeRates`, `AccountingPeriods`, `DocumentNumberAllocator`, `ChainVerifier`, `ReturnCalculator::quote()`, `OrganizationProvisioner`;
- права и фирма: `AccessChecker`, `HasSensitiveFields`, `CurrentOrganization`, `BelongsToOrganization`, `OrganizationSettings`;
- значения: `Money`, `Rate`, `Currency`, `Imei`, `Iin`, `Phone`, `DocumentNumber`, `Idempotency`;
- события после коммита: `DocumentPosted`, `DocumentReversed`;
- тесты: `world()`, `Reconciliation::problems()`.

**Известные ограничения** (не ядро или решение за владельцем):
1. RLS PostgreSQL выключен; нужен отдельный пользователь БД приложения без `ALTER` / `DISABLE TRIGGER` / `TRUNCATE`‑обхода (ТЗ 02 §2.5 п.2, ТЗ 03 §12 п.3) — на деплое.
2. `ChainVerifier` не видит удалённый «хвост» журнала (голова цепочки нигде не хранится) — сохранять последний `chain_seq/hash` в ночной сверке.
3. Сторно продажи из корзины/заказа не восстанавливает бронь (экземпляр → `in_stock`); сторно возврата после «Решения по проверке» возвращает экземпляр в `sold`.
4. Курсовая разница конвертации/оплаты $‑долга — в `fx_conversions.fx_diff_kzt` / `debt_entries.fx_diff_kzt`, не отдельной денежной записью; отчёт прибыли берёт её оттуда.
5. Безопасность входа (модули Security/Staff): блокировка 15 мин после 5 неверных паролей по телефону (сейчас лимит Filament 5/мин на IP), 2FA, устройства, сессии в Redis и их удаление при блокировке, `no-store` для ERP.
6. spatie `$user->can()` не смотрит статус членства — вне HTTP только `AccessChecker`.
7. Продавец может забронировать в корзину телефон другого магазина (блокирует его на `reservation_minutes`); продать — нельзя.
8. Детальные строки черновиков не имеют составных FK на фирму — их id проверяет handler при проведении (понятная ошибка вместо ошибки БД). Таблицы модулей — по правилу §11.
9. Типы документов §5 «NotImplemented» (trade‑in, перемещения, списание, ревизия, смены, заказы, …) — делают модули; ledgers и переходы для них готовы.
10. Параллельность двух соединений в `RefreshDatabase` не проверяется (см. §8): блокировки проверены последовательным проведением, индексами и логом запросов.

---

## 14. Изменения ядра после аудита (10.11.2026)

Решения — `../../docs/audit/decisions.md`, статус — `../../docs/AUDIT.md` §0. Всё ниже покрыто тестами (153 теста).

**Права и доступ**
- Отметка «👁» (`V`) даёт только права просмотра из `PermissionCatalog::VIEW_PERMISSIONS` (`customer.view`, `debt.supplier_view`, `payroll.view`, `period.view` …), действие — никогда (№1). Новое право с `V` на действии — тест упадёт.
- Владелец проходит любое право каталога, в т. ч. добавленное модулем позже; `RoleProvisioner::installFor()` дописывает новые права ролям владельца (№23). Неизвестное право — «нет права».
- Магазины сотрудника — таблица `membership_stores` с галочками `can_sales` / `can_finance`. Нет строки = нет доступа. Права групп «Продажа» и «Trade-in» требуют «Продажи», группы «Деньги» — «Финансы» (`PermissionCatalog::storeScope()`). Владелец — все магазины. `OrganizationProvisioner::addMember(..., $storeIds = null)`: `null` = все магазины фирмы, `[]` — ошибка (№10).
- Себестоимость, поставщик и т. п. (`HasSensitiveFields`) скрываются **по умолчанию** при загрузке модели, если пользователь вошёл в систему (№21). `forViewer()` по-прежнему можно звать явно.

**Продажа**
- Цена по прайсу берётся из базы при проведении (телефон — его цена, иначе цена варианта; аксессуар — прайс фирмы) и записывается в `sale_lines.list_price_kzt`. Цена ниже прайса = скидка на разницу для лимитов L1–L3; «подарок» = скидка 100 %; телефон подарком быть не может (№2).
- Счёт и комиссия оплаты — только из `payment_methods` фирмы/магазина, значения из запроса игнорируются; «Зачёт trade-in» и «Зачёт обмена» без парного документа запрещены; «Kaspi Магазин» — единственный способ оплаты чека (№6, №34).
- Телефон с `cost_unknown` не продаёт никто (№4). Отметка госбазы «Не верифицирован» / «Заблокирован» — продажа запрещена всегда (№55).
- Потолок долга одного клиента — настройка `sales.customer_debt_ceiling` (300 000 ₸): выше — только владелец (№17).

**Документы и проведение**
- Новые типы: `cost_entry` ВСБ (внесение себестоимости, обработчик в ядре), `legacy_return` ВДП, `cash_adjustment` РКС (вместо `shift_close`), `bonus_expire` БСГ; `СМН` — номер смены (не документ). CHECK `documents.type` строится из `DocumentType`.
- Черновик без явной даты получает момент проведения (`documents.occurred_at_manual`, №19). Сторно всегда своей датой (№27).
- `Poster::postMany()` / `Reverser::reverseMany()` — несколько документов одной операцией: всё или ничего (№20).
- Строки черновика менять только через `Document::editDraft()` — та же блокировка, что у проведения (№45).
- **Смена** (№18, №57): одна открытая смена на магазин (`shifts`, `Shifts::open()/close()/current()`), документ помнит `documents.shift_id`. Обработчик объявляет `shiftRequirement()`: `Always` (продажа, возврат), `IfStoreCash` (по умолчанию: если двигает наличные кассы магазина), `Never` (входящие остатки). Проверка — `ShiftGuard` после `post()` и при сторно. Фикстура `world()` открывает смену.

**Склад**
- Аксессуары и категории — данные фирмы (`organization_id`, артикул и штрихкод уникальны в фирме, составные FK) (№9). Стартовые аксессуары: `CatalogSeeder::accessoriesForCurrentOrganization()`.
- Публичный код `<префикс>-10001` выдаёт триггер базы из счётчика фирмы, префикс — `storefront.code_prefix` (№43).
- «Показывать на сайте» включено по умолчанию (№44).
- Новые переходы статуса (решения А6): входящие остатки в `on_check`/`defective`, ВДП, найден после списания, потерян в пути, брак на подготовке, возврат поставщику с подготовки, приход того же экземпляра от поставщика (№41).
- Trade-in своего проданного телефона (`UnitAction::TradeInKnown`) закрывает прежнюю строку продажи (`closed_reason = bought_back`), сторно акта — открывает; телефон можно продать снова (№39).
- Просроченная чужая бронь не держит телефон; задача `prime:release-reservations` каждую минуту (№51).

**Деньги**
- Стартовые счета: касса ₸/$ и терминал — свои у каждого магазина; технические «Зачёт обмена», «Инкассация в пути ₸/$», банковский «Kaspi Магазин». Банковские способы оплаты выключены, пока не введена комиссия (№28, №34, №52).
- Курсовая разница — только `fx_conversions.fx_diff_kzt` и `debt_entries.fx_diff_kzt`; статьи и вида долга `fx_diff` больше нет (№38).
- Оплата поставщику: погашенная сумма долга считается системой; комиссия оплаты долга клиентом — из настройки способа (№22).
- Изменение «счёт может уходить в минус» действует сразу (триггер, №50). Месяцы закрываются по порядку (№46). Лимит 0 = всегда заявка (№48).

**Надёжность**
- Очередь изменений (transactional outbox): `App\Support\Outbox\Outbox::record()` в транзакции изменения; ядро пишет `document.posted`, `document.reversed`, `unit.changed`. Доставка — `prime:outbox` (каждую минуту), событие `OutboxMessage`; слушатели обязаны быть идемпотентными (№5).
- Ночная сверка в рабочей системе: `prime:reconcile` (03:30 Алматы), результат — `reconciliation_runs` (№16). Класс — `App\Domain\Ledger\Services\Reconciliation`.
- Журнал действий — только добавление (триггер, №14). В журналы можно добавлять только колонки `NULL` без значения по умолчанию — иначе ломается хэш-цепочка; тест `JournalHashColumnsTest` (№15).

**Передано модулям (правило записано в decisions.md):** ВДП-обработчик и возврат по продажам из sfon (№3, модуль чеков/возвратов); план возврата денег по способам (№26, модуль возвратов); заявка к смене цены и ссылка заявки у сторно (№53, модуль заявок); сетка выкупа и акт trade-in (№29, №40).

## 15. Документы раунда 1 (10.11.2026)

| Документ | Черновик | Обработчик | Смена |
|---|---|---|---|
| Движение денег ДДС | `Money\Drafts\CashMoveDraft` (deposit, withdrawal, transfer, collection, acceptCollection) | `Money\Posting\CashMoveHandler` | если наличные магазина |
| Расхождение кассы РКС | `Money\Drafts\CashAdjustmentDraft` (вручную: `money.adjust`, L16) | `Money\Posting\CashAdjustmentHandler` | не нужна |
| Корректировка долга КДЛ | `Debts\Drafts\DebtAdjustmentDraft` (forgive, increase, postpone) | `Debts\Posting\DebtAdjustmentHandler` | не нужна |
| Бонусы вручную БНС | `Loyalty\Drafts\BonusManualDraft` (give, take) | `Loyalty\Posting\BonusManualHandler` | не нужна |
| Сгорание бонусов БСГ | только `Loyalty\Services\BonusExpiry` (ночью) | `Loyalty\Posting\BonusExpireHandler`, не сторнируется | не нужна |
| Заказ ЗАК | `Orders\Drafts\OrderDraft` (unit, variant, prepay) | `Orders\Posting\OrderHandler` | с предоплатой — нужна |

- **Смена с пересчётом:** `Money\Services\ShiftCash::open($store, $user, [account_id => сумма])` и `close($shift, …)` — сумма в тиынах/центах по каждой кассе магазина, расхождение → РКС на каждую кассу; `Shifts` ядра без пересчёта остаётся для ночного автозакрытия.
- **Системный документ:** `Poster::postAsSystem()` — только типы `Poster::SYSTEM_TYPES` с `meta.system = true`; в журнале действий — «система».
- **Обработчик может сам решать, кто отменяет документ:** метод `authorizeReversal(Document, PostingContext): bool` (заказ — его продавец или `order.cancel_any`); иначе — `doc.reverse` с L18. Проверка «после документа были другие» смотрит только телефоны, которые документ двигал.
- **Бонусы на долг:** `Loyalty\Services\DebtBonusAccrual` вызывает оплата долга клиента; разнесение — `debt_payment_allocations`; возврат отменяет и эти бонусы.
- **Заказы:** `Orders\Services\Orders::extend()`, `attachUnit()`, `tick()`; продажа по заказу — `SaleDraft::fromOrder($orderDocId)` + оплата `prepayment`.
- **Расписание:** `prime:shifts-tick` (каждую минуту), `prime:orders-tick` (5 мин), `prime:bonus-expire` (03:10), `prime:reconcile` (03:30).
