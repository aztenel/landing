<?php

namespace App\Http\Controllers\Shell;

use App\Domain\Shell\Services\AuditLog;
use App\Domain\Shell\Services\DefaultTexts;
use App\Domain\Shell\Services\HomeResolver;
use App\Domain\Shell\Services\PasswordRules;
use App\Domain\Tenancy\Models\Membership;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Э1.2 «Первый вход»: a person whose password was given to him (integrator, migration from sfon) sets his
 * own password (twice) and accepts the rules of work with client data before the ERP opens.
 */
class FirstLoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->must_change_password) {
            return redirect()->to(app(HomeResolver::class)->url($user));
        }
        $settings = CurrentOrganization::instance()->require()->settings();

        return view('shell.auth.first-login', [
            'user' => $user,
            'rules' => DefaultTexts::staffRules($settings),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'password' => ['required', 'string', 'max:200'],
            'password_confirmation' => ['required', 'string'],
            'rules' => ['accepted'],
        ], [
            'rules.accepted' => __('Подтвердите, что прочитали правила работы с данными клиентов'),
            'password.required' => __('Придумайте пароль'),
            'password_confirmation.required' => __('Повторите пароль'),
        ]);
        if ($data['password'] !== $data['password_confirmation']) {
            return back()->withErrors(['password_confirmation' => __('Пароли не совпадают')]);
        }
        try {
            PasswordRules::assert($data['password'], $user->phone);
        } catch (UserFacingException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }
        if (Hash::check($data['password'], $user->getAuthPassword())) {
            return back()->withErrors(['password' => __('Новый пароль должен отличаться от выданного')]);
        }

        $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false, 'password_changed_at' => now()])->save();
        $settings = CurrentOrganization::instance()->require()->settings();
        Membership::query()->where('user_id', $user->id)->update([
            'rules_accepted_at' => now(), 'rules_version' => (int) $settings->get('texts.staff_rules_version', 1),
        ]);
        // The password hash in the session is refreshed so AuthenticateSession keeps this session alive.
        Auth::guard('web')->login($user);
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        AuditLog::record('auth', 'password_changed', null, ['first_login' => true], $user);

        return redirect()->to(app(HomeResolver::class)->url($user))->with('shell.toast', __('Пароль сохранён. Добро пожаловать!'));
    }
}
