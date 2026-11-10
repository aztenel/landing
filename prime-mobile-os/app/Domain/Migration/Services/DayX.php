<?php

namespace App\Domain\Migration\Services;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\AccessoryCategory;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Models\Document;
use App\Domain\Migration\Models\ImportRow;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Day X (TZ 07 §1.7): the scan of every phone takes one sfon row of its variant (used — chosen from a short list),
 * unknown phones become «лишние»; the quality report must be all zeros; then one ВХО per store with units,
 * accessories, counted money, debts and bonuses — posted by the owner after the signed lists (Ш6).
 */
final class DayX
{
    public const STEPS = [
        'Ш0' => 'Доступы: ключ API в .env сервера, письмо в sfon на полную выгрузку',
        'Ш1' => 'Выгрузка API: «Забрать из sfon»; число товаров = экрану «Склад» sfon',
        'Ш2' => 'Разведка Excel: все выгрузки загружены, колонки сопоставлены',
        'Ш3' => 'Справочники и магазины: 0 ошибок, список «нет перевода» передан',
        'Ш4' => 'Репетиция остатков № 1 на staging',
        'Ш5' => 'Доработка: 0 без себестоимости, 0 несопоставленных моделей',
        'Ш6' => 'Клиенты, долги, бонусы: списки распечатаны, проверены и подписаны Кайратом',
        'Ш7' => 'Полная репетиция дня X на staging, время замерено',
        'Ш8' => 'День X: ВХО проведён, акт сверки подписан',
        'Ш9' => 'Параллельная работа ≥ 14 дней, 5 сверок подряд без расхождений',
        'Ш10' => 'Архив истории загружен, суммы по месяцам совпали',
        'Ш11' => 'Отключение sfon: письменное согласие Кайрата',
    ];

    /** @return array{result: string, message: string, choices?: Collection} */
    public function scan(Store $store, string $raw, User $user, ?int $usedRowId = null): array
    {
        $imei = Imei::normalize($raw);
        if (! preg_match('/^\d{15}$/', $imei) || ! Imei::isValid($imei)) {
            return ['result' => 'bad', 'message' => __('IMEI с ошибкой — переснимите или введите с *#06#')];
        }
        $dup = DB::table('migration_scans')->where('organization_id', CurrentOrganization::instance()->requireId())->where('imei', $imei)->first();
        if ($dup) {
            return ['result' => 'dup', 'message' => __('Уже отсканирован (строка :n)', ['n' => DB::table('migration_scans')->where('organization_id', $dup->organization_id)->where('id', '<=', $dup->id)->count()])];
        }
        if (StockUnit::findByIdentifier($imei)) {
            return ['result' => 'dup', 'message' => __('Этот IMEI уже есть в системе')];
        }
        if ($usedRowId) {
            $row = ImportRow::query()->where('kind', 'stock_used')->whereKey($usedRowId)->where('status', 'matched')->firstOrFail();
            abort_if($this->taken($row->id), 422);

            return $this->take($store, $imei, $row, 'used', $user);
        }

        // a new phone: the first free group of its variant in this store — the variant comes from the sfon rows by model name in the scan? No IMEI in sfon:
        // the seller confirms the variant only when several groups are possible (handled by the screen with $choose)
        return ['result' => 'choose', 'message' => __('Выберите, какой это телефон'), 'imei' => $imei];
    }

    /** The screen picked a group (new) for the scanned IMEI. */
    public function takeNew(Store $store, string $imei, int $rowId, User $user): array
    {
        $row = ImportRow::query()->where('kind', 'stock_new')->whereKey($rowId)->where('status', 'matched')->firstOrFail();
        if ($this->left($row) <= 0) {
            return ['result' => 'full', 'message' => __('Все :n шт. этого варианта уже отсканированы — это «лишний» телефон', ['n' => $row->fields['count']])];
        }

        return $this->take($store, $imei, $row, 'new', $user);
    }

