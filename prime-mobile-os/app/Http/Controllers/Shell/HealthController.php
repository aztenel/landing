<?php

namespace App\Http\Controllers\Shell;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * /health (architecture §6): the deploy switches to a new release only if this answers 200; the external monitor
 * checks it every minute. No secrets and no business data — only «ok / not ok» of each part.
 */
class HealthController
{
    public function __invoke()
    {
        $checks = [];
        $checks['database'] = rescue(fn () => DB::select('select 1') !== [], false, false);
        $checks['redis'] = config('cache.default') !== 'redis' ? true : rescue(fn () => (bool) Redis::connection()->ping(), false, false);
        $checks['storage'] = rescue(fn () => Storage::disk('local')->put('.health', (string) time()), false, false);
        $oldest = rescue(fn () => DB::table('outbox_events')->whereNull('processed_at')->min('created_at'), null, false);
        $checks['outbox'] = ! $oldest || now()->diffInMinutes(Carbon::parse($oldest), true) < 10;
        $backup = @json_decode((string) @file_get_contents(storage_path('app/backup-status.json')), true);
        $checks['backup'] = app()->environment('production') ? (($backup['ok'] ?? false) && isset($backup['at']) && now()->diffInHours(Carbon::parse($backup['at']), true) < 26) : true;
        $ok = ! in_array(false, $checks, true);

        return response()->json(['ok' => $ok, 'checks' => $checks, 'time' => now()->toIso8601String()], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
