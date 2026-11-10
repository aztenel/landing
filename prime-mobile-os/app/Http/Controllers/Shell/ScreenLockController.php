<?php

namespace App\Http\Controllers\Shell;

use App\Domain\Shell\Services\AuditLog;
use App\Domain\Shell\Services\ScreenLock;
use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Э1.3: lock by idle (from the browser), unlock by password, activity heartbeat. */
class ScreenLockController extends Controller
{
    public const MAX_FAILS = 5;

    public function __construct(private readonly ScreenLock $lock) {}

    public function lock(Request $request): JsonResponse
    {
        $this->lock->lock($request->session(), $this->user($request), (string) $request->input('why', 'idle'));

        return response()->json(['locked' => true]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:200']]);
        $user = $this->user($request);
        if ($this->lock->unlock($request->session(), $user, (string) $request->input('password'))) {
            return response()->json(['ok' => true]);
        }
        if ($this->lock->failures($request->session()) >= self::MAX_FAILS) {
            // TZ 03 §7.4: 5 wrong PIN/password on the lock screen → full login with the password.
            AuditLog::record('auth', 'logout', null, ['why' => 'unlock_failed'], $user);
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['ok' => false, 'logout' => true, 'redirect' => Filament::getLoginUrl(),
                'message' => __('Слишком много попыток. Войдите заново с телефоном и паролем')], 401);
        }

        return response()->json(['ok' => false, 'message' => __('PIN или пароль не подходит')], 422);
    }

    public function activity(): Response
    {
        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
