<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\AccessoryCategory;
use App\Domain\Catalog\Models\Color;
use App\Domain\Catalog\Models\Family;
use App\Domain\Catalog\Models\Memory;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\Ram;
use App\Domain\Catalog\Models\Variant;
use Illuminate\Database\Seeder;

/**
 * Global catalog (assumption Д1): types → families → models with memory/colour/RAM variants, accessories.
 * Idempotent (firstOrCreate everywhere).
 */
class CatalogSeeder extends Seeder
{
    /** type code => [name_ru, name_kk, serialized] */
    public const TYPES = [
        'smartphone' => ['Смартфон', 'Смартфон', true],
        'tablet' => ['Планшет', 'Планшет', true],
        'headphones' => ['Наушники', 'Құлаққап', false],
    ];

    /** [type, brand, family, model, year, memories, colors, rams] */
    public const MODELS = [
        ['smartphone', 'Apple', 'iPhone', 'iPhone 13', 2021, ['128 ГБ', '256 ГБ'], ['Midnight', 'Starlight', 'Blue', 'Pink', '(PRODUCT)RED', 'Green'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 14', 2022, ['128 ГБ', '256 ГБ'], ['Midnight', 'Starlight', 'Blue', 'Purple', 'Yellow'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 14 Pro', 2022, ['128 ГБ', '256 ГБ', '512 ГБ'], ['Space Black', 'Silver', 'Gold', 'Deep Purple'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 14 Pro Max', 2022, ['128 ГБ', '256 ГБ', '512 ГБ'], ['Space Black', 'Silver', 'Gold', 'Deep Purple'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 15', 2023, ['128 ГБ', '256 ГБ', '512 ГБ'], ['Black', 'Blue', 'Green', 'Yellow', 'Pink'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 15 Pro', 2023, ['128 ГБ', '256 ГБ', '512 ГБ', '1 ТБ'], ['Natural Titanium', 'Blue Titanium', 'White Titanium', 'Black Titanium'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 15 Pro Max', 2023, ['256 ГБ', '512 ГБ', '1 ТБ'], ['Natural Titanium', 'Blue Titanium', 'White Titanium', 'Black Titanium'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 16e', 2025, ['128 ГБ', '256 ГБ', '512 ГБ'], ['Black', 'White'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 16', 2024, ['128 ГБ', '256 ГБ', '512 ГБ'], ['Black', 'White', 'Pink', 'Teal', 'Ultramarine'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 16 Pro', 2024, ['128 ГБ', '256 ГБ', '512 ГБ', '1 ТБ'], ['Desert Titanium', 'Natural Titanium', 'White Titanium', 'Black Titanium'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 16 Pro Max', 2024, ['256 ГБ', '512 ГБ', '1 ТБ'], ['Desert Titanium', 'Natural Titanium', 'White Titanium', 'Black Titanium'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 17', 2025, ['256 ГБ', '512 ГБ'], ['Black', 'White', 'Mist Blue', 'Sage', 'Lavender'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone Air', 2025, ['256 ГБ', '512 ГБ', '1 ТБ'], ['Space Black', 'Cloud White', 'Light Gold', 'Sky Blue'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 17 Pro', 2025, ['256 ГБ', '512 ГБ', '1 ТБ'], ['Silver', 'Cosmic Orange', 'Deep Blue'], []],
        ['smartphone', 'Apple', 'iPhone', 'iPhone 17 Pro Max', 2025, ['256 ГБ', '512 ГБ', '1 ТБ', '2 ТБ'], ['Silver', 'Cosmic Orange', 'Deep Blue'], []],
        ['smartphone', 'Samsung', 'Galaxy S', 'Galaxy S25', 2025, ['256 ГБ', '512 ГБ'], ['Navy', 'Icyblue', 'Mint', 'Silver Shadow'], ['12 ГБ']],
        ['smartphone', 'Samsung', 'Galaxy S', 'Galaxy S25 Ultra', 2025, ['256 ГБ', '512 ГБ', '1 ТБ'], ['Titanium Gray', 'Titanium Black', 'Titanium Silverblue', 'Titanium Whitesilver'], ['12 ГБ']],
        ['smartphone', 'Samsung', 'Galaxy A', 'Galaxy A35', 2024, ['128 ГБ', '256 ГБ'], ['Navy', 'Iceblue', 'Lilac', 'Lemon'], ['8 ГБ']],
        ['smartphone', 'Samsung', 'Galaxy A', 'Galaxy A55', 2024, ['128 ГБ', '256 ГБ'], ['Navy', 'Iceblue', 'Lilac', 'Lemon'], ['8 ГБ']],
        ['smartphone', 'Xiaomi', 'Redmi Note', 'Redmi Note 14', 2025, ['128 ГБ', '256 ГБ'], ['Black', 'Mist Purple', 'Lime Green'], ['8 ГБ']],
        ['smartphone', 'Xiaomi', 'Redmi Note', 'Redmi Note 14 Pro', 2025, ['256 ГБ', '512 ГБ'], ['Black', 'Ocean Blue', 'Lavender Purple'], ['8 ГБ', '12 ГБ']],
        ['tablet', 'Apple', 'iPad', 'iPad Air 11 M2', 2024, ['128 ГБ', '256 ГБ'], ['Space Gray', 'Blue', 'Purple', 'Starlight'], []],
    ];

    public const COLOR_HEX = [
        'Midnight' => '#232A31', 'Starlight' => '#F3EEE6', 'Blue' => '#5C7FA6', 'Pink' => '#F2C9CF', '(PRODUCT)RED' => '#BF0013',
        'Green' => '#3E5246', 'Purple' => '#B8AFE6', 'Yellow' => '#F5E58A', 'Space Black' => '#2E2D2F', 'Silver' => '#E3E4E5',
        'Gold' => '#F4E8CE', 'Deep Purple' => '#594F63', 'Black' => '#1F2020', 'Natural Titanium' => '#BAB4A9',
        'Blue Titanium' => '#2F4452', 'White Titanium' => '#F2F1ED', 'Black Titanium' => '#3B3B3D', 'White' => '#FAFAFA',
        'Teal' => '#B0D4D2', 'Ultramarine' => '#9AADF6', 'Desert Titanium' => '#BFA48F', 'Mist Blue' => '#A9BCD0',
        'Sage' => '#B4BBA2', 'Lavender' => '#DCCFE8', 'Cloud White' => '#F4F4F2', 'Light Gold' => '#EADBC1',
        'Sky Blue' => '#C9DCEB', 'Cosmic Orange' => '#E8792F', 'Deep Blue' => '#2C3A57', 'Navy' => '#2B3448',
        'Icyblue' => '#BFD6EA', 'Mint' => '#CFE7DA', 'Silver Shadow' => '#B9BDC2', 'Titanium Gray' => '#8E8C88',
        'Titanium Black' => '#2F2F31', 'Titanium Silverblue' => '#A9B6C4', 'Titanium Whitesilver' => '#E7E7E5',
        'Iceblue' => '#BFD6EA', 'Lilac' => '#CBB9E4', 'Lemon' => '#F2EDA2', 'Mist Purple' => '#B9A8D6',
        'Lime Green' => '#C9E39A', 'Ocean Blue' => '#3E6C9C', 'Lavender Purple' => '#A893D1', 'Space Gray' => '#5D5F62',
    ];

    /** sku => [category, name_ru, name_kk, barcode] */
    public const ACCESSORIES = [
        'APP-AIRPODS-PRO2' => ['Наушники', 'AirPods Pro 2 (USB-C)', 'AirPods Pro 2 (USB-C)', '0194253939337'],
        'CASE-16P-SIL' => ['Чехлы', 'Чехол iPhone 16 Pro силиконовый MagSafe', 'iPhone 16 Pro қабы, силикон MagSafe', '4601000000016'],
        'CASE-15-CLEAR' => ['Чехлы', 'Чехол iPhone 15 прозрачный', 'iPhone 15 мөлдір қабы', '4601000000023'],
        'CASE-S25U' => ['Чехлы', 'Чехол Galaxy S25 Ultra', 'Galaxy S25 Ultra қабы', '4601000000030'],
        'GLASS-16P' => ['Защитные стёкла', 'Защитное стекло iPhone 16 Pro', 'iPhone 16 Pro қорғаныс шынысы', '4601000000047'],
        'GLASS-15' => ['Защитные стёкла', 'Защитное стекло iPhone 15', 'iPhone 15 қорғаныс шынысы', '4601000000054'],
        'GLASS-CAM' => ['Защитные стёкла', 'Стекло на камеру iPhone', 'iPhone камерасына шыны', '4601000000061'],
        'APP-20W' => ['Зарядки и кабели', 'Зарядное устройство Apple 20W USB-C', 'Apple 20W USB-C зарядтағышы', '0194252156896'],
        'CBL-C-L-1M' => ['Зарядки и кабели', 'Кабель USB-C – Lightning 1 м', 'USB-C – Lightning кабелі 1 м', '4601000000078'],
        'CBL-C-C-1M' => ['Зарядки и кабели', 'Кабель USB-C – USB-C 1 м', 'USB-C – USB-C кабелі 1 м', '4601000000085'],
        'PB-10000' => ['Зарядки и кабели', 'Power Bank 10 000 мАч', 'Power Bank 10 000 мАсағ', '4601000000092'],
    ];

    public function run(): void
    {
        $sort = 0;
        foreach (self::TYPES as $code => [$ru, $kk, $serialized]) {
            ProductType::query()->firstOrCreate(['code' => $code], ['name_ru' => $ru, 'name_kk' => $kk, 'is_serialized' => $serialized, 'sort' => $sort += 10]);
        }

        $sort = 0;
        foreach (self::MODELS as [$type, $brand, $familyName, $modelName, $year, $memories, $colors, $rams]) {
            $typeId = ProductType::query()->where('code', $type)->value('id');
            $family = Family::query()->firstOrCreate(['product_type_id' => $typeId, 'name' => $familyName], ['brand' => $brand]);
            $model = ProductModel::query()->firstOrCreate(
                ['family_id' => $family->id, 'name_ru' => $modelName],
                ['name_kk' => $modelName, 'name_en' => $modelName, 'release_year' => $year, 'sort' => $sort += 10],
            );
            foreach ($memories as $memoryName) {
                $memory = Memory::query()->firstOrCreate(['name' => $memoryName], ['gb' => $this->gb($memoryName), 'sort' => $this->gb($memoryName)]);
                foreach ($colors as $colorName) {
                    $color = Color::query()->firstOrCreate(['name_ru' => $colorName], ['name_en' => $colorName, 'name_kk' => $colorName, 'hex' => self::COLOR_HEX[$colorName] ?? null]);
                    foreach ($rams ?: [null] as $ramName) {
                        $ram = $ramName ? Ram::query()->firstOrCreate(['name' => $ramName], ['gb' => $this->gb($ramName)]) : null;
                        Variant::findOrCreateFor($model->id, $memory->id, $color->id, $ram?->id);
                    }
                }
            }
        }

        $sort = 0;
        foreach (self::ACCESSORIES as $sku => [$category, $ru, $kk, $barcode]) {
            $cat = AccessoryCategory::query()->firstOrCreate(['name_ru' => $category], ['sort' => $sort += 10]);
            Accessory::query()->firstOrCreate(['sku' => $sku], ['category_id' => $cat->id, 'name_ru' => $ru, 'name_kk' => $kk, 'barcode' => $barcode]);
        }
    }

    private function gb(string $name): int
    {
        $n = (int) $name;

        return str_contains($name, 'ТБ') ? $n * 1024 : $n;
    }

    /** Find a variant by human names (used by the demo seeder). */
    public static function variant(string $model, string $memory, string $color, ?string $ram = null): Variant
    {
        $modelId = ProductModel::query()->where('name_ru', $model)->value('id');

        return Variant::query()
            ->where('model_id', $modelId)
            ->where('memory_id', Memory::query()->where('name', $memory)->value('id'))
            ->where('color_id', Color::query()->where('name_ru', $color)->value('id'))
            ->when($ram, fn ($q) => $q->where('ram_id', Ram::query()->where('name', $ram)->value('id')), fn ($q) => $q->orderBy('ram_id'))
            ->firstOrFail();
    }
}
