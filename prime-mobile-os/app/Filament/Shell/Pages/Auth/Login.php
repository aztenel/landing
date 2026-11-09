<?php

namespace App\Filament\Shell\Pages\Auth;

use App\Domain\Shell\Models\Device;
use App\Domain\Shell\Services\AuditLog;
use App\Domain\Shell\Services\DeviceRegistry;
use App\Domain\Shell\Services\LoginGuard;
use App\Domain\Shell\Services\ScreenLock;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Membership;
use App\Filament\Pages\Auth\PhoneLogin;
use App\Http\Middleware\Shell\EnsureDeviceAllowed;
use App\Models\User;
use App\Support\Phone\Phone;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Э1.1 «Вход»: phone (+7 mask) and password, «Запомнить это устройство», texts of errors from TZ 04 Э1.1
 * word for word. Protection: 5 wrong passwords for a phone → 10 minutes pause (LoginGuard), blocked or
 * dismissed people and switched-off devices do not get in. Device check, 2FA and badges: TZ 03 §7–8.
 */
class Login extends PhoneLogin
{
    protected static string $view = 'shell.auth.login';

    public const ERR_FAILED = 'Неверный телефон или пароль';

    public const ERR_THROTTLED = 'Слишком много попыток. Попробуйте через 10 минут или попросите владельца сбросить пароль';

    public const ERR_BLOCKED = 'Ваш доступ отключён владельцем фирмы';

    public const ERR_STORE = 'Касса открывается только в вашем магазине';

    public const ERR_PHONE = 'Введите номер полностью: 10 цифр после +7';

    public bool $showForgot = false;

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }
        // «Запомнить» is on by default on iPhone, off on the laptop (Э1.1).
        $this->form->fill(['remember' => DeviceRegistry::isPhone((string) request()->userAgent())]);
        if ($error = session('shell.login_error')) {
            $this->addError('data.phone', $error);
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('Вход');
    }

    public function getHeading(): string|Htmlable
    {
        return __('Вход');
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label(__('Телефон'))
            ->tel()
            ->mask('+7 (999) 999-99-99')
            ->placeholder('+7 (___) ___-__-__')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1, 'inputmode' => 'tel']);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('Пароль'))
            ->password()
            ->revealable()
            ->autocomplete('current-password')
            ->required()
            ->extraInputAttributes(['tabindex' => 2]);
    }

    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')->label(__('Запомнить это устройство'));
    }

    public function toggleForgot(): void
    {
        $this->showForgot = ! $this->showForgot;
    }

    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $guard = app(LoginGuard::class);
        $ip = (string) request()->ip();

        $digits = preg_replace('/\D+/', '', (string) $data['phone']) ?? '';
        if (strlen($digits) < 10) {
            throw ValidationException::withMessages(['data.phone' => __(self::ERR_PHONE)]);
        }
        try {
            $phone = Phone::normalize((string) $data['phone']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['data.phone' => __(self::ERR_PHONE)]);
        }

        if ($guard->isLocked($phone, $ip)) {
            $this->journalFor($phone, 'login_throttled');
            throw ValidationException::withMessages(['data.phone' => __(self::ERR_THROTTLED)]);
        }

        $credentials = ['phone' => $phone, 'password' => (string) $data['password']];
        if (! Filament::auth()->validate($credentials)) {
            $nowLocked = $guard->failed($phone, $ip);
            $this->journalFor($phone, 'login_failed');
            throw ValidationException::withMessages(['data.phone' => __($nowLocked ? self::ERR_THROTTLED : self::ERR_FAILED)]);
        }

        /** @var User $user */
        $user = User::query()->where('phone', $phone)->firstOrFail();
        if (! $user->canAccessPanel(Filament::getCurrentPanel())) {
            $this->journalFor($phone, 'login_refused', ['why' => __(self::ERR_BLOCKED)]);
            throw ValidationException::withMessages(['data.phone' => __(self::ERR_BLOCKED)]);
        }
        if ($error = $this->deviceProblem($user)) {
            $this->journalFor($phone, 'login_refused', ['why' => $error]);
            throw ValidationException::withMessages(['data.phone' => $error]);
        }

        $guard->succeeded($phone);
        Auth::guard(Filament::getAuthGuard())->login($user, (bool) ($data['remember'] ?? false));
        session()->regenerate();
        session()->put(ScreenLock::LOGIN_AT, now()->getTimestamp());
        session()->put(ScreenLock::ACTIVE, now()->getTimestamp());
        $user->forceFill(['last_login_at' => now()])->save();

        $organizations = $user->activeOrganizations();
        if ($organizations->count() === 1) {
            CurrentOrganization::instance()->run($organizations->first(), function () use ($user) {
                [$platform, $browser] = DeviceRegistry::describeAgent((string) request()->userAgent());
                Membership::query()->where('user_id', $user->id)->update(['last_login_at' => now(), 'last_login_device' => $platform.' · '.$browser]);
                AuditLog::record('auth', 'login', null, [], $user);
            });
        }

        return app(LoginResponse::class);
    }

    /** Text of the error if this browser may not be used by the person (Э1.1, TZ 03 §8). */
    private function deviceProblem(User $user): ?string
    {
        $key = request()->cookies->get(DeviceRegistry::COOKIE);
        if (! is_string($key) || $key === '') {
            return null;
        }
        $hash = hash('sha256', $key);
        foreach ($user->activeOrganizations() as $org) {
            $problem = CurrentOrganization::instance()->run($org, function () use ($hash, $user) {
                $device = Device::query()->where('key_hash', $hash)->first();
                if (! $device) {
                    return null;
                }
                if ($device->isDisabled()) {
                    return __(EnsureDeviceAllowed::DISABLED_MESSAGE);
                }
                $membership = Membership::query()->where('user_id', $user->id)->first();
                if ($device->store_id && $membership && ! $membership->allowsStore($device->store_id)) {
                    return __(self::ERR_STORE);
                }

                return null;
            });
            if ($problem && $user->activeOrganizations()->count() === 1) {
                return $problem;
            }
        }

        return null;
    }

    /** Write a login event into the journal of every firm of that phone (owner sees «5 неудачных входов: Дана»). */
    private function journalFor(string $phone, string $event, array $properties = []): void
    {
        $user = User::query()->where('phone', $phone)->first();
        if (! $user) {
            return;
        }
        $orgIds = $user->memberships()->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Blocked->value, MembershipStatus::Dismissed->value])
            ->pluck('organization_id');
        foreach ($orgIds as $orgId) {
            CurrentOrganization::instance()->run((int) $orgId, fn () => AuditLog::record('auth', $event, null, $properties + ['phone' => Phone::mask($phone)], $user));
        }
    }
}
