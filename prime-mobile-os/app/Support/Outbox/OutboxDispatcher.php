<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read side of the outbox: takes pending rows (FOR UPDATE SKIP LOCKED — several workers do not deliver one row twice
 * at the same time), fires OutboxMessage for each and marks it processed. A failing row is retried later with a growing
 * pause (1, 2, 4 … 60 minutes); rows are delivered in id order per run.
 */
class OutboxDispatcher
{
    public const MAX_DELAY_MINUTES = 60;

    /** @return array{processed: int, failed: int} */
    public function run(int $limit = 200): array
    {
        $processed = 0;
        $failed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $result = DB::transaction(function () {
                $row = DB::table('outbox_events')
                    ->whereNull('processed_at')->whereRaw('available_at <= clock_timestamp()') // DB clock: PHP «now» is cut to whole seconds
                    ->orderBy('id')->lock('for update skip locked')->first();
                if (! $row) {
                    return null;
                }
                try {
                    DB::transaction(function () use ($row) {    // savepoint: a failing listener undoes only its own writes
                        CurrentOrganization::instance()->run((int) $row->organization_id, fn () => event(new OutboxMessage(
                            (int) $row->id, (int) $row->organization_id, $row->topic, $row->subject_type,
                            $row->subject_id === null ? null : (int) $row->subject_id, json_decode($row->payload, true) ?? [],
                        )));
                    });
                    DB::table('outbox_events')->where('id', $row->id)->update(['processed_at' => now(), 'attempts' => $row->attempts + 1, 'last_error' => null]);

                    return true;
                } catch (Throwable $e) {
                    $delay = min(self::MAX_DELAY_MINUTES, 2 ** min($row->attempts, 6));
                    DB::table('outbox_events')->where('id', $row->id)->update([
                        'attempts' => $row->attempts + 1, 'last_error' => mb_substr($e::class.': '.$e->getMessage(), 0, 2000),
                        'available_at' => now()->addMinutes($delay),
                    ]);
                    report($e);

                    return false;
                }
            });
            if ($result === null) {
                break;
            }
            $result ? $processed++ : $failed++;
        }

        return ['processed' => $processed, 'failed' => $failed];
    }
}
