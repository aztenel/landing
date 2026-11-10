{{-- Отчёт качества (TZ 07 §1.6): before the ВХО every red target must be 0 --}}
<div class="grid grid-cols-2 gap-2 sm:grid-cols-4 desk:grid-cols-6">
    @foreach ([[__('Новых в sfon'), $quality['sfon_new'], null], [__('Б/у в sfon'), $quality['sfon_used'], null], [__('Нет варианта'), $quality['no_variant'], 0],
        [__('Отсканировано'), $quality['scanned'], null], [__('Не найдено'), $quality['not_found'], 0], [__('Лишние без ответа'), $quality['unresolved'] ?? 0, 0], [__('Без себестоимости'), $quality['no_cost'], 0],
        [__('Без kk-названия'), $quality['no_kk'], 0], [__('Аксессуаров'), $quality['accessories'], null], [__('Аксессуаров с ошибкой'), $quality['acc_no_cost'], 0],
        [__('Клиентов'), $quality['customers'], null], [__('Долги клиентов'), \App\Support\Money\Money::kztMinor($quality['customer_debts'])->format(), null], [__('Бонусы'), number_format($quality['bonuses'] / 100, 0, '', ' '), null]] as [$n, $v, $target])
        <div class="rounded-xl border border-border bg-card p-3"><p class="text-xs text-muted-foreground">{{ $n }}</p><p class="num text-lg font-semibold {{ $target !== null ? ($v == $target ? 'text-ok' : 'text-bad') : '' }}">{{ $v }}</p></div>
    @endforeach
</div>
