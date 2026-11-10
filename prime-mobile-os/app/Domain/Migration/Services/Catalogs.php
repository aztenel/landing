<?php

namespace App\Domain\Migration\Services;

use App\Domain\Catalog\Models\Color;
use App\Domain\Catalog\Models\Family;
use App\Domain\Catalog\Models\Memory;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\Ram;
use App\Domain\Catalog\Models\Variant;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * sfon dictionary → our catalogue (TZ 07 §1.5): by sfon_id, else by code / name; missing — created. Names: ru, kz → kk,
 * en. Memory «256», «256GB», «1TB» → «256 ГБ», «1 ТБ». Different spellings of a colour go through import_mappings.
 */
final class Catalogs
{
    public static function name(array $d, string $lang): ?string
    {
        $v = $d['name'.ucfirst($lang)] ?? $d['name_'.$lang] ?? ($lang === 'ru' ? ($d['name'] ?? null) : null);

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    public function type(array $d): ProductType
    {
        $t = ProductType::query()->where('sfon_id', $d['id'] ?? 0)->first()
            ?? ProductType::query()->where('code', $d['code'] ?? '-')->first()
            ?? ProductType::query()->where('name_ru', self::name($d, 'ru'))->first()
            ?? new ProductType(['code' => $d['code'] ?? Str::slug((string) self::name($d, 'ru')), 'name_ru' => self::name($d, 'ru') ?? (string) ($d['code'] ?? '?')]);
        $t->forceFill(['sfon_id' => $d['id'] ?? $t->sfon_id, 'name_kk' => $t->name_kk ?: self::name($d, 'kz')])->save();

        return $t;
    }

    public function family(array $d, ProductType $type): Family
    {
        $name = self::name($d, 'ru') ?? (string) ($d['code'] ?? '?');
        $f = Family::query()->where('sfon_id', $d['id'] ?? 0)->first()
            ?? Family::query()->where('product_type_id', $type->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? new Family(['product_type_id' => $type->id, 'name' => $name, 'brand' => $name]);
        $f->forceFill(['sfon_id' => $d['id'] ?? $f->sfon_id])->save();

        return $f;
    }

    public function model(array $d, Family $family): ProductModel
    {
        $name = self::name($d, 'ru') ?? self::name($d, 'en') ?? (string) ($d['code'] ?? '?');
        $m = ProductModel::query()->where('sfon_id', $d['id'] ?? 0)->first()
            ?? ProductModel::query()->where('family_id', $family->id)->whereRaw('lower(name_ru) = ?', [mb_strtolower($name)])->first()
            ?? new ProductModel(['family_id' => $family->id, 'name_ru' => $name]);
        $m->forceFill(['sfon_id' => $d['id'] ?? $m->sfon_id, 'name_kk' => $m->name_kk ?: self::name($d, 'kz'), 'name_en' => $m->name_en ?: self::name($d, 'en')])->save();

        return $m;
    }

    public static function gb(?string $v): ?int
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*(tb|тб|gb|гб)?/iu', (string) $v, $m)) {
            return null;
        }
        $n = (float) str_replace(',', '.', $m[1]);

        return (int) round(in_array(mb_strtolower($m[2] ?? ''), ['tb', 'тб'], true) ? $n * 1024 : $n);
    }

    public function memory(?string $v, ?int $sfonId = null): ?Memory
    {
        $gb = self::gb($v);
        if (! $gb) {
            return null;
        }
        $m = Memory::query()->where('gb', $gb)->first() ?? Memory::query()->create(['gb' => $gb, 'name' => $gb >= 1024 ? ($gb / 1024).' ТБ' : $gb.' ГБ']);
        if ($sfonId && ! $m->sfon_id) {
            $m->forceFill(['sfon_id' => $sfonId])->save();
        }

        return $m;
    }

    public function ram(?string $v, ?int $sfonId = null): ?Ram
    {
        $gb = self::gb($v);
        if (! $gb) {
            return null;
        }

        return Ram::query()->where('gb', $gb)->first() ?? Ram::query()->create(['gb' => $gb, 'name' => $gb.' ГБ']);
    }

    public function color(?string $ru, ?string $kk = null, ?string $en = null, ?int $sfonId = null): ?Color
    {
        $name = trim((string) ($ru ?: $en));
        if ($name === '') {
            return null;
        }
        $mapped = DB::table('import_mappings')->where('organization_id', CurrentOrganization::instance()->requireId())
            ->where('kind', 'color')->where('source_value', mb_strtolower($name))->value('target_id');
        $c = ($mapped ? Color::query()->find($mapped) : null)
            ?? ($sfonId ? Color::query()->where('sfon_id', $sfonId)->first() : null)
            ?? Color::query()->whereRaw('lower(name_ru) = ? or lower(name_en) = ?', [mb_strtolower($name), mb_strtolower($name)])->first()
            ?? Color::query()->create(['name_ru' => $name, 'name_kk' => $kk, 'name_en' => $en]);
        if (($sfonId && ! $c->sfon_id) || (! $c->name_kk && $kk)) {
            $c->forceFill(['sfon_id' => $c->sfon_id ?: $sfonId, 'name_kk' => $c->name_kk ?: $kk])->save();
        }

        return $c;
    }

    /** A product group / used item of the API → our variant (model by sfon id or names). */
    public function variant(array $p): ?Variant
    {
        $model = ProductModel::query()->where('sfon_id', $p['modelId'] ?? 0)->first();
        if (! $model && ! empty($p['modelName'])) {
            $model = ProductModel::query()->whereRaw('lower(name_ru) = ? or lower(name_en) = ?', [mb_strtolower($p['modelName']), mb_strtolower($p['modelName'])])->first();
        }
        if (! $model) {
            return null;
        }

        return Variant::findOrCreateFor($model->id, $this->memory($p['memoryName'] ?? $p['memoryCode'] ?? null)?->id,
            $this->color($p['colorName'] ?? $p['colorCode'] ?? null)?->id, $this->ram($p['ramName'] ?? $p['ramCode'] ?? null)?->id);
    }
}
