<?php

namespace App\Filament\Stock\Pages;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\AccessoryCategory;
use App\Domain\Catalog\Models\Color;
use App\Domain\Catalog\Models\Family;
use App\Domain\Catalog\Models\Memory;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\Variant;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Filament\Pages\Page;
use Illuminate\Validation\Rule;

/**
 * Э24 «Справочник» (/catalog). Models are common to all firms (Д1): names of models — only the owner, variants and
 * lists — catalog.edit; accessories and categories belong to the firm; Kazakh names — catalog.translate (Э24.4).
 */
class Catalog extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'stock.passport';

    protected static ?MenuGroup $menuGroup = MenuGroup::Management;

    protected static ?string $navigationIcon = 'book';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'catalog';

    protected static string $view = 'stock.pages.catalog';

    public string $tabName = 'models';

    public string $q = '';

    public ?int $modelId = null;

    public array $model = [];

    public array $newVariant = ['memory_id' => null, 'color_id' => null];

    public array $newModel = ['family_id' => null, 'name_ru' => ''];

    public ?array $accessory = null;

    /** @var array<string, string> «type:id» => Kazakh name */
    public array $kk = [];

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Справочник');
    }

    public function getTitle(): string
    {
        return __('Справочник');
    }

    public function openModel(int $id): void
    {
        $m = ProductModel::query()->findOrFail($id);
        $this->modelId = $m->id;
        $this->model = $m->only(['name_ru', 'name_kk', 'name_en', 'release_year']);
    }

    public function saveModel(): void
    {
        Access::authorize('catalog.edit');
        $m = ProductModel::query()->findOrFail($this->modelId);
        $data = $this->validate(['model.name_ru' => 'required|string|max:120', 'model.name_kk' => 'nullable|string|max:120',
            'model.name_en' => 'nullable|string|max:120', 'model.release_year' => 'nullable|integer|min:2000|max:2100'])['model'];
        // the model is shared by all firms: renaming it — only the owner (TZ 04 Э24)
        if ($data['name_ru'] !== $m->name_ru && ! Access::isOwner()) {
            $this->addError('model.name_ru', __('Название модели меняет только владелец'));

            return;
        }
        $m->fill($data)->save();
        $this->flash = __('Сохранено');
    }

    public function addModel(): void
    {
        Access::authorize('catalog.edit');
        $data = $this->validate(['newModel.family_id' => 'required|exists:families,id', 'newModel.name_ru' => 'required|string|max:120'])['newModel'];
        $m = ProductModel::query()->firstOrCreate(['family_id' => $data['family_id'], 'name_ru' => trim($data['name_ru'])]);
        $this->newModel = ['family_id' => $data['family_id'], 'name_ru' => ''];
        $this->openModel($m->id);
    }

    public function addVariant(): void
    {
        Access::authorize('catalog.edit');
        $data = $this->validate(['newVariant.memory_id' => 'nullable|exists:memories,id', 'newVariant.color_id' => 'nullable|exists:colors,id'])['newVariant'];
        Variant::findOrCreateFor((int) $this->modelId, $data['memory_id'] ? (int) $data['memory_id'] : null, $data['color_id'] ? (int) $data['color_id'] : null);
        $this->newVariant = ['memory_id' => null, 'color_id' => null];
    }

    public function editAccessory(?int $id = null): void
    {
        Access::authorize('catalog.edit');
        $a = $id ? Accessory::query()->findOrFail($id) : new Accessory;
        $this->accessory = ['id' => $a->id] + $a->only(['name_ru', 'name_kk', 'sku', 'barcode', 'category_id', 'warranty_months']);
    }

    public function generateBarcode(): void
    {
        // internal EAN-13 with the 200–299 «in-store» prefix and a check digit (TZ 01 П7)
        do {
            $body = '2'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split($body) as $i => $d) {
                $sum += (int) $d * ($i % 2 ? 3 : 1);
            }
            $code = $body.((10 - $sum % 10) % 10);
        } while (Accessory::query()->where('barcode', $code)->exists());
        $this->accessory['barcode'] = $code;
    }

    public function saveAccessory(): void
    {
        Access::authorize('catalog.edit');
        $id = $this->accessory['id'] ?? null;
        $org = \App\Support\Tenancy\CurrentOrganization::instance()->requireId();
        $data = $this->validate([
            'accessory.name_ru' => 'required|string|max:160', 'accessory.name_kk' => 'nullable|string|max:160',
            'accessory.sku' => ['required', 'string', 'max:64', Rule::unique('accessories', 'sku')->where('organization_id', $org)->ignore($id)],
            'accessory.barcode' => ['nullable', 'string', 'max:64', Rule::unique('accessories', 'barcode')->where('organization_id', $org)->ignore($id)],
            'accessory.category_id' => 'nullable|exists:accessory_categories,id', 'accessory.warranty_months' => 'nullable|integer|min:0|max:60',
        ], ['accessory.barcode.unique' => __('Этот штрихкод уже у другого товара'), 'accessory.sku.unique' => __('Такой артикул уже есть')])['accessory'];
        ($id ? Accessory::query()->findOrFail($id) : new Accessory)->fill($data + ['is_active' => true])->save();
        $this->accessory = null;
        $this->flash = __('Сохранено');
    }

    public function saveKk(string $key): void
    {
        Access::authorize('catalog.translate');
        [$type, $id] = explode(':', $key) + [null, null];
        $value = trim((string) ($this->kk[$key] ?? ''));
        if ($value === '') {
            return;
        }
        $class = ['model' => ProductModel::class, 'color' => Color::class, 'accessory' => Accessory::class][$type] ?? null;
        abort_unless($class, 404);
        $class::query()->findOrFail((int) $id)->forceFill(['name_kk' => mb_substr($value, 0, 160)])->save();
        unset($this->kk[$key]);
    }

    protected function getViewData(): array
    {
        $q = trim($this->q);

        return [
            'types' => ProductType::query()->orderBy('sort')->get(),
            'families' => Family::query()->orderBy('sort')->orderBy('name')->get(),
            'models' => ProductModel::query()->when($q !== '', fn ($w) => $w->where('name_ru', 'ilike', '%'.addcslashes($q, '%_').'%'))->orderBy('name_ru')->get()->groupBy('family_id'),
            'variants' => $this->modelId ? Variant::query()->where('model_id', $this->modelId)->with(['memory', 'color'])->get() : collect(),
            'memories' => Memory::query()->orderBy('gb')->get(),
            'colors' => Color::query()->orderBy('name_ru')->get(),
            'accessories' => $this->tabName === 'accessories' ? Accessory::query()->with('category')->when($q !== '', fn ($w) => $w->where('name_ru', 'ilike', '%'.addcslashes($q, '%_').'%'))->orderBy('name_ru')->limit(300)->get() : collect(),
            'categories' => AccessoryCategory::query()->orderBy('name_ru')->get(),
            'untranslated' => $this->tabName === 'kk' ? collect()
                ->concat(ProductModel::query()->whereNull('name_kk')->orderBy('name_ru')->limit(200)->get()->map(fn ($m) => ['key' => 'model:'.$m->id, 'kind' => __('Модель'), 'name' => $m->name_ru]))
                ->concat(Color::query()->whereNull('name_kk')->orderBy('name_ru')->get()->map(fn ($c) => ['key' => 'color:'.$c->id, 'kind' => __('Цвет'), 'name' => $c->name_ru]))
                ->concat(Accessory::query()->whereNull('name_kk')->orderBy('name_ru')->limit(300)->get()->map(fn ($a) => ['key' => 'accessory:'.$a->id, 'kind' => __('Аксессуар'), 'name' => $a->name_ru])) : collect(),
            'canEdit' => Access::allows('catalog.edit'),
            'canTranslate' => Access::allows('catalog.translate'),
        ];
    }
}
