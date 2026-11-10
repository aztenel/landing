<?php

namespace App\View\Components;

use Illuminate\View\Component;

/** <x-shell-auth-layout :title="…"> — the card layout of the login-related screens (Э1). */
class ShellAuthLayout extends Component
{
    public function __construct(public ?string $title = null) {}

    public function render()
    {
        return view('shell.auth.layout');
    }
}
