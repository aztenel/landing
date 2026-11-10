<?php

namespace App\Filament\Warehouse\Pages;

use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Warehouse\Drafts\StockOpDraft;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Filament\Warehouse\Concerns\PicksGoods;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/** Э16 «Списание» и «Возврат поставщику» (/writeoffs): scan what goes, a reason / the supplier and the money back. */
class WriteOffs extends Page
{
    use HasErpAccess, PicksGoods;

    protected static string|array|null $permission = ['stock.write_off', 'stock.return_supplier'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'ban';

    protected static ?int $navigationSort = 37;

    protected static ?string $slug = 'writeoffs';

    protected static string $view = 'warehouse.pages.writeoffs';

    public string $mode = 'write_off';

    public string $reason = '';

    public ?int $supplierId = null;

    public string $refundMode = 'debt';

    public string $refund = '';

    public ?int $accountId = null;

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Списание');
    }

    public function getTitle(): string
    {
        return __('Списание и возврат поставщику');
    }

    public function mount(): void
    {
        if ($unit = request()->integer('unit')) {
            $this->goods[] = ['kind' => 'unit', 'id' => $unit, 'qty' => 1];
        }
        $this->mode = request()->query('mode') === 'supplier' ? 'supplier' : 'write_off';
    }

    public function post(): void
    {
        $this->error = null;
        try {
            $store = WorkingStore::get(auth()->user());
            $draft = $this->mode === 'supplier'
                ? StockOpDraft::supplierReturn($store, (int) $this->supplierId, $this->refundMode, Money::kzt((int) preg_replace('/\D/', '', $this->refund)), $this->refundMode === 'money' ? $this->accountId : null)
                : StockOpDraft::writeOff($store, $this->reason);
            if ($this->mode === 'supplier') {
                $draft->comment(trim($this->reason) ?: null);
            }
            $this->addGoodsTo($draft, $this->mode === 'write_off' ? $this->reason : null);
            $doc = $draft->post(auth()->user());
            $this->flash = __('Проведено: :n', ['n' => $doc->number]);
            $this->goods = [];
            $this->reason = $this->refund = '';
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        return [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'accounts' => MoneyAccount::query()->where('currency', 'KZT')->whereIn('type', ['cash', 'bank', 'safe'])->where('is_active', true)->get(['id', 'name']),
            'recent' => Document::query()->whereIn('type', ['write_off', 'supplier_return'])->whereIn('store_id', WorkingStore::available(auth()->user())->pluck('id'))->latest('id')->limit(10)->get(),
        ];
    }
}
