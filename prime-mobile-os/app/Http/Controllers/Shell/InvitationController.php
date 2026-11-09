<?php

namespace App\Http\Controllers\Shell;

use App\Domain\Shell\Models\Invitation;
use App\Domain\Shell\Services\DefaultTexts;
use App\Domain\Shell\Services\DeviceRegistry;
use App\Domain\Shell\Services\HomeResolver;
use App\Domain\Shell\Services\ScreenLock;
use App\Domain\Shell\Services\StaffManager;
use App\Domain\Tenancy\Models\Organization;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\UserFacingException;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Invitation / password-reset link (TZ 03 §14.2, §7.5; TZ 04 Э1.2): «Здравствуйте, Дана! Вас пригласил
 * Кайрат в Prime Mobile как Продавца» → password twice → rules → the ERP. The device where the invitation
 * was accepted is allowed automatically (TZ 03 §8.2).
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $link = StaffManager::findLink($token);
        if (! $link || ! $link->isUsable()) {
            return view('shell.auth.invitation-expired');
        }
        $org = Organization::query()->findOrFail($link->organization_id);

        return CurrentOrganization::instance()->run($org, function () use ($link, $org, $request) {
            if (! $link->opened_at) {
                [$platform, $browser] = DeviceRegistry::describeAgent((string) $request->userAgent());
                $link->forceFill(['opened_at' => now(), 'opened_ip' => $request->ip(), 'opened_device' => $platform.' · '.$browser])->save();
            }
            $user = $link->user;
            $user->unsetRelation('roles');
            /** @var Role|null $role */
            $role = $user->roles()->first();

            return view('shell.auth.invitation', [
                'link' => $link,
                'person' => $user,
                'inviter' => $link->creator,
                'organization' => $org,
                'role' => $role?->title,
                'rules' => DefaultTexts::staffRules($org->settings()),
                'isReset' => $link->kind === Invitation::PASSWORD_RESET,
            ]);
        });
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $link = StaffManager::findLink($token);
        if (! $link || ! $link->isUsable()) {
            return redirect()->route('erp.invitation', ['token' => $token]);
        }
        $data = $request->validate([
            'password' => ['required', 'string', 'max:200'],
            'password_confirmation' => ['required', 'string'],
        ], [
            'password.required' => __('Придумайте пароль'),
            'password_confirmation.required' => __('Повторите пароль'),
        ]);
        if ($data['password'] !== $data['password_confirmation']) {
            return back()->withErrors(['password_confirmation' => __('Пароли не совпадают')]);
        }
        $org = Organization::query()->findOrFail($link->organization_id);
        try {
            $user = app(StaffManager::class)->accept($link, $data['password'], $request->boolean('rules'),
                (int) $org->settings()->get('texts.staff_rules_version', 1));
        } catch (UserFacingException $e) {
            return back()->withErrors([$e->reason === 'rules' ? 'rules' : 'password' => $e->getMessage()]);
        }

        Auth::guard(Filament::getAuthGuard())->login($user);
        $request->session()->regenerate();
        $request->session()->put(ScreenLock::LOGIN_AT, now()->getTimestamp());
        $request->session()->put(ScreenLock::ACTIVE, now()->getTimestamp());
        $home = CurrentOrganization::instance()->run($org, function () use ($request, $user) {
            app(DeviceRegistry::class)->touch($request, $user); // allowed automatically (TZ 03 §8.2)

            return app(HomeResolver::class)->url($user);
        });

        return redirect()->to($home)->with('shell.toast', __('Пароль сохранён. Добро пожаловать!'));
    }
}
