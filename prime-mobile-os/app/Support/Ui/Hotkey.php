<?php

namespace App\Support\Ui;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A page hotkey (TZ 04 §0.6). A page returns them from `hotkeys()` (trait HasErpAccess / interface
 * HasHotkeys); the global dispatcher (resources/js/shell/hotkeys.js) runs the action and lists the key
 * in the «?» cheat sheet.
 *
 *   Hotkey::make('F9', __('Оплатить'))->click('[data-hotkey="pay"]')
 *   Hotkey::make('F8', __('Trade-in'))->call('openTradeIn')          // Livewire method of the page
 *   Hotkey::make('F3', __('Новый клиент'))->dispatch('new-customer')  // Livewire/browser event
 *   Hotkey::make('F2', __('Скан'))->focus('[data-scan-input]')
 *
 * F1, F5, F11, F12 are never taken (help, reload, full screen, DevTools).
 */
final class Hotkey implements JsonSerializable
{
    public const ALLOWED = ['F2', 'F3', 'F4', 'F6', 'F7', 'F8', 'F9', 'F10', 'Ctrl+Enter', 'Ctrl+P', 'Esc'];

    private string $action = 'click';

    private string $target = '';

    private function __construct(public readonly string $key, public readonly string $label) {}

    public static function make(string $key, string $label): self
    {
        if (! in_array($key, self::ALLOWED, true)) {
            throw new InvalidArgumentException("Hotkey {$key} is not allowed (TZ 04 §0.6).");
        }

        return new self($key, $label);
    }

    public function click(string $selector): self
    {
        return $this->with('click', $selector);
    }

    public function focus(string $selector): self
    {
        return $this->with('focus', $selector);
    }

    public function call(string $livewireMethod): self
    {
        return $this->with('call', $livewireMethod);
    }

    public function dispatch(string $event): self
    {
        return $this->with('dispatch', $event);
    }

    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'action' => $this->action, 'target' => $this->target];
    }

    private function with(string $action, string $target): self
    {
        $this->action = $action;
        $this->target = $target;

        return $this;
    }
}
