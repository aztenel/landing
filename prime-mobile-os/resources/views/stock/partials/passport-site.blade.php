@php
    $siteUrl = fn (string $path) => (request()->isSecure() ? 'https://' : 'http://').config('prime.shop_domain').(request()->getPort() && ! in_array(request()->getPort(), [80, 443]) ? ':'.request()->getPort() : '').$path;
    $first = $sitePhotos->first();
@endphp
<div class="space-y-4 p-4">
    {{-- status line (§3.2 п. 7) --}}
    @if ($listing && $listing->public_status !== 'sold')
        <div class="flex flex-wrap items-center gap-2 rounded-lg bg-ok/10 px-3 py-2 text-sm text-ok">
            <x-ui::icon name="globe" class="h-4 w-4" />
            <span class="font-medium">{{ __('На сайте с :t', ['t' => $listing->published_at->setTimezone($tz)->format('d.m H:i')]) }}</span>
            <a href="{{ $siteUrl('/phone/'.$unit->public_code) }}" target="_blank" class="ml-auto font-semibold underline">{{ __('Открыть') }}</a>
        </div>
    @else
        <div class="rounded-lg bg-warn/10 px-3 py-2 text-sm text-warn">
            <p class="font-medium">{{ __('Не на сайте') }}:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($siteReasons as $r)
                    <li>{{ $r['text'] }}@if ($r['code'] === 'price' && $can['price']) — <button type="button" wire:click="$set('modal', 'price')" class="underline">{{ __('Указать') }}</button>@endif</li>
                @endforeach
            </ul>
        </div>
    @endif
    @unless ($siteOn)<p class="text-xs text-muted-foreground">{{ __('Сайт пока выключен в настройках — покупатели его не видят, сотрудники открывают в режиме предпросмотра.') }}</p>@endunless

    {{-- photos (§3.2) --}}
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="mr-auto font-semibold">{{ __('Фото для сайта') }} <span class="text-muted-foreground">{{ $sitePhotos->count() }} / 12</span></h3>
            @if ($siteCan['photo'])
                <label class="inline-flex h-12 cursor-pointer items-center gap-2 rounded-xl bg-primary px-5 text-base font-semibold text-white">
                    <x-ui::icon name="camera" class="h-5 w-5" /> {{ __('Добавить фото') }}
                    <input type="file" accept="image/*" multiple wire:model="uploads" class="sr-only">
                </label>
            @endif
        </div>
        <div wire:loading wire:target="uploads" class="mt-2 text-sm text-muted-foreground">{{ __('Загружаем и обрабатываем фото…') }}</div>
        <p class="mt-1 text-xs text-muted-foreground">{{ __('Порядок: 1 — перед (экран выключен), 2 — зад, 3–4 — бока, 5 — экран включён, 6 — дефект крупно. IMEI на фото не снимайте.') }}</p>
        @if ($sitePhotos->isNotEmpty())
            <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4 desk:grid-cols-6">
                @foreach ($sitePhotos as $i => $p)
                    <div class="relative overflow-hidden rounded-xl border border-border bg-muted" wire:key="ph{{ $p->id }}">
                        @if ($p->status === 'ready')
                            <img src="{{ $p->url('sm') }}" alt="" class="aspect-square w-full object-cover">
                        @else
                            <div class="grid aspect-square place-items-center text-xs text-muted-foreground">{{ $p->status === 'failed' ? __('Ошибка') : __('обрабатывается') }}</div>
                        @endif
                        @if ($i === 0)<span class="absolute left-1 top-1 rounded bg-primary px-1.5 py-0.5 text-[11px] font-semibold text-white">{{ __('Главное') }}</span>@endif
                        @if ($p->needs_review)<span class="absolute right-1 top-1 rounded bg-warn px-1.5 py-0.5 text-[11px] font-semibold text-white">{{ __('на проверке') }}</span>@endif
                        @if (in_array('dark', (array) $p->flags, true))<span class="absolute bottom-9 left-1 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white">{{ __('тёмное') }}</span>@endif
                        @if ($siteCan['photo'])
                            <div class="flex items-center justify-between bg-card px-1 py-1">
                                <button type="button" wire:click="photoMove({{ $p->id }}, -1)" class="grid h-8 w-8 place-items-center rounded hover:bg-muted" aria-label="{{ __('Левее') }}" @disabled($i === 0)><x-ui::icon name="chev-left" class="h-4 w-4" /></button>
                                <button type="button" wire:click="photoRemove({{ $p->id }})" wire:confirm="{{ __('Удалить фото?') }}" class="grid h-8 w-8 place-items-center rounded text-bad hover:bg-bad/10" aria-label="{{ __('Удалить') }}"><x-ui::icon name="trash" class="h-4 w-4" /></button>
                                <button type="button" wire:click="photoMove({{ $p->id }}, 1)" class="grid h-8 w-8 place-items-center rounded hover:bg-muted" aria-label="{{ __('Правее') }}" @disabled($i === $sitePhotos->count() - 1)><x-ui::icon name="chev-right" class="h-4 w-4" /></button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($sitePhotos->count() < 3)<p class="mt-2 text-xs text-warn">{{ __('Меньше 3 фото — добавьте ещё, с фото продают быстрее') }}</p>@endif
        @endif
    </div>

    {{-- switches and texts --}}
    <div class="grid grid-cols-[minmax(0,1fr)] gap-3 desk:grid-cols-2">
        <div class="space-y-2 rounded-xl border border-border p-3">
            <label class="flex items-center justify-between gap-3 text-sm font-medium">
                {{ __('Показывать на сайте') }}
                <button type="button" wire:click="siteToggle" @disabled(! $siteCan['visibility']) role="switch" aria-checked="{{ $unit->show_on_site ? 'true' : 'false' }}"
                        class="relative h-7 w-12 rounded-full transition {{ $unit->show_on_site ? 'bg-primary' : 'bg-input' }} disabled:opacity-50">
                    <span class="absolute top-0.5 h-6 w-6 rounded-full bg-white shadow transition {{ $unit->show_on_site ? 'left-[22px]' : 'left-0.5' }}"></span>
                </button>
            </label>
            @unless ($siteCan['visibility'])<p class="text-xs text-muted-foreground">{{ __('Показать телефон на сайте может владелец или админ') }}</p>@endunless
            @if ($siteCan['order'])
                <div class="flex flex-wrap gap-2 pt-1">
                    @if ($unit->site_pinned_rank)
                        <span class="inline-flex h-9 items-center gap-1 rounded-lg bg-primary/10 px-2 text-sm text-primary"><x-ui::icon name="pinned" class="h-4 w-4" />{{ __('Закреплён №:n', ['n' => $unit->site_pinned_rank]) }}</span>
                        <button type="button" wire:click="pin(false)" class="{{ $btn }} h-9">{{ __('Обычный порядок') }}</button>
                    @else
                        <button type="button" wire:click="pin(true)" class="{{ $btn }} h-9"><x-ui::icon name="pinned" class="h-4 w-4" />{{ __('Поднять наверх') }}</button>
                    @endif
                </div>
            @endif
        </div>
        @if ($siteCan['description'])
            <div class="space-y-2 rounded-xl border border-border p-3">
                <label class="block text-sm">{{ __('Описание / дефекты (рус)') }}<textarea wire:model="site.description_ru" rows="2" maxlength="500" class="mt-1 w-full rounded-lg border-input bg-background text-base" placeholder="{{ __('Небольшая царапина на рамке') }}"></textarea></label>
                <label class="block text-sm">{{ __('Сипаттама (қаз)') }}<textarea wire:model="site.description_kk" rows="2" maxlength="500" class="mt-1 w-full rounded-lg border-input bg-background text-base"></textarea></label>
                @if ($siteCan['order'])
                    <div class="flex gap-4 text-sm"><label class="flex items-center gap-2"><input type="checkbox" wire:model="site.hit" class="rounded border-input text-primary">{{ __('Хит') }}</label><label class="flex items-center gap-2"><input type="checkbox" wire:model="site.new" class="rounded border-input text-primary">{{ __('Новинка') }}</label></div>
                @endif
                <button type="button" wire:click="saveSite" class="h-10 rounded-lg bg-primary px-4 text-sm font-semibold text-white">{{ __('Сохранить') }}</button>
            </div>
        @endif
    </div>
</div>
