<?php

namespace App\Http\Controllers\Tenancy;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Choosing / switching the organization for users working in several (TZ 03 §7.3 p.4). */
class OrganizationSwitchController extends Controller
{
    public function select(Request $request): View
    {
        return view('tenancy.select-organization', ['organizations' => $request->user()->activeOrganizations()]);
    }

    public function switch(Request $request, int $organization): RedirectResponse
    {
        abort_unless($request->user()->hasActiveMembershipIn($organization), 403, 'Нет доступа к этой фирме');
        $request->session()->put(SetCurrentOrganization::SESSION_KEY, $organization);
        activity('auth')->causedBy($request->user())->withProperties(['organization_id' => $organization])
            ->tap(fn ($entry) => $entry->organization_id = $organization) // journal of the firm switched to
            ->log('organization_switched');

        return redirect()->intended('/admin');
    }
}
