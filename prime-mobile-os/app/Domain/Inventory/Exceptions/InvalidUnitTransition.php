<?php

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\UserFacingException;

class InvalidUnitTransition extends UserFacingException
{
    public string $reason = 'invalid_transition';
}
