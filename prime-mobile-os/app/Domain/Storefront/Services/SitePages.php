<?php

namespace App\Domain\Storefront\Services;

/**
 * Texts of the site pages (TZ 05 §12.3): Trade-in, Рассрочка, Гарантия/обмен/возврат, О магазине, Политика. A simple
 * editor format: «## Заголовок», «- пункт», **жирный**, [ссылка](https://…), empty line = new paragraph. The defaults
 * are DRAFTS for the lawyer (§9.11, §9.12 — сроки возврата для онлайн-продаж не проверены).
 */
final class SitePages
{
    public const DEFAULTS = [
        'trade-in' => ['title' => 'Trade-in', 'body' => "Сдайте старый телефон — его стоимость зачтём в покупку нового. Доплачиваете только разницу.\n\n## Как это работает\n- Заполните форму ниже и отправьте её в WhatsApp.\n- Мы ответим с ориентировочной ценой.\n- Приходите в магазин: осмотр занимает 10–15 минут, точная цена — после осмотра."],
        'rassrochka' => ['title' => 'Рассрочка', 'body' => "Покупайте телефоны в рассрочку без переплаты для покупателя. Условия — по программе банка.\n\nНапишите нам в WhatsApp — оформим в магазине за 15 минут."],
        'garantiya' => ['title' => 'Гарантия, обмен и возврат', 'body' => "**Черновик для юриста.**\n\n## Гарантия\n- Новые телефоны — гарантия магазина 12 месяцев.\n- Б/у телефоны — гарантия магазина 1 месяц.\n- Аксессуары — 1 месяц.\n\n## Обмен и возврат\nВозврат и обмен — по закону Республики Казахстан «О защите прав потребителей». Сохраните чек и гарантийный талон.\n\nСроки возврата для покупок, оформленных через сайт, — уточняются (не проверено)."],
        'privacy' => ['title' => 'Политика конфиденциальности', 'body' => "**Черновик. Текст даёт юрист.**\n\nСайт не собирает имена, телефоны и другие персональные данные. Заявки с сайта анонимны: связь с вами появляется только в WhatsApp, когда вы сами нам пишете.\n\nСайт хранит только язык страницы (cookie) и обезличенные счётчики просмотров без IP-адресов."],
        'about' => ['title' => 'О магазине', 'body' => ''],
    ];

    public static function render(?string $text): string
    {
        $out = [];
        $list = [];
        $flush = function () use (&$list, &$out) {
            if ($list) {
                $out[] = '<ul>'.implode('', array_map(fn ($i) => '<li>'.$i.'</li>', $list)).'</ul>';
                $list = [];
            }
        };
        foreach (preg_split("/\n{2,}|\r\n\r\n/", trim((string) $text)) as $block) {
            foreach (preg_split("/\r?\n/", $block) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, '- ')) {
                    $list[] = self::inline(substr($line, 2));

                    continue;
                }
                $flush();
                $out[] = str_starts_with($line, '## ') ? '<h2>'.self::inline(substr($line, 3)).'</h2>' : '<p>'.self::inline($line).'</p>';
            }
            $flush();
        }

        return implode("\n", $out);
    }

    private static function inline(string $s): string
    {
        $s = e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s);

        return preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/u', fn ($m) => '<a href="'.$m[2].'" class="text-primary underline" rel="noopener">'.$m[1].'</a>', $s);
    }
}
