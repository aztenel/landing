<?php

namespace App\Livewire\Shell;

use App\Domain\Shell\Services\DeviceRegistry;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Э29 «Это устройство»: the «старый ноутбук» mode (larger text, contrast borders, no animations) is a setting of the
 * DEVICE, not of the user: the shop laptop on Windows 7 keeps it for every seller. Stored on the device record (and
 * in localStorage for the first paint, see shell/layout/head-start).
 */
class DevicePrefs extends Component
{
    public bool $open = false;

    public bool $legacy = false;

    #[On('pm-open-device')]
    public function openDialog(): void
    {
        $this->legacy = (bool) app(DeviceRegistry::class)->current()?->legacy_mode;
        $this->open = true;
    }

    public function save(): void
    {
        $registry = app(DeviceRegistry::class);
        $device = $registry->current();
        if ($device) {
            $registry->setLegacyMode($device, $this->legacy, auth()->user());
        }
        $this->open = false;
        $this->dispatch('pm-legacy-changed', legacy: $this->legacy);
    }

    public function render()
    {
        return view('livewire.shell.device-prefs');
    }
}
