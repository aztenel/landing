<?php

namespace App\Domain\Reports\Services;

use Carbon\CarbonImmutable;

/**
 * Report period in the firm's time zone (TZ 06 §1.1 «день = 00:00–23:59 местного времени», §1.2 periods).
 * from/to are local; queries use the UTC bounds [startUtc, endUtc).
 */
final class Period
{
    public const KEYS = ['today' => 'Сегодня', 'yesterday' => 'Вчера', 'week' => 'Эта неделя', 'last_week' => 'Прошлая неделя',
        'month' => 'Этот месяц', 'last_month' => 'Прошлый месяц', 'year' => 'Этот год', 'last_year' => 'Прошлый год', 'custom' => 'Свой период'];

    private function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to, public readonly string $key) {}

    public static function make(string $key, string $tz, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): self
    {
        $n = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        [$a, $b] = match ($key) {
            'yesterday' => [$n->subDay()->startOfDay(), $n->subDay()->endOfDay()],
            'week' => [$n->startOfWeek(), $n->endOfDay()],
            'last_week' => [$n->subWeek()->startOfWeek(), $n->subWeek()->endOfWeek()],
            'month' => [$n->startOfMonth(), $n->endOfDay()],
            'last_month' => [$n->subMonthNoOverflow()->startOfMonth(), $n->subMonthNoOverflow()->endOfMonth()],
            'year' => [$n->startOfYear(), $n->endOfDay()],
            'last_year' => [$n->subYear()->startOfYear(), $n->subYear()->endOfYear()],
            'custom' => [self::date($from, $tz, $n)->startOfDay(), self::date($to, $tz, $n)->endOfDay()],
            default => [$n->startOfDay(), $n->endOfDay()],
        };
        if ($b->lt($a)) {
            [$a, $b] = [$b->startOfDay(), $a->endOfDay()];
        }

        return new self($a, $b, isset(self::KEYS[$key]) ? $key : 'today');
    }

    private static function date(?string $d, string $tz, CarbonImmutable $fallback): CarbonImmutable
    {
        try {
            return $d ? CarbonImmutable::parse($d, $tz) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public static function between(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($from, $to, 'custom');
    }

    /** «Прошлый такой же период» (§1.2): the same length right before; a month/year compares with the previous one. */
    public function previous(): self
    {
        return match ($this->key) {
            'month' => new self($this->from->subMonthNoOverflow(), $this->to->subMonthNoOverflow()->min($this->from->subSecond()), 'custom'),
            'last_month' => new self($this->from->subMonthNoOverflow()->startOfMonth(), $this->from->subMonthNoOverflow()->endOfMonth(), 'custom'),
            'year', 'last_year' => new self($this->from->subYear(), $this->to->subYear(), 'custom'),
            default => new self($this->from->subDays($this->days()), $this->to->subDays($this->days()), 'custom'),
        };
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay(), true) + 1;
    }

    public function startUtc(): string
    {
        return $this->from->utc()->toDateTimeString();
    }

    public function endUtc(): string
    {
        return $this->to->addSecond()->startOfSecond()->utc()->toDateTimeString();
    }

    /** Bars of the chart (Э2.1): > 62 days — by weeks, > 1 year — by months. */
    public function bucket(): string
    {
        return $this->days() > 366 ? 'month' : ($this->days() > 62 ? 'week' : 'day');
    }

    public function label(): string
    {
        if ($this->key !== 'custom') {
            return __(self::KEYS[$this->key]);
        }

        return $this->from->isSameDay($this->to) ? $this->from->format('d.m.Y') : $this->from->format('d.m.Y').' – '.$this->to->format('d.m.Y');
    }
}
