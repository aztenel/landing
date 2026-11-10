<?php

namespace App\Domain\Shell\Services;

use App\Domain\Shell\Models\Device;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** PIN of unlock (TZ 03 §7.2): 4–6 digits, not 1234 / 0000 / 1111 …, own for every user on every device, only a hash. */
final class Pins
{
    public const WEAK = ['1234', '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '12345', '123456', '4321', '000000', '111111', '654321'];

    public function set(User $user, Device $device, string $pin, string $password): void
    {
        if (! Hash::check($password, $user->getAuthPassword())) {
            throw UserFacingException::because('pin_password', __('Пароль не подходит'));
        }
        if (! preg_match('/^\d{4,6}$/', $pin)) {
            throw UserFacingException::because('pin_format', __('PIN — от 4 до 6 цифр'));
        }
        if (in_array($pin, self::WEAK, true) || count(array_unique(str_split($pin))) === 1) {
            throw UserFacingException::because('pin_weak', __('Слишком простой PIN — выберите другой'));
        }
        DB::table('device_pins')->updateOrInsert(['user_id' => $user->id, 'device_id' => $device->id], ['pin_hash' => Hash::make($pin), 'updated_at' => now(), 'created_at' => now()]);
        AuditLog::record('auth', 'pin.set', $device, [], $user);
    }

    public function has(User $user, ?Device $device): bool
    {
        return $device && DB::table('device_pins')->where('user_id', $user->id)->where('device_id', $device->id)->exists();
    }

    public function check(User $user, ?Device $device, string $pin): bool
    {
        $hash = $device ? DB::table('device_pins')->where('user_id', $user->id)->where('device_id', $device->id)->value('pin_hash') : null;

        return $hash && preg_match('/^\d{4,6}$/', $pin) && Hash::check($pin, $hash);
    }
}
