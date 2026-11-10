<?php

namespace App\Console\Commands;

use App\Support\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

class OutboxDispatchCommand extends Command
{
    protected $signature = 'prime:outbox {--limit=500}';

    protected $description = 'Deliver pending outbox events (site sync and other listeners), with retries';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $result = $dispatcher->run((int) $this->option('limit'));
        $this->info("processed {$result['processed']}, failed {$result['failed']}");

        return self::SUCCESS;
    }
}
