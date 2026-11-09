<?php

namespace App\Support\Tenancy;

use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\Activitylog\ActivitylogServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentOrganization::class);
    }

    public function boot(): void
    {
        // Livewire update requests (/livewire/update — every Filament action, the cash register) re-run only the
        // «persistent» middleware of the original route. Without this the organization context and the membership
        // re-check (blocked / dismissed / switched firm) would be skipped on them (TZ 03 §12 p.2, §18 p.1).
        Livewire::addPersistentMiddleware([SetCurrentOrganization::class]);

        // A queue worker reuses this singleton for every job: a job must set its own organization (TZ 03 §12 p.4),
        // never inherit the previous job's firm. (A «sync» job runs inside the dispatching request and keeps its firm.)
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                CurrentOrganization::instance()->clear();
            }
        });

        // The action journal belongs to the organization (TZ 03 §12 p.1, p.9): activity_log.organization_id.
        $activity = ActivitylogServiceProvider::determineActivityModel();
        $activity::creating(function (Model $entry): void {
            if ($entry->getAttribute('organization_id') === null) {
                $entry->setAttribute('organization_id', CurrentOrganization::instance()->id());
            }
        });
    }
}
