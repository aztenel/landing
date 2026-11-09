<?php

namespace App\Domain\Tenancy\Access;

/**
 * Answer of the single access function (TZ 03 §18 p.2): allowed / needs approval (type, limit, value) / denied.
 */
final class AccessDecision
{
    public const ALLOWED = 'allowed';

    public const APPROVAL = 'approval';

    public const DENIED = 'denied';

    private function __construct(
        public readonly string $status,
        public readonly string $permission,
        public readonly ?Limit $limit = null,
        public readonly ?int $limitValue = null,
        public readonly ?int $actualValue = null,
        public readonly string $message = '',
    ) {}

    public static function allowed(string $permission): self
    {
        return new self(self::ALLOWED, $permission);
    }

    public static function denied(string $permission, string $message): self
    {
        return new self(self::DENIED, $permission, message: $message);
    }

    public static function approval(string $permission, ?Limit $limit, ?int $limitValue, ?int $actualValue, string $message): self
    {
        return new self(self::APPROVAL, $permission, $limit, $limitValue, $actualValue, $message);
    }

    public function isAllowed(): bool
    {
        return $this->status === self::ALLOWED;
    }

    public function needsApproval(): bool
    {
        return $this->status === self::APPROVAL;
    }

    public function isDenied(): bool
    {
        return $this->status === self::DENIED;
    }
}
