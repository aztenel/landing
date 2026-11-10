<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Transactional outbox (audit №5): deliver changes to the site and other listeners every minute
Schedule::command('prime:outbox')->everyMinute()->withoutOverlapping();

// Nightly expiry of customer bonuses, a system document БСГ per firm (audit №54); before the ledger checks
Schedule::command('prime:bonus-expire')->dailyAt('03:10')->timezone('Asia/Almaty')->withoutOverlapping();

// Nightly ledger checks in production (audit №16, TZ 02 §13)
Schedule::command('prime:reconcile')->dailyAt('03:30')->timezone('Asia/Almaty')->withoutOverlapping();

// Expired cart reservations (audit №51)
Schedule::command('prime:release-reservations')->everyMinute()->withoutOverlapping();

// Approval requests: expiry, escalation to the admin, repeated push (TZ 03 §6.4)
Schedule::command('prime:approvals-tick')->everyMinute()->withoutOverlapping();

// Unclosed shifts: «Касса не закрыта» to the owner at 23:59, auto close «не сверена» at 03:00 (audit №57)
Schedule::command('prime:shifts-tick')->everyMinute()->withoutOverlapping();

// Orders: a hold without prepayment expires, with prepayment becomes «Просрочен» (TZ 01 П10.2)
Schedule::command('prime:orders-tick')->everyFiveMinutes()->withoutOverlapping();

// Storefront: nightly full check of the public cards against the warehouse (TZ 05 §8.6)
Schedule::command('prime:site-reconcile')->dailyAt('03:40')->timezone('Asia/Almaty')->withoutOverlapping();

// Parallel run with sfon: nightly comparison of stock and prices (TZ 07 §1.9); idle without SFON_API_KEY or after Ш11
Schedule::command('prime:sfon-reconcile')->dailyAt('03:00')->timezone('Asia/Almaty')->withoutOverlapping();

// Owner summaries (TZ 06 §4.1): morning «Итог вчера» (+ week on Monday, month on the 1st) and «Итог дня» at 22:30
Schedule::command('prime:summaries')->everyMinute()->withoutOverlapping();

// Site promotions: start and finish by the clock (TZ 05 §6.3)
Schedule::command('prime:promotions-tick')->everyMinute()->withoutOverlapping();
