<?php

namespace App\Filament\Warehouse\Pages;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\AccessoryBalance;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Warehouse\Drafts\StockOpDraft;
use App\Domain\Warehouse\Models\Revision;
use App\Domain\Warehouse\Models\RevisionScan;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/**
 * Э15 «Ревизия» (/revisions): start for the store, everyone scans (USB / iPhone camera), the screen shows found /
 * not found / extra; the admin or owner posts the result as a document РЕВ (shortages written off, phones written off
 * before and found — back, accessories ±). Extra phones of another store or unknown are listed, not posted.
 */
class Revisions extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'revision.scan';

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'scan';

    protected static ?int $navigationSort = 38;

    protected static ?string $slug = 'revisions';

    protected static string $view = 'warehouse.pages.revisions';

    public string $scope = 'all';

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Ревизия');
    }

    public function getTitle(): string
    {
        return __('Ревизия');
    }

    protected function current(): ?Revision
    {
        return Revision::query()->where('store_id', WorkingStore::get(auth()->user())->id)->where('status', 'open')->latest('id')->first();
    }

    public function start(): void
    {
        if (! $this->current()) {
            Revision::query()->create(['store_id' => WorkingStore::get(auth()->user())->id, 'scope' => in_array($this->scope, ['all', 'phones', 'accessories'], true) ? $this->scope : 'all', 'started_by' => auth()->id()]);
        }
    }

    public function scan(string $code): void
    {
        $this->error = null;
        $rev = $this->current() ?? throw new \RuntimeException('no revision');
        $code = trim($code);
        $unit = StockUnit::query()->where('public_code', mb_strtoupper($code))->first() ?? StockUnit::findByIdentifier($code);
        if ($unit) {
            if (RevisionScan::query()->where('revision_id', $rev->id)->where('stock_unit_id', $unit->id)->exists()) {
                $this->error = __('Уже отсканирован: :c', ['c' => $unit->public_code]);

                return;
            }
            RevisionScan::query()->create(['revision_id' => $rev->id, 'code' => $code, 'stock_unit_id' => $unit->id, 'scanned_by' => auth()->id()]);

            return;
        }
        $a = Accessory::query()->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->first();
        RevisionScan::query()->create(['revision_id' => $rev->id, 'code' => $code, 'accessory_id' => $a?->id, 'scanned_by' => auth()->id()]);
        if (! $a) {
            $this->error = __('Неизвестный код :c — записан как лишний', ['c' => $code]);
        }
    }

    /** @return array{found: \Illuminate\Support\Collection, missing: \Illuminate\Support\Collection, extra: \Illuminate\Support\Collection, back: \Illuminate\Support\Collection, acc: array} */
    public function result(Revision $rev): array
    {
        $scans = RevisionScan::query()->where('revision_id', $rev->id)->get();
        $scannedUnits = $scans->whereNotNull('stock_unit_id')->pluck('stock_unit_id')->all();
        $expected = $rev->scope === 'accessories' ? collect() : StockUnit::query()->with('variant')->inStore($rev->store_id)
            ->whereIn('status', [UnitStatus::InStock->value, UnitStatus::Preparing->value, UnitStatus::OnCheck->value, UnitStatus::Defective->value, UnitStatus::Reserved->value])->get();
        $scannedModels = StockUnit::query()->with('variant')->whereIn('id', $scannedUnits)->get();
        $acc = [];
        if ($rev->scope !== 'phones') {
            $have = AccessoryBalance::query()->where('location_type', 'store')->where('location_id', $rev->store_id)->where('qty', '>', 0)->pluck('qty', 'accessory_id');
            $counted = $scans->whereNotNull('accessory_id')->groupBy('accessory_id')->map->sum('qty');
            foreach ($have->keys()->merge($counted->keys())->unique() as $id) {
                $diff = (int) ($counted[$id] ?? 0) - (int) ($have[$id] ?? 0);
                if ($diff !== 0) {
                    $acc[(int) $id] = ['name' => Accessory::query()->find($id)?->name_ru, 'have' => (int) ($have[$id] ?? 0), 'counted' => (int) ($counted[$id] ?? 0), 'diff' => $diff];
                }
            }
        }

        return [
            'found' => $expected->whereIn('id', $scannedUnits),
            'missing' => $expected->whereNotIn('id', $scannedUnits),
            'back' => $scannedModels->filter(fn ($u) => $u->status === UnitStatus::WrittenOff),
            'extra' => $scannedModels->filter(fn ($u) => $u->status !== UnitStatus::WrittenOff && ! $expected->contains('id', $u->id))
                ->concat($scans->whereNull('stock_unit_id')->whereNull('accessory_id')->map(fn ($s) => (object) ['public_code' => $s->code, 'variant' => null])),
            'acc' => $acc,
        ];
    }

    public function post(): void
    {
        $this->error = null;
        try {
            Access::authorize('revision.post');
            $rev = $this->current() ?? throw new \RuntimeException('no revision');
            $r = $this->result($rev);
            $d = StockOpDraft::revision($rev->store_id, $rev->id);
            $any = false;
            foreach ($r['missing'] as $u) {
                if ($u->status === UnitStatus::Reserved) {
                    continue; // a phone in a cart is counted as here (the seller has it)
                }
                $d->shortage($u);
                $any = true;
            }
            foreach ($r['back'] as $u) {
                $d->found($u);
                $any = true;
            }
            foreach ($r['acc'] as $id => $a) {
                $d->accessory($id, $a['diff']);
                $any = true;
            }
            if ($any) {
                $doc = $d->post(auth()->user());
                $rev->forceFill(['status' => 'posted', 'document_id' => $doc->id])->save();
                $this->flash = __('Ревизия проведена: :n', ['n' => $doc->number]);
            } else {
                $rev->forceFill(['status' => 'posted'])->save();
                $this->flash = __('Ревизия закрыта: всё сошлось');
            }
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function cancel(): void
    {
        $this->current()?->forceFill(['status' => 'cancelled'])->save();
    }

    protected function getViewData(): array
    {
        $rev = $this->current();

        return ['rev' => $rev, 'r' => $rev ? $this->result($rev) : null, 'canPost' => Access::allows('revision.post'),
            'scans' => $rev ? RevisionScan::query()->where('revision_id', $rev->id)->count() : 0];
    }
}
