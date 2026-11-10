<?php

namespace App\Domain\Storefront;

use App\Domain\Storefront\Listeners\SyncStorefront;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Module «storefront» (TZ 05): outbox → public cards; routes in routes/modules/storefront.php. */
class StorefrontServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OutboxMessage::class, SyncStorefront::class);
        // photos from an iPhone: up to 25 MB each (TZ 05 §3.3); php upload_max_filesize must allow it too (deploy)
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:25600']]);
    }
}
