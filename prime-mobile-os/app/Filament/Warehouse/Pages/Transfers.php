<?php

namespace App\Filament\Warehouse\Pages;

use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Warehouse\Drafts\StockOpDraft;
use App\Domain\Warehouse\Models\StockOp;
use App\Domain\Warehouse\Models\StockOpLine;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Filament\Warehouse\Concerns\PicksGoods;
use App\Support\Exceptions\UserFacingException;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/**
 * Э14 «Перемещения» (/transfers): send to another store of the firm (scan), incoming with «принять / отказать» per line.
 * The menu item is hidden while the firm has one store (TZ 04 Э14, Допущение).
 */
class Transfers extends Page
{
    use HasErpAccess, PicksGoods;

    protected static string|array|null $permission = ['transfer.send', 'transfer.accept'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'swap';

    protected static ?int $navigationSort = 36;

    protected static ?string $slug = 'transfers';

    protected static string $view = 'warehouse.pages.transfers';

    public ?int $toStore = null;

    public ?int $openId = null;

    /** @var array<int, array{refuse: bool, qty: int, refused: int}> line id => decision */
    public array $decide = [];

    public ?string $error = null;

    public ?string $flash = null;

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && Store::query()->where('is_active', true)->count() > 1;
    }

    public static function getNavigationLabel(): string
    {
        return __('Перемещения');
    }

    public function getTitle(): string
    {
        return __('Перемещения');
    }

    public function send(): void
    {
        $this->error = null;
        try {
            $doc = StockOpDraft::transferOut(WorkingStore::get(auth()->user()), (int) $this->toStore);
            $this->addGoodsTo($doc);
            $posted = $doc->post(auth()->user());
            $this->flash = __('Отправлено: :n', ['n' => $posted->number]);
            $this->goods = [];
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function open(int $id): void
    {
        $doc = $this->incoming()->firstWhere('id', $id) ?? abort(404);
        $this->openId = $doc->id;
        $this->decide = StockOpLine::query()->where('document_id', $doc->id)->get()
            ->mapWithKeys(fn ($l) => [$l->id => ['refuse' => false, 'qty' => (int) $l->qty, 'refused' => 0]])->all();
    }

    public function accept(): void
    {
        $this->error = null;
        try {
            $out = $this->incoming()->firstWhere('id', $this->openId) ?? abort(404);
            $d = StockOpDraft::transferIn($out);
            foreach (StockOpLine::query()->where('document_id', $out->id)->get() as $l) {
                $c = $this->decide[$l->id] ?? ['refuse' => false, 'qty' => $l->qty, 'refused' => 0];
                if ($l->stock_unit_id) {
                    $c['refuse'] ? $d->refuseUnit((int) $l->stock_unit_id) : $d->unit((int) $l->stock_unit_id);
                } else {
                    $refused = max(0, min((int) $l->qty, (int) $c['refused']));
                    $d->accessory((int) $l->accessory_id, (int) $l->qty - $refused, $refused);
                }
            }
            $posted = $d->post(auth()->user());
            $this->flash = __('Принято: :n', ['n' => $posted->number]);
            $this->openId = null;
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function incoming()
    {
        $mine = WorkingStore::available(auth()->user())->pluck('id');

        return Document::query()->where('type', 'transfer_out')->where('status', 'posted')
            ->whereIn('id', StockOp::query()->where('status', 'in_transit')->whereIn('to_store_id', $mine)->select('document_id'))->get();
    }

    protected function getViewData(): array
    {
        $here = WorkingStore::get(auth()->user());
        $outgoing = Document::query()->where('type', 'transfer_out')->whereIn('status', ['posted', 'reversed'])->where('store_id', $here->id)->latest('id')->limit(20)->get();

        return [
            'stores' => Store::query()->where('is_active', true)->where('id', '<>', $here->id)->get(['id', 'name']),
            'incoming' => $this->incoming(),
            'outgoing' => $outgoing,
            'ops' => StockOp::query()->whereIn('document_id', $outgoing->pluck('id')->merge($this->incoming()->pluck('id')))->get()->keyBy('document_id'),
            'storeNames' => Store::query()->pluck('name', 'id'),
            'openLines' => $this->openId ? StockOpLine::query()->where('document_id', $this->openId)->get() : collect(),
        ];
    }
}
