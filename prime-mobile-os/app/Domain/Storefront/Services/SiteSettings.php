<?php

namespace App\Domain\Storefront\Services;

use App\Support\Tenancy\CurrentOrganization;

/**
 * «Сайт → Настройки» (TZ 05 §12.4) — stored in organizations.settings.storefront, defaults here. Texts are ru/kk; an
 * empty kk falls back to ru (§13.3).
 */
final class SiteSettings
{
    public const DEFAULTS = [
        'enabled' => false,                               // §4.2 «Сайт включён» — off until the launch
        'city_ru' => 'Алматы', 'city_kk' => 'Алматы',
        'instagram' => null,
        'show_tradein_estimate' => false,                 // §9.10
        'photo_review' => true,                           // §3.5 seller photo on a visible unit → «Новые фото»
        'templates' => [
            'ask_ru' => 'Здравствуйте! Интересует {название}, {код}, {цена}. {ссылка} Код заявки {заявка}',
            'installment_ru' => 'Здравствуйте! Хочу {название} ({код}, {цена}) в рассрочку на {срок} мес. {ссылка} Код заявки {заявка}',
            'book_ru' => 'Здравствуйте! Хочу забронировать {название}, {код}, {цена}. Приду {когда}. {ссылка} Код заявки {заявка}',
            'similar_ru' => 'Здравствуйте! Подберите похожий на {название} ({код}). Код заявки {заявка}',
            'to_order_ru' => 'Здравствуйте! Ищу: {запрос}. Есть под заказ? Код заявки {заявка}',
            'tradein_ru' => 'Здравствуйте! Хочу сдать в trade-in: {модель} {память}, экран: {экран}, батарея: {батарея}. Взамен: {товар}. Оценка на сайте: {оценка}. Код заявки {заявка}',
            'general_ru' => 'Здравствуйте! Пишу с сайта.',
            'ask_kk' => null, 'installment_kk' => null, 'book_kk' => null, 'similar_kk' => null, 'to_order_kk' => null, 'tradein_kk' => null, 'general_kk' => null,
        ],
        'installment' => [
            'enabled' => true,
            'title_ru' => 'Рассрочка Kaspi',
            'title_kk' => 'Kaspi бөліп төлеу',
            'months' => [3, 6, 12, 24],
            'markup' => [0, 0, 0, 0],                     // % overpay of the buyer per term (Кайрат enters, не проверено)
            'min' => 3000, 'max' => 2000000,              // ₸
        ],
        'grades' => [
            'A' => ['ru' => 'Отличное', 'kk' => 'Өте жақсы', 'text_ru' => 'Как новый, следов использования почти нет', 'text_kk' => null],
            'B' => ['ru' => 'Хорошее', 'kk' => 'Жақсы', 'text_ru' => 'Есть лёгкие следы использования, всё работает', 'text_kk' => null],
            'C' => ['ru' => 'Заметные следы', 'kk' => 'Байқалатын іздер', 'text_ru' => 'Заметные царапины или потёртости, всё работает', 'text_kk' => null],
        ],
    ];

    /** Public items of the trade-in / preparation checklist (§2.1 public_checks, §9.5). */
    public const PUBLIC_CHECKS = [
        'icloud' => ['iCloud / Локатор выключен', 'iCloud / Локатор өшірілген'],
        'biometrics' => ['Face ID / Touch ID', 'Face ID / Touch ID'],
        'cameras' => ['Камеры', 'Камералар'],
        'audio' => ['Динамики и микрофон', 'Динамиктер мен микрофон'],
        'charging' => ['Зарядка', 'Зарядтау'],
        'water' => ['Влага не обнаружена', 'Ылғал табылмады'],
    ];

    private array $data;

    public function __construct(?array $stored = null)
    {
        $stored ??= (array) ((CurrentOrganization::instance()->require()->settings ?? [])['storefront'] ?? []);
        $this->data = self::merge(self::DEFAULTS, $stored);
    }

    /** Stored values override defaults key by key; a stored list (months, markup) replaces the default list whole. */
    private static function merge(array $defaults, array $stored): array
    {
        foreach ($stored as $k => $v) {
            if ($v === null) {
                continue;
            }
            $defaults[$k] = is_array($v) && isset($defaults[$k]) && is_array($defaults[$k]) && ! array_is_list($defaults[$k]) ? self::merge($defaults[$k], $v) : $v;
        }

        return $defaults;
    }

    public static function current(): self
    {
        return new self;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function enabled(): bool
    {
        return (bool) $this->data['enabled'];
    }

    public function template(string $type, string $lang): string
    {
        $t = $this->data['templates'];

        return ($lang === 'kk' ? ($t[$type.'_kk'] ?? null) : null) ?: ($t[$type.'_ru'] ?? '');
    }

    public function grade(?string $grade, string $lang = 'ru'): ?string
    {
        $g = $this->data['grades'][$grade] ?? null;

        return $g ? ($g[$lang] ?? null ?: $g['ru']) : null;
    }

    public function gradeText(?string $grade, string $lang = 'ru'): ?string
    {
        $g = $this->data['grades'][$grade] ?? null;

        return $g ? (($lang === 'kk' ? $g['text_kk'] ?? null : null) ?: $g['text_ru']) : null;
    }

    /**
     * §7.2: monthly payment for the max allowed term of the main program, rounded UP to a whole tenge.
     *
     * @return array{0: int, 1: int}|null [payment ₸, months]
     */
    public function installmentFrom(int $price): ?array
    {
        $p = $this->data['installment'];
        if (! $p['enabled'] || $price < (int) $p['min'] || $price > (int) $p['max'] || ! $p['months']) {
            return null;
        }
        $i = array_keys($p['months'], max($p['months']))[0];
        $months = (int) $p['months'][$i];
        $markup = (float) ($p['markup'][$i] ?? 0);

        return [(int) ceil(round($price * (100 + $markup) / 100 / $months, 4)), $months];
    }

    /** @return list<array{months: int, payment: int}> all terms for the card (§7.2 «выбор срока»). */
    public function installmentTerms(int $price): array
    {
        $p = $this->data['installment'];
        if (! $p['enabled'] || $price < (int) $p['min'] || $price > (int) $p['max']) {
            return [];
        }
        $out = [];
        foreach ($p['months'] as $i => $m) {
            $out[] = ['months' => (int) $m, 'payment' => (int) ceil(round($price * (100 + (float) ($p['markup'][$i] ?? 0)) / 100 / $m, 4))];
        }

        return $out;
    }
}
