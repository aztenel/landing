<?php

namespace App\Domain\Shell\Services;

use App\Domain\Shell\Enums\DeviceStatus;
use App\Domain\Shell\Enums\DeviceType;
use App\Domain\Shell\Models\Device;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Devices (TZ 03 §8, TZ 04 Э25.4, Э29). The browser keeps a random key in the `pm_device` cookie (1 year,
 * HttpOnly); the server stores its SHA-256 per organization. Every ERP request touches the device
 * (EnsureDeviceAllowed); a disabled device logs the user out on the next request.
 *
 * Допущение (R1): a new device is registered as «разрешено» and written to the journal; the «new device →
 * request to the owner» flow of TZ 03 §8.2 is switched on by the Approvals module (status pending).
 */
class DeviceRegistry
{
    public const COOKIE = 'pm_device';

    private ?Device $current = null;

    private bool $resolved = false;

    /** The device of this request (no DB writes); null outside a firm or without the cookie. */
    public function current(?Request $request = null): ?Device
    {
        if ($this->resolved) {
            return $this->current;
        }
        $request ??= request();
        $key = $request->cookies->get(self::COOKIE);
        if (! $key || ! CurrentOrganization::instance()->has()) {
            return null;
        }
        $this->resolved = true;

        return $this->current = Device::query()->where('key_hash', hash('sha256', $key))->first();
    }

