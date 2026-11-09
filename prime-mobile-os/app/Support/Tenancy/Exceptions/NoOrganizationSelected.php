<?php

namespace App\Support\Tenancy\Exceptions;

use LogicException;

/** Thrown when tenant data is read or written without a selected organization (TZ 03 §12 p.2). */
final class NoOrganizationSelected extends LogicException
{
    public static function forRead(string $model): self
    {
        return new self("No organization selected: cannot query {$model}. Use CurrentOrganization::set()/run().");
    }

    public static function forWrite(string $model): self
    {
        return new self("No organization selected: cannot create {$model}. Use CurrentOrganization::set()/run().");
    }

    public static function generic(): self
    {
        return new self('No organization selected.');
    }
}
