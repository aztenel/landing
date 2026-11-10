<?php

namespace App\Domain\Tenancy\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Field visibility (TZ 03 §4, §18 p.3): closed fields are removed from the data BEFORE it reaches a screen,
 * Excel, PDF, JSON or a Livewire public property — never hidden by CSS.
 *
 *   $unit->forViewer($user)->toArray();                 // one model
 *   StockUnit::hideForViewer($units, $user);            // a collection (one permission check per permission)
 *   StockUnit::hiddenFieldsFor($user);                  // e.g. to drop table columns / sorting in Filament
 *
 * @mixin Model
 */
trait HasSensitiveFields
{
    /**
     * Hidden by default (audit №21): every model loaded while a user is logged in hides the fields that user may not
     * see, so HTML, JSON, Livewire snapshots and Excel exports of any screen are safe even when the screen forgets
     * forViewer(). Property access in domain code ($unit->cost_kzt) is not affected — only serialization.
     */
    public static function bootHasSensitiveFields(): void
    {
        static::retrieved(function (self $model) {
            $user = auth()->user();
            if ($user instanceof User) {
                $model->makeHidden(static::hiddenFieldsForCached($user));
            }
        });
    }

    /** @return list<string> */
    private static function hiddenFieldsForCached(User $viewer): array
    {
        static $cache = [];
        $key = spl_object_id(app()).':'.static::class.':'.$viewer->id;

        return $cache[$key] ??= static::hiddenFieldsFor($viewer);
    }

    /** @return array<string, list<string>> permission that opens the fields => fields */
    abstract public static function sensitiveFields(): array;

    /** @return list<string> */
    public static function hiddenFieldsFor(User $viewer): array
    {
        $checker = app(AccessChecker::class);
        $hidden = [];
        foreach (static::sensitiveFields() as $permission => $fields) {
            if (! $checker->can($viewer, $permission)) {
                $hidden = array_merge($hidden, $fields);
            }
        }

        return array_values(array_unique($hidden));
    }

    public function forViewer(User $viewer): static
    {
        return $this->makeHidden(static::hiddenFieldsFor($viewer));
    }

    /** @param iterable<static> $models */
    public static function hideForViewer(iterable $models, User $viewer): iterable
    {
        $hidden = static::hiddenFieldsFor($viewer);
        foreach ($models as $model) {
            $model->makeHidden($hidden);
        }

        return $models;
    }
}
