<?php

namespace App\Domain\Warehouse\Services;

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Services\DocumentNumberAllocator;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Warehouse\Models\WarrantyCase;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Гарантийные обращения ГРО (TZ 01 П13, 02 №46, 04 Э17): not an accounting document — the phone stays «продан»,
 * the case carries the flag «в гарантийном ремонте». accepted → in_repair (у нас) | at_service (в сервисе) → ready →
 * issued; refused at any step. A replacement by another unit is an exchange (Exchange) at the price of the sale.
 */
class Warranty
{
    public const NEXT = [
        'accepted' => ['in_repair', 'at_service', 'refused'], 'in_repair' => ['ready', 'at_service', 'refused'],
        'at_service' => ['ready', 'refused'], 'ready' => ['issued'], 'issued' => [], 'refused' => ['issued'],
    ];

    public function __construct(private readonly AccessChecker $access, private readonly DocumentNumberAllocator $numbers) {}

    public function accept(StockUnit $unit, User $user, int $storeId, string $complaint, ?string $kit = null): WarrantyCase
    {
        if (! $this->access->decide($user, 'sale.create', storeId: $storeId)->isAllowed()) {
            throw AccessDenied::because('access_denied', 'Принять обращение может сотрудник этого магазина');
        }
        if ($unit->status !== UnitStatus::Sold) {
            throw PostingException::because('not_sold', 'Гарантия — только для проданного нами телефона');
        }
        if (trim($complaint) === '') {
            throw PostingException::because('complaint_required', 'Опишите неисправность со слов клиента');
        }
        $line = SaleLine::query()->where('stock_unit_id', $unit->id)->where('is_active', true)->latest('id')->first();
        if (WarrantyCase::query()->where('stock_unit_id', $unit->id)->whereNotIn('status', ['issued'])->exists()) {
            throw PostingException::because('case_open', 'По этому телефону уже есть открытое обращение');
        }

        return DB::transaction(function () use ($unit, $user, $storeId, $complaint, $kit, $line) {
            $year = (int) now(CurrentOrganization::instance()->require()->timezone)->format('Y');

            return WarrantyCase::query()->create([
                'number' => $this->numbers->allocate('ГРО', $year), 'store_id' => $storeId, 'stock_unit_id' => $unit->id,
                'sale_document_id' => $line?->document_id, 'customer_id' => $line ? Sale::query()->where('document_id', $line->document_id)->value('customer_id') : null,
                'complaint' => trim($complaint), 'kit' => $kit, 'accepted_by' => $user->id,
            ]);
        });
    }

    public function move(WarrantyCase $case, string $to, User $user, ?string $result = null, ?int $serviceSupplierId = null): WarrantyCase
    {
        $right = in_array($to, ['issued'], true) ? 'sale.create' : 'stock.prepare';
        if (! $this->access->decide($user, $right, storeId: (int) $case->store_id)->isAllowed()) {
            throw AccessDenied::because('access_denied', 'Нет права менять статус обращения');
        }

        return DB::transaction(function () use ($case, $to, $result, $serviceSupplierId, $user) {
            $c = WarrantyCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($to, self::NEXT[$c->status] ?? [], true)) {
                throw PostingException::because('bad_status', 'Так перевести обращение нельзя');
            }
            $c->forceFill(array_filter([
                'status' => $to, 'result' => $result ?: null,
                'repair_where' => $to === 'in_repair' ? 'self' : ($to === 'at_service' ? 'service' : null),
                'service_supplier_id' => $to === 'at_service' ? $serviceSupplierId : null,
                'issued_at' => $to === 'issued' ? now() : null,
            ], fn ($v) => $v !== null))->save();
            activity('warranty')->performedOn($c)->causedBy($user)->withProperties(['status' => $to, 'number' => $c->number])->log('warranty_status');

            return $c;
        });
    }
}
