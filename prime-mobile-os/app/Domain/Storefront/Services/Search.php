<?php

namespace App\Domain\Storefront\Services;

/**
 * Site search normalisation (TZ 05 §9.7): Russian keyboard layout instead of Latin («шзрщту» → iphone), slang
 * («айфон», «самсунг», «про макс»), «гб» optional. Applied to both the stored search_text and the query.
 */
final class Search
{
    private const LAYOUT = ['й' => 'q', 'ц' => 'w', 'у' => 'e', 'к' => 'r', 'е' => 't', 'н' => 'y', 'г' => 'u', 'ш' => 'i', 'щ' => 'o', 'з' => 'p',
        'ф' => 'a', 'ы' => 's', 'в' => 'd', 'а' => 'f', 'п' => 'g', 'р' => 'h', 'о' => 'j', 'л' => 'k', 'д' => 'l', 'я' => 'z', 'ч' => 'x',
        'с' => 'c', 'м' => 'v', 'и' => 'b', 'т' => 'n', 'ь' => 'm'];

    private const SLANG = ['айфон' => 'iphone', 'айфоны' => 'iphone', 'ифон' => 'iphone', 'самсунг' => 'samsung', 'самсуг' => 'samsung', 'сяоми' => 'xiaomi',
        'ксиоми' => 'xiaomi', 'редми' => 'redmi', 'галакси' => 'galaxy', 'про' => 'pro', 'макс' => 'max', 'плюс' => 'plus', 'мини' => 'mini',
        'ультра' => 'ultra', 'эпл' => 'apple', 'эппл' => 'apple', 'аирподс' => 'airpods', 'эйрподс' => 'airpods', 'айпад' => 'ipad', 'вотч' => 'watch',
        'гб' => '', 'gb' => '', 'тб' => 'tb', 'бу' => 'бу'];

    public static function normalize(string $text): string
    {
        $t = mb_strtolower($text);
        $t = str_replace(['б/у', 'ё'], ['бу', 'е'], $t);
        $t = preg_replace('/(\d+)\s*(гб|gb)\b/u', '$1', $t);
        $t = preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /** @return list<list<string>> tokens of a buyer query, each with alternatives (a Cyrillic word is also read as the Latin layout). */
    public static function tokens(string $query): array
    {
        $out = [];
        foreach (explode(' ', self::normalize($query)) as $w) {
            if ($w === '') {
                continue;
            }
            $alt = [array_key_exists($w, self::SLANG) ? self::SLANG[$w] : $w];
            if (preg_match('/^[а-я]+$/u', $w) && mb_strlen($w) >= 3) {
                $alt[] = strtr($w, self::LAYOUT);
            }
            $alt = array_values(array_unique(array_filter($alt, fn ($a) => $a !== '')));
            if ($alt) {
                $out[] = $alt;
            }
        }

        return $out;
    }

    /** Apply the tokens to a query on search_text (every token must match one of its alternatives). */
    public static function apply($query, string $text): void
    {
        foreach (self::tokens($text) as $alts) {
            $query->where(function ($q) use ($alts) {
                foreach ($alts as $a) {
                    $q->orWhere('search_text', 'like', '%'.addcslashes($a, '%_').'%');
                }
            });
        }
    }
}