    /** A phone that is not in sfon (TZ 07 §1.7 «Лишние»): variant, condition, price and cost entered on the spot. */
    public function extra(Store $store, string $imei, int $variantId, string $condition, ?int $price, ?int $cost, User $user): array
    {
        DB::table('migration_scans')->insert(['organization_id' => CurrentOrganization::instance()->requireId(), 'store_id' => $store->id, 'imei' => Imei::normalize($imei),
            'variant_id' => $variantId, 'condition' => $condition === 'used' ? 'used' : 'new', 'extra' => true, 'price' => $price, 'cost' => $cost, 'scanned_by' => $user->id, 'created_at' => now()]);

        return ['result' => 'extra', 'message' => __('Записан как «лишний» — найден при переносе')];
    }

    private function take(Store $store, string $imei, ImportRow $row, string $condition, User $user): array
    {
        $f = (array) $row->fields;
        DB::table('migration_scans')->insert(['organization_id' => CurrentOrganization::instance()->requireId(), 'store_id' => $store->id, 'imei' => $imei, 'import_row_id' => $row->id,
            'variant_id' => $f['variant_id'] ?? null, 'condition' => $condition, 'price' => $f['price'] ?? null, 'battery' => $f['battery'] ?? null, 'scanned_by' => $user->id, 'created_at' => now()]);
        $done = $condition === 'new' ? ((int) $f['count'] - $this->left($row)) : 1;

        return ['result' => 'ok', 'message' => $condition === 'new' ? __(':v — :a из :b', ['v' => Variant::query()->find($f['variant_id'])?->title(), 'a' => $done, 'b' => $f['count']])
            : __('Б/у привязан к карточке sfon')];
    }

    public function left(ImportRow $row): int
    {
        return (int) ($row->fields['count'] ?? 1) - DB::table('migration_scans')->where('import_row_id', $row->id)->count();
    }

    public function taken(int $rowId): bool
    {
        return DB::table('migration_scans')->where('import_row_id', $rowId)->exists();
    }

    /** Groups / used cards of the store for the choice after a scan. */
    public function candidates(Store $store, string $kind)
    {
        return ImportRow::query()->where('kind', $kind)->where('status', 'matched')->get()
            ->filter(fn ($r) => (int) ($r->fields['store_id'] ?? 0) === $store->id && ($kind === 'stock_used' ? ! $this->taken($r->id) : $this->left($r) > 0))->values();
    }

    /** Отчёт качества (TZ 07 §1.6): every target is 0 before the ВХО. */
    public function quality(): array
    {
        $rows = fn (string $kind) => ImportRow::query()->where('kind', $kind)->whereIn('status', ['matched', 'error']);
        $costs = $this->costsByImei();
        $scans = DB::table('migration_scans')->where('organization_id', CurrentOrganization::instance()->requireId())->get();
        $noCost = $scans->filter(fn ($s) => $s->cost === null && ! isset($costs[$s->imei]) && ! $this->groupCost($s->import_row_id))->count();
        $kkMissing = DB::table('colors')->whereNull('name_kk')->whereIn('id', DB::table('variants')->whereIn('id', $scans->pluck('variant_id')->filter())->select('color_id'))->count();

        return [
            'sfon_new' => (int) $rows('stock_new')->get()->sum(fn ($r) => $r->fields['count'] ?? 0), 'sfon_used' => $rows('stock_used')->count(),
            'no_variant' => $rows('stock_new')->where('status', 'error')->count() + $rows('stock_used')->where('status', 'error')->count(),
            'scanned' => $scans->count(), 'extra' => $scans->where('extra', true)->count(),
            'not_found' => (int) $rows('stock_new')->where('status', 'matched')->get()->sum(fn ($r) => max(0, $this->left($r))) + $rows('stock_used')->where('status', 'matched')->get()->filter(fn ($r) => ! $this->taken($r->id))->count(),
            'no_cost' => $noCost, 'no_kk' => $kkMissing,
            'accessories' => ImportRow::query()->where('kind', 'accessories')->where('status', 'matched')->count(),
            'acc_no_cost' => ImportRow::query()->where('kind', 'accessories')->where('status', 'error')->count(),
            'customers' => ImportRow::query()->where('kind', 'customers')->where('status', 'matched')->count(),
            'customer_debts' => (int) ImportRow::query()->where('kind', 'customer_debts')->where('status', 'matched')->get()->sum(fn ($r) => $r->fields['amount'] ?? 0),
            'supplier_debts' => ImportRow::query()->where('kind', 'supplier_debts')->where('status', 'matched')->count(),
            'bonuses' => (int) ImportRow::query()->where('kind', 'bonuses')->where('status', 'matched')->get()->sum(fn ($r) => $r->fields['amount'] ?? 0),
        ];
    }

