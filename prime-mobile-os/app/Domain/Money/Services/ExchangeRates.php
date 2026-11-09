<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Models\ExchangeRate;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;

/**
 * Exchange rates of the current organization (TZ 02 §3.3). Setting a rate writes no journal entries and
 * changes nothing in the past; retail prices are not recalculated automatically.
 */
class ExchangeRates
{
    public function __construct(private readonly AccessChecker $access) {}

    /** Effective rate = the latest row with valid_from ≤ $at. */
    public function current(?CarbonInterface $at = null, Currency $currency = Currency::USD): ?Rate
    {
        return $this->currentRow($at, $currency)?->toRate();
    }

    public function currentRow(?CarbonInterface $at = null, Currency $currency = Currency::USD): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('currency', $currency->value)
            ->where('valid_from', '<=', $at ?? now())
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  bool  $confirmed  the user confirmed a change above the threshold (protection from «5150» typos)
     */
    public function set(Rate|string $rate, User $actor, ?string $comment = null, bool $confirmed = false, ?CarbonInterface $validFrom = null): ExchangeRate
    {
        CurrentOrganization::instance()->require();
        $rate = Rate::of($rate);

        if (! $this->access->can($actor, 'rate.set')) {
            throw AccessDenied::because('access_denied', 'Курс доллара ставит админ или владелец');
        }

        $previous = $this->current();
        if ($previous && ! $confirmed) {
            $threshold = Money::percentScaled(CurrentOrganization::instance()->require()->settings()->rateConfirmChangePercent());
            if ($rate->changePercentScaled($previous) > $threshold) {
                throw UserFacingException::because('rate_confirm', "Вы уверены? Было {$previous->format()}, станет {$rate->format()}", [
                    'previous' => $previous->toString(), 'new' => $rate->toString(),
                ]);
            }
        }

        return ExchangeRate::query()->create([
            'currency' => Currency::USD->value,
            'rate' => $rate->toString(),
            'valid_from' => $validFrom ?? now(),
            'set_by' => $actor->id,
            'comment' => $comment,
        ]);
    }
}
