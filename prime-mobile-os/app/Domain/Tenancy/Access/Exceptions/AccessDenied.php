<?php

namespace App\Domain\Tenancy\Access\Exceptions;

use App\Support\Exceptions\UserFacingException;

/** The user has no right for the action at all (the button should not have been shown). */
class AccessDenied extends UserFacingException
{
    public string $reason = 'access_denied';
}
