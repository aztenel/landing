<?php

namespace App\Support\Outbox;

/**
 * Laravel event fired by OutboxDispatcher for every outbox row, inside the organization context of the row.
 * Listeners (site sync, notifications) must be idempotent: a row can be delivered again after a failure.
 */
final class OutboxMessage
{
    public function __construct(
        public readonly int $id,
        public readonly int $organizationId,
        public readonly string $topic,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly array $payload,
    ) {}
}
