<?php

namespace App\Filament\Shell\Pages;

use App\Domain\Shell\Services\AuditLog;
use App\Domain\Shell\Services\DeviceRegistry;
use App\Domain\Shell\Services\Pins;
use App\Domain\Shell\Services\TwoFactor;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Ui\MenuGroup;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * «Мой профиль» (TZ 03 §7–9): язык интерфейса (рус / қаз), PIN разблокировки этого устройства, вход по коду (2FA),
 * «Выйти везде». Each person, for himself.
 */
class Profile extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = null;

    protected static ?MenuGroup $menuGroup = MenuGroup::Management;

    protected static ?string $navigationIcon = 'user';

    protected static ?int $navigationSort = 95;

    protected static ?string $slug = 'profile';

    protected static string $view = 'shell.pages.profile';

    public array $pin = ['pin' => '', 'password' => ''];

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Мой профиль');
    }

    public function getTitle(): string
    {
        return __('Мой профиль');
    }

    public function setLocale(string $locale): void
    {
        abort_unless(in_array($locale, ['ru', 'kk'], true), 422);
        auth()->user()->forceFill(['locale' => $locale])->save();
        $this->redirect(static::getUrl());
    }

    public function savePin(): void
    {
        $this->error = null;
        $device = app(DeviceRegistry::class)->current(request());
        try {
            if (! $device) {
                throw UserFacingException::because('no_device', __('Это устройство не распознано — войдите заново'));
            }
            app(Pins::class)->set(auth()->user(), $device, trim($this->pin['pin']), (string) $this->pin['password']);
            $this->pin = ['pin' => '', 'password' => ''];
            $this->flash = __('PIN сохранён для этого устройства');
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** «Выйти везде» (§8.4): every session of this person closed, except this one. */
    public function logoutEverywhere(): void
    {
        $user = auth()->user();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
        DB::table('two_factor_trusts')->where('user_id', $user->id)->delete();
        AuditLog::record('auth', 'logout_everywhere', null, [], $user);
        $this->flash = __('Все другие входы закрыты');
    }

    protected function getViewData(): array
    {
        $user = auth()->user();

        return ['user' => $user, 'twoFactor' => TwoFactor::enabled($user), 'twoFactorRequired' => TwoFactor::required($user),
            'hasPin' => app(Pins::class)->has($user, app(DeviceRegistry::class)->current(request())),
            'codesLeft' => count((array) json_decode((string) $user->two_factor_recovery_codes, true))];
    }
}
