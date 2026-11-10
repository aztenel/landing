<x-filament-panels::page>
@php
    $btn = 'inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-muted';
    $primary = 'inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white';
    $input = 'rounded-lg border-input bg-background text-base';
    $money = fn (?int $t) => $t === null ? '—' : number_format($t, 0, '', ' ').' ₸';
    $shop = (request()->isSecure() ? 'https://' : 'http://').config('prime.shop_domain').(request()->getPort() && ! in_array(request()->getPort(), [80, 443]) ? ':'.request()->getPort() : '');
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-sm font-medium {{ $siteOn ? 'bg-ok/10 text-ok' : 'bg-warn/10 text-warn' }}">
            <x-ui::icon name="globe" class="h-4 w-4" /> {{ $siteOn ? __('Сайт открыт для покупателей') : __('Сайт выключен — покупатели видят «Скоро»') }}</span>
        <a href="{{ $this->previewUrl() }}" target="_blank" class="{{ $btn }} ml-auto"><x-ui::icon name="eye" class="h-4 w-4" />{{ __('Открыть сайт') }}</a>
    </div>

    <div class="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 text-sm" role="tablist">
        @foreach ($tabs as $key => [$label])
            <button type="button" wire:click="$set('tab', '{{ $key }}')" role="tab" class="h-10 shrink-0 px-3 {{ $tab === $key ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ __($label) }}</button>
        @endforeach
    </div>
    @if ($error)<p class="text-sm text-bad" role="alert">{{ $error }}</p>@elseif ($flash)<p class="text-sm text-ok" role="status">{{ $flash }}</p>@endif

    @if ($tab === 'goods')
        <div class="flex flex-wrap items-center gap-2">
            @foreach (['off' => __('Не на сайте'), 'on' => __('На сайте'), 'all' => __('Все')] as $k => $n)
                <button type="button" wire:click="$set('filter', '{{ $k }}')" class="h-9 rounded-full px-3 text-sm {{ $filter === $k ? 'bg-foreground text-background' : 'border border-border bg-card' }}">{{ $n }}</button>
            @endforeach
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Код PM или модель') }}" class="{{ $input }} h-9 w-full sm:w-56">
            <span class="text-sm text-muted-foreground sm:ml-auto">{{ __('На сайте: :n телефонов, :a аксессуаров', ['n' => $counts['on'], 'a' => $counts['accessories']]) }}</span>
        </div>
        @if ($selected)
            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-muted px-3 py-2 text-sm">
                {{ __('Выбрано: :n', ['n' => count($selected)]) }}
                <button type="button" wire:click="bulk(true)" wire:confirm="{{ __('Изменится :n телефонов. Применить?', ['n' => count($selected)]) }}" class="{{ $btn }} h-9">{{ __('Показать на сайте') }}</button>
                <button type="button" wire:click="bulk(false)" wire:confirm="{{ __('Изменится :n телефонов. Применить?', ['n' => count($selected)]) }}" class="{{ $btn }} h-9">{{ __('Скрыть') }}</button>
            </div>
        @endif
        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground"><tr class="border-b border-border"><th class="w-8 px-3 py-2"></th><th class="px-3 py-2">{{ __('Код') }}</th><th class="px-3 py-2">{{ __('Телефон') }}</th><th class="px-3 py-2">{{ __('Сайт') }}</th></tr></thead>
                <tbody class="divide-y divide-border">
                    @forelse ($rows as $r)
                        <tr wire:key="g{{ $r['unit']->id }}">
                            <td class="px-3 py-2"><input type="checkbox" wire:model.live="selected" value="{{ $r['unit']->id }}" class="rounded border-input text-primary"></td>
                            <td class="num px-3 py-2"><a href="{{ route('erp.stock.units.show', ['code' => $r['unit']->public_code]) }}?tab=site" class="text-primary">{{ $r['unit']->public_code }}</a></td>
                            <td class="px-3 py-2">{{ $r['unit']->variant?->title() }} <span class="text-muted-foreground">{{ $r['unit']->condition?->value === 'used' ? __('б/у') : '' }}</span></td>
                            <td class="px-3 py-2">@if ($r['on'])<span class="text-ok">{{ __('на сайте') }}</span>@else<span class="text-warn">{{ collect($r['reasons'])->pluck('text')->implode('; ') }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-6 text-center text-muted-foreground">{{ __('Нет телефонов') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    @elseif ($tab === 'nophoto')
        <div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-2">
            <div class="rounded-xl border border-border bg-card">
                <h3 class="border-b border-border px-4 py-3 font-semibold">{{ __('Б/у без фото') }} <span class="text-muted-foreground">{{ $used->count() }}</span></h3>
                <ul class="divide-y divide-border">
                    @forelse ($used as $u)
                        <li class="flex items-center gap-3 px-4 py-3 text-sm">
                            <span class="min-w-0 flex-1"><span class="num">{{ $u->public_code }}</span> · {{ $u->variant?->title() }}</span>
                            <a href="{{ route('erp.stock.units.show', ['code' => $u->public_code]) }}?tab=site" class="{{ $primary }} h-9"><x-ui::icon name="camera" class="h-4 w-4" />{{ __('Снять') }}</a>
                        </li>
                    @empty
                        <li class="p-6 text-center text-sm text-muted-foreground">{{ __('У всех б/у есть фото') }}</li>
                    @endforelse
                </ul>
            </div>
            <div class="rounded-xl border border-border bg-card">
                <h3 class="border-b border-border px-4 py-3 font-semibold">{{ __('Новые: нет фото модели этого цвета') }}</h3>
                <ul class="divide-y divide-border">
                    @forelse ($variants as $v)
                        <li class="flex items-center gap-3 px-4 py-3 text-sm" wire:key="v{{ $v->id }}">
                            <span class="min-w-0 flex-1">{{ $v->title() }} <span class="text-muted-foreground">· {{ $newCount[$v->id] ?? 0 }} {{ __('шт.') }}</span></span>
                            <label class="{{ $btn }} h-9 cursor-pointer" wire:click="$set('modelPhotoFor', {{ $v->id }})"><x-ui::icon name="image" class="h-4 w-4" />{{ __('Фото модели') }}
                                <input type="file" accept="image/*" multiple wire:model="modelPhoto" class="sr-only"></label>
                        </li>
                    @empty
                        <li class="p-6 text-center text-sm text-muted-foreground">{{ __('У всех новых есть фото модели') }}</li>
                    @endforelse
                </ul>
                <div wire:loading wire:target="modelPhoto" class="px-4 pb-3 text-sm text-muted-foreground">{{ __('Загружаем и обрабатываем фото…') }}</div>
            </div>
        </div>

    @elseif ($tab === 'review')
        <p class="text-sm text-muted-foreground">{{ __('Фото продавцов к телефонам, которые уже на сайте: опубликованы сразу, проверьте и при необходимости уберите.') }}</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 desk:grid-cols-5">
            @forelse ($photos as $p)
                <div class="overflow-hidden rounded-xl border border-border bg-card" wire:key="r{{ $p->id }}">
                    @if ($p->url('md'))<img src="{{ $p->url('md') }}" alt="" class="aspect-square w-full object-cover">@endif
                    <div class="space-y-2 p-2 text-xs">
                        <p><span class="num">{{ $units[$p->stock_unit_id]->public_code ?? '' }}</span> · {{ $uploaders[$p->uploaded_by] ?? '' }}</p>
                        @if (in_array('dark', (array) $p->flags, true))<p class="text-warn">{{ __('Фото тёмное') }}</p>@endif
                        <div class="grid grid-cols-2 gap-1"><button type="button" wire:click="review({{ $p->id }}, true)" class="h-8 rounded bg-ok/10 font-medium text-ok">{{ __('Оставить') }}</button><button type="button" wire:click="review({{ $p->id }}, false)" class="h-8 rounded bg-bad/10 font-medium text-bad">{{ __('Убрать') }}</button></div>
                    </div>
                </div>
            @empty
                <p class="col-span-full rounded-xl border border-border bg-card p-6 text-center text-sm text-muted-foreground">{{ __('Новых фото на проверку нет') }}</p>
            @endforelse
        </div>

    @elseif ($tab === 'leads')
        <div class="flex flex-wrap items-center gap-2">
            @foreach (['in_work' => __('В работе').' '.$leadCounts['in_work'], 'clicked' => __('Нажатые').' '.$leadCounts['clicked'], 'closed' => __('Закрытые')] as $k => $n)
                <button type="button" wire:click="$set('leadTab', '{{ $k }}')" class="h-9 rounded-full px-3 text-sm {{ $leadTab === $k ? 'bg-foreground text-background' : 'border border-border bg-card' }}">{{ $n }}</button>
            @endforeach
            <form wire:submit="findLead" class="flex gap-2 sm:ml-auto"><input type="search" wire:model="search" placeholder="СЗ-1001" class="{{ $input }} h-9 w-36"><button class="{{ $btn }} h-9">{{ __('Найти') }}</button></form>
        </div>
        <div class="grid grid-cols-[minmax(0,1fr)] gap-4 {{ $lead ? 'desk:grid-cols-[minmax(0,1fr)_380px]' : '' }}">
            <div class="overflow-x-auto rounded-xl border border-border bg-card">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground"><tr class="border-b border-border"><th class="px-3 py-2">{{ __('Номер') }}</th><th class="px-3 py-2">{{ __('Тип') }}</th><th class="px-3 py-2">{{ __('Товар') }}</th><th class="px-3 py-2">{{ __('Время') }}</th><th class="px-3 py-2">{{ __('Кто взял') }}</th></tr></thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($leads as $l)
                            <tr wire:click="openLead({{ $l->id }})" class="cursor-pointer hover:bg-muted {{ $lead?->id === $l->id ? 'bg-muted' : '' }}" wire:key="l{{ $l->id }}">
                                <td class="num px-3 py-2 font-medium">{{ $l->number }}</td>
                                <td class="px-3 py-2">{{ __(\App\Domain\Storefront\Models\Lead::TYPES[$l->type] ?? $l->type) }}</td>
                                <td class="px-3 py-2"><span class="num">{{ $l->public_code }}</span> {{ $leadUnits[$l->public_code]?->variant?->title() }}</td>
                                <td class="num px-3 py-2 text-muted-foreground">{{ $l->created_at->setTimezone($tz)->format('d.m H:i') }}</td>
                                <td class="px-3 py-2">{{ $names[$l->taken_by] ?? '' }}@if ($l->outcome) · {{ __(\App\Domain\Storefront\Models\Lead::OUTCOMES[$l->outcome]) }}@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-6 text-center text-muted-foreground">{{ __('Заявок нет') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($lead)
                @php($u = $lead->public_code ? ($leadUnits[$lead->public_code] ?? null) : null)
                <div class="space-y-3 rounded-xl border border-border bg-card p-4 text-sm">
                    <div class="flex items-center gap-2"><h3 class="num mr-auto text-lg font-semibold">{{ $lead->number }}</h3><button type="button" wire:click="$set('leadId', null)" class="grid h-8 w-8 place-items-center rounded hover:bg-muted"><x-ui::icon name="x" class="h-4 w-4" /></button></div>
                    <p>{{ __(\App\Domain\Storefront\Models\Lead::TYPES[$lead->type] ?? $lead->type) }} · {{ $lead->lang === 'kk' ? 'KZ' : 'RU' }} · {{ $lead->source }} · {{ $lead->created_at->setTimezone($tz)->format('d.m.Y H:i') }}</p>
                    @if ($u)
                        <div class="rounded-lg bg-muted p-3">
                            <p class="font-medium"><a href="{{ route('erp.stock.units.show', ['code' => $u->public_code]) }}" class="num text-primary">{{ $u->public_code }}</a> {{ $u->variant?->title() }}</p>
                            <p>{{ __('Сейчас') }}: <b>{{ \App\Domain\Inventory\Enums\UnitStatus::labelOf($u->status) }}</b></p>
                            <p>{{ __('Цена тогда') }}: {{ $money($lead->price) }} · {{ __('сейчас') }}: {{ $money(intdiv(\App\Domain\Storefront\Services\Visibility::price($u), 100)) }}</p>
                        </div>
                    @endif
                    @if ($lead->payload)
                        <dl class="grid grid-cols-[110px_minmax(0,1fr)] gap-1">@foreach ($lead->payload as $k => $v)<dt class="text-muted-foreground">{{ $k }}</dt><dd>{{ is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v }}</dd>@endforeach</dl>
                    @endif
                    @if ($lead->status !== 'closed')
                        <div class="flex flex-wrap gap-2">
                            @if ($lead->status === 'clicked')<button type="button" wire:click="takeLead" class="{{ $primary }}">{{ __('Взять в работу') }}</button>@endif
                            @if ($u && $u->status?->value === 'in_stock')
                                <a href="{{ \App\Filament\Sales\Pages\Orders::getUrl() }}?unit={{ $u->public_code }}" class="{{ $btn }}">{{ __('Забронировать') }}</a>
                                <a href="{{ \App\Filament\Pos\Pages\Sale::getUrl() }}?unit={{ $u->public_code }}" class="{{ $btn }}">{{ __('Продать') }}</a>
                            @endif
                            @if ($lead->type === 'tradein')<a href="{{ \App\Filament\TradeIn\Pages\NewAct::getUrl() }}" class="{{ $btn }}">Trade-in</a>@endif
                            @if ($lead->public_code)<a href="{{ $shop }}/phone/{{ $lead->public_code }}" target="_blank" class="{{ $btn }}">{{ __('Похожие') }}</a>@endif
                        </div>
                        <div class="space-y-2 border-t border-border pt-3">
                            <select wire:model="close.outcome" class="{{ $input }} w-full"><option value="">{{ __('Итог…') }}</option>@foreach (\App\Domain\Storefront\Models\Lead::OUTCOMES as $k => $n)<option value="{{ $k }}">{{ __($n) }}</option>@endforeach</select>
                            <input type="text" wire:model="close.comment" placeholder="{{ __('Комментарий') }}" class="{{ $input }} w-full">
                            <button type="button" wire:click="closeLead" class="{{ $btn }} w-full justify-center">{{ __('Закрыть заявку') }}</button>
                        </div>
                    @else
                        <p class="text-muted-foreground">{{ __('Закрыта') }}: {{ __(\App\Domain\Storefront\Models\Lead::OUTCOMES[$lead->outcome] ?? '') }} {{ $lead->comment }}</p>
                    @endif
                </div>
            @endif
        </div>

    @elseif ($tab === 'rules')
        <div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-3">
            @foreach ([['type', __('Категории'), $types, fn ($t) => $t->name_ru], ['family', __('Бренды / семейства'), $families, fn ($f) => trim($f->brand.' '.$f->name)], ['model', __('Модели в наличии'), $models, fn ($m) => $m->name_ru]] as [$kind, $title, $list, $name])
                <div class="rounded-xl border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 font-semibold">{{ $title }}</h3>
                    <ul class="max-h-[420px] divide-y divide-border overflow-y-auto">
                        @foreach ($list as $item)
                            <li class="flex items-center gap-2 px-4 py-2 text-sm" wire:key="{{ $kind.$item->id }}">
                                <span class="min-w-0 flex-1 truncate {{ $item->site_hidden ? 'text-muted-foreground line-through' : '' }}">{{ $name($item) }}</span>
                                @if ($item->site_hidden)
                                    <button type="button" wire:click="setHidden('{{ $kind }}', {{ $item->id }}, false)" class="{{ $btn }} h-8">{{ __('Показать') }}</button>
                                @elseif ($confirm === $kind.':'.$item->id)
                                    <button type="button" wire:click="setHidden('{{ $kind }}', {{ $item->id }}, true)" class="h-8 rounded-lg bg-bad px-3 text-xs font-semibold text-white">{{ __('Скрыть? Уйдут :n', ['n' => $this->affected($kind, $item->id)]) }}</button>
                                @else
                                    <button type="button" wire:click="askHide('{{ $kind }}', {{ $item->id }})" class="{{ $btn }} h-8">{{ __('Скрыть') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
        <div class="space-y-3 rounded-xl border border-border bg-card p-4">
            <h3 class="font-semibold">{{ __('Магазины на сайте') }}</h3>
            @foreach ($stores as $st)
                <div class="grid grid-cols-[minmax(0,1fr)] gap-2 border-t border-border pt-3 text-sm sm:grid-cols-2 desk:grid-cols-4" wire:key="st{{ $st->id }}">
                    <p class="font-medium sm:col-span-2 desk:col-span-4">{{ $st->name }} · {{ $st->address }}</p>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="storesForm.{{ $st->id }}.shown" class="rounded border-input text-primary">{{ __('Показывать на сайте') }}</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="storesForm.{{ $st->id }}.new" class="rounded border-input text-primary">{{ __('Показывать новые') }}</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="storesForm.{{ $st->id }}.used" class="rounded border-input text-primary">{{ __('Показывать б/у') }}</label>
                    <span></span>
                    <label>WhatsApp<input type="text" wire:model="storesForm.{{ $st->id }}.whatsapp" placeholder="+7 701 …" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Часы работы') }}<input type="text" wire:model="storesForm.{{ $st->id }}.hours" placeholder="{{ __('Ежедневно 10:00–21:00') }}" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Ссылка 2ГИС') }}<input type="url" wire:model="storesForm.{{ $st->id }}.map_url" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Город') }}<input type="text" wire:model="storesForm.{{ $st->id }}.city" class="{{ $input }} mt-1 w-full"></label>
                    <label class="sm:col-span-2 desk:col-span-4">{{ __('Адрес на казахском') }}<input type="text" wire:model="storesForm.{{ $st->id }}.address_kk" class="{{ $input }} mt-1 w-full"></label>
                </div>
            @endforeach
            <button type="button" wire:click="saveStores" class="{{ $primary }}">{{ __('Сохранить магазины') }}</button>
        </div>

    @elseif ($tab === 'pages')
        @if ($pageSlug)
            <div class="space-y-3 rounded-xl border border-border bg-card p-4">
                <div class="grid grid-cols-[minmax(0,1fr)] gap-3 desk:grid-cols-2">
                    <label class="text-sm">{{ __('Заголовок (рус)') }}<input type="text" wire:model="pageForm.title_ru" class="{{ $input }} mt-1 w-full"></label>
                    <label class="text-sm">{{ __('Тақырып (қаз)') }}<input type="text" wire:model="pageForm.title_kk" class="{{ $input }} mt-1 w-full"></label>
                    <label class="text-sm">{{ __('Текст (рус)') }}<textarea wire:model="pageForm.body_ru" rows="14" class="{{ $input }} mt-1 w-full font-mono text-sm"></textarea></label>
                    <label class="text-sm">{{ __('Мәтін (қаз)') }}<textarea wire:model="pageForm.body_kk" rows="14" class="{{ $input }} mt-1 w-full font-mono text-sm"></textarea></label>
                </div>
                <p class="text-xs text-muted-foreground">{{ __('Формат: «## Заголовок», «- пункт списка», **жирный**, [ссылка](https://…), пустая строка — новый абзац.') }}</p>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="pageForm.is_draft" class="rounded border-input text-primary">{{ __('Черновик (текст ещё не проверен юристом)') }}</label>
                <div class="flex gap-2"><button type="button" wire:click="savePage" class="{{ $primary }}">{{ __('Сохранить') }}</button><button type="button" wire:click="$set('pageSlug', null)" class="{{ $btn }}">{{ __('Отмена') }}</button></div>
            </div>
        @else
            <ul class="divide-y divide-border rounded-xl border border-border bg-card">
                @foreach (\App\Domain\Storefront\Services\SitePages::DEFAULTS as $slug => $d)
                    <li class="flex items-center gap-3 px-4 py-3 text-sm">
                        <span class="flex-1 font-medium">{{ $pages[$slug]->title_ru ?? __($d['title']) }}</span>
                        @if (! isset($pages[$slug]) || $pages[$slug]->is_draft)<span class="rounded bg-warn/10 px-2 py-0.5 text-xs text-warn">{{ __('черновик') }}</span>@endif
                        @if (! isset($pages[$slug]) || ! $pages[$slug]->body_kk)<span class="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ __('нет перевода') }}</span>@endif
                        <button type="button" wire:click="editPage('{{ $slug }}')" class="{{ $btn }} h-9">{{ __('Изменить') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif

    @elseif ($tab === 'banners')
        @if ($banner)
            <div class="space-y-3 rounded-xl border border-border bg-card p-4 text-sm">
                <div class="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2">
                    <label>{{ __('Шаблон') }}<select wire:model="banner.template" class="{{ $input }} mt-1 w-full"><option value="new">{{ __('Новинка') }}</option><option value="tradein">Trade-in</option><option value="used">{{ __('Б/у с гарантией') }}</option><option value="promo">{{ __('Акция') }}</option></select></label>
                    <label>{{ __('Ссылка на странице сайта') }}<input type="text" wire:model="banner.link" placeholder="/catalog/smartfony" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Заголовок (рус), до 40') }}<input type="text" maxlength="40" wire:model="banner.title_ru" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Тақырып (қаз)') }}<input type="text" maxlength="40" wire:model="banner.title_kk" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Подзаголовок (рус), до 80') }}<input type="text" maxlength="80" wire:model="banner.subtitle_ru" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Подзаголовок (қаз)') }}<input type="text" maxlength="80" wire:model="banner.subtitle_kk" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Кнопка (рус), до 20') }}<input type="text" maxlength="20" wire:model="banner.button_ru" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Кнопка (қаз)') }}<input type="text" maxlength="20" wire:model="banner.button_kk" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Цена «от» по модели (авто)') }}<select wire:model="banner.price_model_ref" class="{{ $input }} mt-1 w-full"><option value="">{{ __('без цены') }}</option>@foreach ($models as $m)<option value="{{ $m->id }}">{{ $m->name_ru }}</option>@endforeach</select></label>
                    <label>{{ __('Порядок') }}<input type="number" wire:model="banner.sort" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Показывать с') }}<input type="date" wire:model="banner.starts_at" class="{{ $input }} mt-1 w-full"></label>
                    <label>{{ __('Показывать по') }}<input type="date" wire:model="banner.ends_at" class="{{ $input }} mt-1 w-full"></label>
                </div>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="banner.is_active" class="rounded border-input text-primary">{{ __('Активен') }}</label>
                <div class="flex gap-2"><button type="button" wire:click="saveBanner" class="{{ $primary }}">{{ __('Сохранить') }}</button><button type="button" wire:click="$set('banner', null)" class="{{ $btn }}">{{ __('Отмена') }}</button></div>
            </div>
        @else
            <button type="button" wire:click="editBanner" class="{{ $primary }}"><x-ui::icon name="plus" class="h-4 w-4" />{{ __('Баннер') }}</button>
            <ul class="divide-y divide-border rounded-xl border border-border bg-card">
                @forelse ($banners as $b)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                        <span class="min-w-0 flex-1"><b>{{ $b->title_ru }}</b> <span class="text-muted-foreground">{{ $b->subtitle_ru }} · {{ $b->link }}</span></span>
                        @unless ($b->is_active)<span class="rounded bg-muted px-2 py-0.5 text-xs">{{ __('выключен') }}</span>@endunless
                        @unless ($b->title_kk)<span class="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ __('нет перевода') }}</span>@endunless
                        <button type="button" wire:click="editBanner({{ $b->id }})" class="{{ $btn }} h-9">{{ __('Изменить') }}</button>
                        <button type="button" wire:click="removeBanner({{ $b->id }})" wire:confirm="{{ __('Удалить баннер?') }}" class="{{ $btn }} h-9 text-bad">{{ __('Удалить') }}</button>
                    </li>
                @empty
                    <li class="p-6 text-center text-sm text-muted-foreground">{{ __('Баннеров нет') }}</li>
                @endforelse
            </ul>
        @endif

    @elseif ($tab === 'settings')
        <div class="space-y-4 rounded-xl border border-border bg-card p-4 text-sm">
            <label class="flex items-center gap-2 text-base font-semibold"><input type="checkbox" wire:model="settings.enabled" class="rounded border-input text-primary">{{ __('Сайт включён (открыт для покупателей)') }}</label>
            <div class="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-3">
                <label>{{ __('Город (рус)') }}<input type="text" wire:model="settings.city_ru" class="{{ $input }} mt-1 w-full"></label>
                <label>{{ __('Қала (қаз)') }}<input type="text" wire:model="settings.city_kk" class="{{ $input }} mt-1 w-full"></label>
                <label>Instagram<input type="text" wire:model="settings.instagram" placeholder="@prime.mobile" class="{{ $input }} mt-1 w-full"></label>
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="settings.photo_review" class="rounded border-input text-primary">{{ __('Фото продавцов к телефонам на сайте — в «Новые фото» на проверку') }}</label>
            <h3 class="pt-2 font-semibold">{{ __('Рассрочка на сайте') }}</h3>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="settings.installment.enabled" class="rounded border-input text-primary">{{ __('Показывать «от … × N мес»') }}</label>
            <div class="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-3">
                <label>{{ __('Название (рус)') }}<input type="text" wire:model="settings.installment.title_ru" class="{{ $input }} mt-1 w-full"></label>
                <label>{{ __('Атауы (қаз)') }}<input type="text" wire:model="settings.installment.title_kk" class="{{ $input }} mt-1 w-full"></label>
                <label>{{ __('Сроки, мес') }}<input type="text" wire:model="settings.installment.months" class="{{ $input }} mt-1 w-full"></label>
                <label>{{ __('Переплата покупателя, % по срокам') }}<input type="text" wire:model="settings.installment.markup" class="{{ $input }} mt-1 w-full"></label>
                <label>{{ __('Мин. сумма, ₸') }}<input type="text" wire:model="settings.installment.min" class="{{ $input }} mt-1 w-full"></label>
                <label>{{ __('Макс. сумма, ₸') }}<input type="text" wire:model="settings.installment.max" class="{{ $input }} mt-1 w-full"></label>
            </div>
            <p class="text-xs text-muted-foreground">{{ __('Условия вводит владелец по договорам с банками. Комиссия магазину здесь не вводится и на сайт не попадает.') }}</p>
            <h3 class="pt-2 font-semibold">{{ __('Грейды б/у') }}</h3>
            @foreach (['A', 'B', 'C'] as $g)
                <div class="grid grid-cols-[minmax(0,1fr)] gap-2 sm:grid-cols-[40px_1fr_1fr_2fr]">
                    <span class="font-semibold sm:pt-2">{{ $g }}</span>
                    <input type="text" wire:model="settings.grades.{{ $g }}.ru" class="{{ $input }}" placeholder="рус">
                    <input type="text" wire:model="settings.grades.{{ $g }}.kk" class="{{ $input }}" placeholder="қаз">
                    <input type="text" wire:model="settings.grades.{{ $g }}.text_ru" class="{{ $input }}" placeholder="{{ __('Пояснение') }}">
                </div>
            @endforeach
            <h3 class="pt-2 font-semibold">{{ __('Шаблоны WhatsApp') }}</h3>
            <p class="text-xs text-muted-foreground">{{ __('Переменные: {название} {код} {цена} {ссылка} {заявка} {срок} {когда} {запрос} {модель} {память} {экран} {батарея} {товар} {оценка}. Пустой казахский — берётся русский.') }}</p>
            @foreach (['ask' => __('Написать (товар)'), 'installment' => __('Купить в рассрочку'), 'book' => __('Забронировать'), 'similar' => __('Похожий'), 'to_order' => __('Под заказ'), 'tradein' => 'Trade-in', 'general' => __('Общий (шапка)')] as $k => $n)
                <div class="grid grid-cols-[minmax(0,1fr)] gap-2 desk:grid-cols-[160px_1fr_1fr]">
                    <span class="font-medium desk:pt-2">{{ $n }}</span>
                    <textarea wire:model="settings.templates.{{ $k }}_ru" rows="2" class="{{ $input }} text-sm"></textarea>
                    <textarea wire:model="settings.templates.{{ $k }}_kk" rows="2" class="{{ $input }} text-sm" placeholder="қаз"></textarea>
                </div>
            @endforeach
            <button type="button" wire:click="saveSettings" class="{{ $primary }}">{{ __('Сохранить настройки') }}</button>
        </div>

    @elseif ($tab === 'state')
        <div class="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-sm text-muted-foreground">{{ __('Обновление') }}</p>
                <p class="mt-1 text-lg font-semibold {{ $lagSeconds > 300 ? 'text-bad' : 'text-ok' }}">{{ $lagSeconds > 0 ? __('Отстаёт на :t', ['t' => $lagSeconds >= 60 ? intdiv($lagSeconds, 60).' '.__('мин') : $lagSeconds.' '.__('сек')]) : __('Сайт работает') }}</p>
                <p class="text-sm text-muted-foreground">{{ __('Последнее обновление') }}: {{ $synced?->setTimezone($tz)->format('d.m H:i:s') ?? '—' }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-sm text-muted-foreground">{{ __('На сайте сейчас') }}</p>
                <p class="mt-1 text-lg font-semibold">{{ ($onSite['new'] ?? 0) + ($onSite['used'] ?? 0) }} {{ __('телефонов') }}</p>
                <p class="text-sm text-muted-foreground">{{ __(':n новых, :u б/у, :a аксессуаров', ['n' => $onSite['new'] ?? 0, 'u' => $onSite['used'] ?? 0, 'a' => $accessories]) }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-sm text-muted-foreground">{{ __('Очередь') }}</p>
                <p class="mt-1 text-lg font-semibold">{{ $pending }} {{ __('событий') }}</p>
                <p class="text-sm {{ $failed ? 'text-bad' : 'text-muted-foreground' }}">{{ __('С ошибкой: :n', ['n' => $failed]) }}</p>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card">
            <h3 class="border-b border-border px-4 py-3 font-semibold">{{ __('Ночные сверки и пересборки') }}</h3>
            <ul class="divide-y divide-border text-sm">
                @forelse ($runs as $r)
                    <li class="flex gap-3 px-4 py-2"><span class="num w-32 text-muted-foreground">{{ \Carbon\Carbon::parse($r->created_at)->setTimezone($tz)->format('d.m H:i') }}</span><span class="flex-1">{{ $r->kind === 'reconcile' ? __('Ночная сверка') : __('Пересборка') }}</span><span class="{{ $r->fixed ? 'text-warn' : 'text-ok' }}">{{ __('расхождений :n', ['n' => $r->fixed]) }}</span></li>
                @empty
                    <li class="p-6 text-center text-muted-foreground">{{ __('Сверок ещё не было') }}</li>
                @endforelse
            </ul>
        </div>
        <button type="button" wire:click="rebuild" wire:confirm="{{ __('Пересобрать сайт целиком? В обычной работе это не нужно.') }}" class="{{ $btn }}"><x-ui::icon name="refresh" class="h-4 w-4" />{{ __('Пересобрать сайт целиком') }}</button>

    @elseif ($tab === 'stats')
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([[__('Просмотров'), $views], ['WhatsApp', $clicks], [__('В работе'), $inWork], [__('Купили'), $bought]] as [$n, $v])
                <div class="rounded-xl border border-border bg-card p-4"><p class="text-sm text-muted-foreground">{{ $n }} · 7 {{ __('дн.') }}</p><p class="num mt-1 text-2xl font-semibold">{{ $v }}</p></div>
            @endforeach
        </div>
        <div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-3">
            @foreach ([[__('Больше всего смотрят'), $top], [__('Ищут и не находят'), $misses], [__('Смотрят, но не пишут (14 дн.)'), $silent]] as [$title, $list])
                <div class="rounded-xl border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 font-semibold">{{ $title }}</h3>
                    <ul class="divide-y divide-border text-sm">
                        @forelse ($list as $r)
                            <li class="flex gap-3 px-4 py-2"><span class="num flex-1 truncate">{{ $r->key }}</span><span class="num text-muted-foreground">{{ $r->n }}</span></li>
                        @empty
                            <li class="p-4 text-center text-muted-foreground">—</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</div>
</x-filament-panels::page>
