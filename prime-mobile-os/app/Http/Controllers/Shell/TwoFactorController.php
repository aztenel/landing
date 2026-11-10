<?php

namespace App\Http\Controllers\Shell;

use App\Domain\Printing\Printing;
use App\Domain\Shell\Services\DeviceRegistry;
use App\Domain\Shell\Services\HomeResolver;
use App\Domain\Shell\Services\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use Illuminate\Http\Request;

/** «Код из приложения» after the password and the first setup with a QR code (TZ 03 §7.1, §7.3). */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $tf) {}

    public function show(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        if (! TwoFactor::enabled($user)) {
            return redirect()->route('erp.two-factor.setup');
        }
        if ($this->tf->passed($user, $request)) {
            return redirect()->to(app(HomeResolver::class)->url($user));
        }
        $device = app(DeviceRegistry::class)->current($request);

        return view('shell.auth.two-factor', ['user' => $user, 'canRemember' => $device && $device->type?->value !== 'store_computer']);
    }

    public function verify(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'max:20']], ['code.required' => __('Введите код')]);
        try {
            $this->tf->verify($user, $data['code'], $request, $request->boolean('remember'), app(DeviceRegistry::class)->current($request));
        } catch (UserFacingException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->intended(app(HomeResolver::class)->url($user));
    }

    public function setup(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        if (TwoFactor::enabled($user) && ! session('shell.2fa_codes')) {
            return redirect()->route('erp.two-factor');
        }
        $secret = session('shell.2fa_codes') ? null : $this->tf->begin($user);

        return view('shell.auth.two-factor-setup', ['user' => $user, 'secret' => $secret, 'qr' => $secret ? Printing::qr(TwoFactor::uri($user, $secret)) : null,
            'codes' => session('shell.2fa_codes')]);
    }

    public function confirm(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $codes = $this->tf->confirm($user, (string) $request->input('code'));
        } catch (UserFacingException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('erp.two-factor.setup')->with('shell.2fa_codes', $codes);
    }
}