    /** Find or register the device of this request for the user; update «last seen» at most once a minute. */
    public function touch(Request $request, User $user): Device
    {
        $key = $request->cookies->get(self::COOKIE);
        if (! is_string($key) || strlen($key) < 32) {
            $key = Str::random(48);
            Cookie::queue(Cookie::make(self::COOKIE, $key, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
            $request->cookies->set(self::COOKIE, $key);
        }
        $hash = hash('sha256', $key);
        $device = Device::query()->where('key_hash', $hash)->first();
        [$platform, $browser] = self::describeAgent((string) $request->userAgent());

        if (! $device) {
            $device = new Device;
            $device->forceFill([
                'key_hash' => $hash,
                'type' => self::isPhone((string) $request->userAgent()) ? DeviceType::Personal->value : DeviceType::Other->value,
                'owner_user_id' => self::isPhone((string) $request->userAgent()) ? $user->id : null,
                'platform' => $platform,
                'browser' => $browser,
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                'status' => DeviceStatus::Allowed->value,
                'approved_at' => now(),
                'last_user_id' => $user->id,
                'last_seen_at' => now(),
                'last_ip' => $request->ip(),
            ])->save();
            $this->current = $device;
            $this->resolved = true;
            AuditLog::record('devices', 'registered', $device, ['device' => $device->label()], $user);

            return $device;
        }

        if ($device->last_user_id !== $user->id || ! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill([
                'last_user_id' => $user->id,
                'last_seen_at' => now(),
                'last_ip' => $request->ip(),
                'platform' => $platform,
                'browser' => $browser,
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ])->save();
        }
        $this->current = $device;
        $this->resolved = true;

        return $device;
    }

    public function disable(Device $device, User $by): Device
    {
        $this->authorizeManage($device, $by);
        $device->forceFill(['status' => DeviceStatus::Disabled->value, 'disabled_by' => $by->id, 'disabled_at' => now()])->save();
        AuditLog::record('devices', 'disabled', $device, ['device' => $device->label()], $by);

        return $device;
    }

    public function enable(Device $device, User $by): Device
    {
        $this->authorizeManage($device, $by);
        $device->forceFill(['status' => DeviceStatus::Allowed->value, 'approved_by' => $by->id, 'approved_at' => now(), 'disabled_by' => null, 'disabled_at' => null])->save();
        AuditLog::record('devices', 'enabled', $device, ['device' => $device->label()], $by);

        return $device;
    }

    public function update(Device $device, User $by, string $name, DeviceType $type, ?int $storeId): Device
    {
        $this->authorizeManage($device, $by);
        $old = $device->name;
        $device->fill(['name' => trim($name) ?: null, 'type' => $type->value, 'store_id' => $type === DeviceType::StoreComputer ? $storeId : null])->save();
        if ($old !== $device->name) {
            AuditLog::record('devices', 'renamed', $device, ['old' => $old ?: '—', 'new' => $device->name ?: '—'], $by);
        }

        return $device;
    }

    /** Only disabled devices can be removed from the list; the journal keeps its rows (TZ 03 §8.4). */
    public function delete(Device $device, User $by): void
    {
        if (! Access::isOwner($by)) {
            throw AccessDenied::because('access_denied', __('Удалить устройство из списка может только владелец'));
        }
        if (! $device->isDisabled()) {
            throw AccessDenied::because('device_active', __('Сначала отключите устройство, потом удалите его из списка'));
        }
        AuditLog::record('devices', 'deleted', null, ['device' => $device->label(), 'device_id' => $device->id], $by);
        $device->delete();
    }

    /** «Режим старый ноутбук» of the device (Э29): any member for the device they work on, owner/admin — any. */
    public function setLegacyMode(Device $device, bool $on, User $by): Device
    {
        $own = $this->current()?->is($device) ?? false;
        if (! $own) {
            $this->authorizeManage($device, $by);
        } elseif (! Access::sees('device.prefs', $by)) {
            throw AccessDenied::because('access_denied', __('Нет права: Настройки своего устройства'));
        }
        if ($device->legacy_mode !== $on) {
            $device->forceFill(['legacy_mode' => $on])->save();
            AuditLog::record('devices', 'legacy_mode', $device, ['on' => $on], $by);
        }

        return $device;
    }

    /** device.manage; a non-owner cannot touch devices of owners (TZ 03 §3.9 №98). */
    public function authorizeManage(Device $device, User $by): void
    {
        Access::authorize('device.manage', __('Устройствами управляет владелец'), user: $by);
        if (! Access::isOwner($by) && $this->belongsToOwner($device)) {
            throw AccessDenied::because('access_denied', __('Устройства владельца меняет только владелец'));
        }
    }

    public function belongsToOwner(Device $device): bool
    {
        $ids = array_filter([$device->owner_user_id, $device->last_user_id]);
        foreach (User::query()->whereKey($ids)->get() as $user) {
            if (Access::isOwner($user)) {
                return true;
            }
        }

        return false;
    }

    public static function isPhone(string $ua): bool
    {
        return (bool) preg_match('/iPhone|iPod|Android.+Mobile/i', $ua);
    }

    /** @return array{0: string, 1: string} [platform, browser] — «Windows 7», «Chrome 109» */
    public static function describeAgent(string $ua): array
    {
        $platform = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows NT 6.1') => 'Windows 7',
            str_contains($ua, 'Windows NT 10') => 'Windows 10/11',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => __('Другое'),
        };
        $browser = match (true) {
            (bool) preg_match('/Edg\/(\d+)/', $ua, $m) => 'Edge '.$m[1],
            (bool) preg_match('/(?:Chrome|CriOS)\/(\d+)/', $ua, $m) => 'Chrome '.$m[1],
            (bool) preg_match('/Firefox\/(\d+)/', $ua, $m) => 'Firefox '.$m[1],
            (bool) preg_match('/Version\/(\d+)[\d.]* .*Safari/', $ua, $m) => 'Safari '.$m[1],
            str_contains($ua, 'Safari') => 'Safari',
            default => __('Браузер'),
        };

        return [$platform, $browser];
    }

    /** Chrome ≤ 109 → offer «старый ноутбук» on the first login (TZ 04 Э29, Допущение). */
    public static function isOldChrome(string $ua): bool
    {
        return (bool) preg_match('/Chrome\/(\d+)/', $ua, $m) && (int) $m[1] <= 109 && ! str_contains($ua, 'Mobile');
    }
}
