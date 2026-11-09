<?php

namespace App\Support\Tenancy\Exceptions;

use LogicException;

/** Thrown on an attempt to write a row of another organization than the current one. */
final class CrossOrganizationViolation extends LogicException
{
    public static function make(string $model, int $rowOrganizationId, int $currentOrganizationId): self
    {
        return new self("{$model}: organization {$rowOrganizationId} does not match the current organization {$currentOrganizationId}.");
    }
}