    private function costsByImei(): array
    {
        return ImportRow::query()->where('kind', 'costs')->where('status', 'matched')->get()->mapWithKeys(fn ($r) => [$r->fields['imei'] => $r->fields])->all();
    }

    private function groupCost(?int $rowId): ?int
    {
        return $rowId ? (ImportRow::query()->find($rowId)?->fields['cost'] ?? null) : null;
    }

    /** Builds and posts the ВХО of one store. $money: account id => counted tiyn. Only the owner, only after Ш6. */
    public function post(Store $store, array $money, User $owner): Document
    {
        if (! Access::isOwner($owner)) {
            throw UserFacingException::because('owner_only', __('Входящие остатки проводит только владелец'));
        }
        if (DB::table('migration_checklist')->where('organization_id', CurrentOrganization::instance()->requireId())->where('step', 'Ш6')->value('status') !== 'done') {
            throw UserFacingException::because('lists_unsigned', __('Сначала шаг Ш6: списки долгов и бонусов проверены и подписаны'));
        }
        if (Document::query()->where('idempotency_key', 'like', 'sfon-vxo-'.$store->id.'-%')->where('status', 'posted')->exists()) {
            throw UserFacingException::because('vxo_done', __('Входящие остатки этого магазина уже проведены'));
        }
        $rate = (string) (DB::table('exchange_rates')->where('organization_id', CurrentOrganization::instance()->requireId())->where('currency', 'USD')->orderByDesc('valid_from')->value('rate') ?? '0');
        $costs = $this->costsByImei();
        $draft = OpeningBalanceDraft::make($store);
        $scans = DB::table('migration_scans')->where('organization_id', CurrentOrganization::instance()->requireId())->where('store_id', $store->id)->get();
        $rowIds = [];
        foreach ($scans as $s) {
            $row = $s->import_row_id ? ImportRow::query()->find($s->import_row_id) : null;
            $c = $costs[$s->imei] ?? null;
            $cost = $s->cost ?? ($c ? ($c['currency'] === 'USD' ? null : $c['cost']) : null) ?? ($row->fields['cost'] ?? null);
            $usd = $s->cost === null && $c && $c['currency'] === 'USD' ? $c['cost'] : null;
            $attrs = ['condition' => $s->condition, 'sale_price' => $s->price ? Money::kztMinor((int) $s->price) : null] + ($s->condition === 'used' ? ['battery_percent' => $s->battery, 'year' => $row->fields['year'] ?? null,
                'defects' => $row->fields['description'] ?? null] : []);
            if ($usd) {
                $attrs['rate'] = $rate;
            }
            if ($cost === null && $usd === null) {
                $attrs['cost_unknown'] = true;
            }
            $draft->unit((int) $s->variant_id, $s->imei, $usd ? Money::usd((int) $usd) : Money::kztMinor((int) ($cost ?? 0)), array_filter($attrs, fn ($v) => $v !== null));
            $row && $rowIds[] = $row->id;
        }
        $first = Store::query()->where('is_active', true)->orderBy('id')->value('id') === $store->id;   // org-wide lines — once, with the main store
        foreach (ImportRow::query()->where('kind', 'accessories')->where('status', 'matched')->get() as $r) {
            $f = $r->fields;
            if (($f['qty'] ?? 0) <= 0 || ! $first) {
                continue;
            }
            $acc = Accessory::query()->when($f['barcode'], fn ($q) => $q->where('barcode', $f['barcode']), fn ($q) => $q->where('sku', $f['sku'] ?: '-'))->first()
                ?? Accessory::query()->where('name_ru', $f['name'])->first()
                ?? Accessory::query()->create(['name_ru' => $f['name'], 'sku' => $f['sku'] ?: 'SF-'.$r->id, 'barcode' => $f['barcode'],
                    'category_id' => AccessoryCategory::query()->firstOrCreate(['name_ru' => $f['category']])->id]);
            $draft->accessory($acc, (int) $f['qty'], Money::kztMinor((int) $f['cost']));
            if ($f['price']) {
                DB::table('accessory_prices')->updateOrInsert(['organization_id' => CurrentOrganization::instance()->requireId(), 'accessory_id' => $acc->id],
                    ['price_kzt' => $f['price'], 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $rowIds[] = $r->id;
        }
        foreach ($money as $account => $amount) {
            if ((int) $amount !== 0) {
                $draft->money((int) $account, MoneyAccount::query()->find($account)?->currency === 'USD' ? Money::usd((int) $amount) : Money::kztMinor((int) $amount));
            }
        }
        if ($first) {
            $customer = function (string $phone, ?string $name) {
                $c = Customer::query()->firstOrNew(['phone' => $phone]);
                if (! $c->exists) {
                    $c->fill(['name' => $name ?: __('Клиент'), 'source' => 'sfon'])->save();
                    $c->forceFill(['pd_consent_method' => 'sfon'])->save();
                }

                return $c;
            };
            foreach (ImportRow::query()->where('kind', 'customers')->where('status', 'matched')->get() as $r) {
                $c = $customer($r->fields['phone'], $r->fields['name']);
                if ($r->fields['source'] && ! $c->source) {
                    $c->update(['source' => $r->fields['source']]);
                }
                $rowIds[] = $r->id;
            }
            foreach (ImportRow::query()->where('kind', 'customer_debts')->where('status', 'matched')->get() as $r) {
                $draft->customerDebt($customer($r->fields['phone'], $r->fields['name']), Money::kztMinor((int) $r->fields['amount']), $r->fields['due'] ? Carbon::parse($r->fields['due']) : null);
                $rowIds[] = $r->id;
            }
            foreach (ImportRow::query()->where('kind', 'supplier_debts')->where('status', 'matched')->get() as $r) {
                $sup = Supplier::query()->whereRaw('lower(name) = ?', [mb_strtolower($r->fields['name'])])->first() ?? Supplier::query()->create(['name' => $r->fields['name'], 'currency' => $r->fields['currency']]);
                $usd = $r->fields['currency'] === 'USD';
                $draft->supplierDebt($sup, $usd ? Money::usd((int) $r->fields['amount']) : Money::kztMinor((int) $r->fields['amount']), $r->fields['due'] ? Carbon::parse($r->fields['due']) : null, $usd ? $rate : null);
                $rowIds[] = $r->id;
            }
            foreach (ImportRow::query()->where('kind', 'bonuses')->where('status', 'matched')->get() as $r) {
                $draft->bonus($customer($r->fields['phone'], $r->fields['name']), (int) $r->fields['amount']);
                $rowIds[] = $r->id;
            }
        }
        $doc = $draft->comment(__('Входящие остатки — перенос из sfon'))->idempotencyKey('sfon-vxo-'.$store->id.'-'.now()->format('YmdHis'))->post($owner);
        ImportRow::query()->whereIn('id', $rowIds)->update(['status' => 'in_vxo', 'updated_at' => now()]);
        // used phones keep their sfon card id for the nightly check
        foreach ($scans->where('condition', 'used') as $s) {
            $key = ImportRow::query()->find($s->import_row_id)?->sfon_key;
            if ($key) {
                StockUnit::query()->where('imei1', $s->imei)->update(['sfon_id' => (int) substr($key, 4)]);
            }
        }
        DB::table('migration_checklist')->updateOrInsert(['organization_id' => CurrentOrganization::instance()->requireId(), 'step' => 'Ш8'],
            ['status' => 'done', 'done_by' => $owner->id, 'done_at' => now(), 'note' => $doc->number]);
        activity('migration')->causedBy($owner)->withProperties(['document' => $doc->number, 'units' => $scans->count()])->log('vxo.posted');

        return $doc;
    }
}
