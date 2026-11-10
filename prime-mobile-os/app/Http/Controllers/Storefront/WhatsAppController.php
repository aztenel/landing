<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Models\SiteAccessory;
use App\Domain\Storefront\Services\Leads;
use App\Http\Storefront\Site;
use Illuminate\Http\Request;

/**
 * /go/wa (TZ 05 §10.1): the click creates an anonymous request СЗ-… (§11) and goes straight on to wa.me of the store
 * where the item lies, with the text of the template in the page language. Works without JavaScript. Nothing personal
 * is stored: no IP, no browser (§11.2).
 */
class WhatsAppController
{
    public function __invoke(Request $r, Leads $leads)
    {
        $type = (string) $r->query('t', 'general');
        $code = mb_strtoupper((string) $r->query('code'));
        $listing = preg_match('/^PM-\d+$/', $code) ? Listing::query()->where('public_code', $code)->first() : null;
        $item = ! $listing && preg_match('/^PA-\d+$/', $code) ? SiteAccessory::query()->where('public_code', $code)->first() : null;
        $store = Site::store($listing?->store_ref);
        if (! in_array($type, ['ask', 'installment', 'book', 'similar', 'to_order', 'tradein', 'general'], true)) {
            $type = 'general';
        }
        $months = (int) $r->query('m') ?: (int) Site::settings()->get('installment.months.'.(count((array) Site::settings()->get('installment.months')) - 1), 24);
        $query = mb_substr(trim((string) $r->query('q')), 0, 120);
        $tradein = $type === 'tradein' ? array_map(fn ($v) => mb_substr(trim((string) $v), 0, 60), $r->only(['model', 'memory', 'screen', 'body', 'battery', 'faceid', 'repair', 'box'])) : [];
        $title = $listing?->title(Site::$lang) ?? $item?->{'title_'.Site::$lang} ?? null;
        $price = $listing?->public_status === 'sold' ? null : ($listing?->price ?? $item?->price);
        $link = $listing ? $r->getSchemeAndHttpHost().'/'.$listing->public_code : ($item ? $r->getSchemeAndHttpHost().Site::route('item', ['code' => $item->public_code]) : '');

        $number = '';
        if ($type !== 'general') {
            $lead = $leads->create($type, $listing?->public_code ?? $item?->public_code, array_filter(['months' => $type === 'installment' ? $months : null, 'query' => $query ?: null] + $tradein),
                $store?->store_ref, Site::$lang, $this->source($r), mb_substr((string) parse_url((string) $r->headers->get('referer'), PHP_URL_PATH), 0, 200) ?: null, $price);
            $number = $lead->number;
        }
        $wantCode = mb_strtoupper((string) $r->query('want'));
        $text = strtr(Site::settings()->template($type, Site::$lang), [
            '{название}' => (string) $title, '{код}' => (string) ($listing?->public_code ?? $item?->public_code), '{цена}' => Site::money($price),
            '{ссылка}' => $link, '{заявка}' => $number, '{срок}' => (string) $months, '{когда}' => '', '{запрос}' => $query,
            '{модель}' => $tradein['model'] ?? '', '{память}' => $tradein['memory'] ?? '', '{экран}' => $tradein['screen'] ?? '', '{батарея}' => $tradein['battery'] ?? '',
            '{товар}' => preg_match('/^PM-\d+$/', $wantCode) ? $wantCode : Site::t('пока не знаю'), '{оценка}' => Site::t('Оценим в WhatsApp за 5 минут'),
        ]);
        $text = trim(preg_replace('/\s{2,}/u', ' ', $text));

        return redirect()->away(Site::wa($store?->whatsapp).'?text='.rawurlencode($text));
    }

    /** «прямой / Google / Instagram» by the address of the jump, or utm_source (§11.2). */
    private function source(Request $r): string
    {
        if ($utm = $r->query('utm_source')) {
            return 'utm:'.mb_substr((string) $utm, 0, 30);
        }
        $host = (string) parse_url((string) $r->headers->get('referer'), PHP_URL_HOST);

        return match (true) {
            $host === '' || $host === $r->getHost() => 'site',
            str_contains($host, 'google') => 'google',
            str_contains($host, 'instagram') => 'instagram',
            str_contains($host, 'yandex') => 'yandex',
            str_contains($host, '2gis') => '2gis',
            default => 'other',
        };
    }
}
