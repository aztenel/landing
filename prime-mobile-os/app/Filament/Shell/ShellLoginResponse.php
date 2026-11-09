<?php

namespace App\Filament\Shell;

use App\Domain\Shell\Services\HomeResolver;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/** After login: the page of the role (TZ 04 §1) or the firm chooser when the person works in several firms. */
class ShellLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();
        $organizations = $user?->activeOrganizations() ?? collect();
        if ($organizations->count() > 1) {
            return redirect()->route('organization.select');
        }
        if ($organizations->count() === 1) {
            $home = CurrentOrganization::instance()->run($organizations->first(), fn () => app(HomeResolver::class)->url($user));

            return redirect()->intended($home);
        }

        return redirect()->intended(Filament::getUrl());
    }
}
