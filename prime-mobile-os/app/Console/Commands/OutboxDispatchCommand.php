<?php

namespace App\Console\Commands;

use App\Support\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * Delivers the outbox (site sync and other listeners). Once — by the scheduler every minute (a safety net);
 * --loop — the production worker under supervisor: polls every second, so a sale leaves the site in ≤ 15 s
 * (TZ 05 §8.3), and exits after --max-time seconds to be restarted fresh (memory, new code after a deploy).
 */
class OutboxDispatchCommand extends Command
{
    protected $signature = 'prime:outbox {--limit=500} {--loop : keep running} {--max-time=3600 : seconds before a clean exit in --loop}';

    protected $description = 'Deliver pending outbox events (site sync and other listeners), with retries';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $until = time() + (int) $this->option('max-time');
        do {
            $result = $dispatcher->run((int) $this->option('limit'));
            if (! $this->option('loop')) {
                $this->info("processed {$result['processed']}, failed {$result['failed']}");

                break;
            }
            if ($result['processed'] === 0) {
                sleep(1);
            }
        } while (time() < $until);

        return self::SUCCESS;
    }
}
