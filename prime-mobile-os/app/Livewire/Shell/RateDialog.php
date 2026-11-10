<?php

namespace App\Livewire\Shell;

use App\Domain\Money\Services\ExchangeRates;
use App\Support\Exceptions\UserFacingException;
use App\Support\Ui\Access;
use Livewire\Component;

/**
 * Э22 «Курс доллара»: the rate chip in the top bar; a click opens a small dialog. Everyone sees the rate; only those
 * with rate.set may change it. A change above the threshold asks «Вы уверены?» (protection from «5150» typos).
 */
class RateDialog extends Component
{
    public bool $open = false;

    public string $rate = '';

    public string $comment = '';

    public ?string $confirm = null;

    public ?string $error = null;

    public function openDialog(): void
    {
        $this->rate = app(ExchangeRates::class)->current()?->toString() ?? '';
        $this->comment = '';
        $this->confirm = null;
        $this->error = null;
        $this->open = true;
    }

    public function save(bool $confirmed = false): void
    {
        Access::authorize('rate.set', __('Курс доллара ставит админ или владелец')); // checked on the server, whatever the browser sends
        $value = str_replace([' ', ','], ['', '.'], trim($this->rate));
        if (! preg_match('/^\d{2,4}(\.\d{1,4})?$/', $value)) {
            $this->error = __('Введите курс цифрами, например 515 или 515,50');

            return;
        }
        try {
            app(ExchangeRates::class)->set($value, auth()->user(), $this->comment ?: null, $confirmed);
        } catch (UserFacingException $e) {
            if ($e->reason === 'rate_confirm') {
                $this->confirm = $e->getMessage();

                return;
            }
            $this->error = $e->getMessage();

            return;
        }
        $this->open = false;
        $this->dispatch('pm-rate-changed');
    }

    public function render()
    {
        $row = app(ExchangeRates::class)->currentRow();

        return view('livewire.shell.rate-dialog', [
            'current' => $row ? \App\Support\Money\Rate::of($row->rate)->format() : null,
            'since' => $row?->valid_from?->setTimezone(\App\Support\Tenancy\CurrentOrganization::instance()->require()->timezone)->format('d.m H:i'),
            'canSet' => Access::allows('rate.set'),
        ]);
    }
}
