{{-- Shop header of tape forms: name, address, WhatsApp, legal name and BIN, «не фискальный» --}}
@php($P = \App\Domain\Printing\Printing::class)
<div class="c b big">{{ mb_strtoupper($brand) }}</div>
@if ($store)<div class="c small">{{ $store->name }}{{ $store->address ? ', '.$store->address : '' }}</div>
    @if (($store->meta['whatsapp'] ?? null) || $store->phone)<div class="c small">WhatsApp {{ $store->meta['whatsapp'] ?? $store->phone }}</div>@endif
@endif
@if ($org->legal_name || $org->bin_iin)<div class="c small">{{ $org->legal_name }}{{ $org->bin_iin ? ', БИН/ИИН '.$org->bin_iin : '' }}</div>@endif
