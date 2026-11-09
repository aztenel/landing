<?php

namespace App\Filament\Pages\Auth;

use App\Support\Phone\Phone;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Login;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Login by phone + password (TZ 03 §7.1). Users have no e-mail: users.phone (E.164) is the login.
 * Device approval, 2FA, badge/PIN are the Security module's job.
 */
class PhoneLogin extends Login
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('Телефон')
            ->tel()
            ->placeholder('+7 701 000 00 01')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        try {
            $phone = Phone::normalize((string) $data['phone']);
        } catch (InvalidArgumentException) {
            $this->throwFailureValidationException();
        }

        return ['phone' => $phone, 'password' => $data['password']];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages(['data.phone' => 'Телефон или пароль не подходят']);
    }
}
