<?php

namespace App\Domain\Approvals\Enums;

/**
 * Life of a request (TZ 03 §6.4): pending (30 min) → approved (valid 15 min, one posting) → used; or rejected,
 * cancelled by the requester, expired (no answer in 30 min), lapsed (approved but not used in 15 min).
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Used = 'used';
    case Lapsed = 'lapsed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Ждёт ответа'),
            self::Approved => __('Разрешено'),
            self::Rejected => __('Отклонено'),
            self::Cancelled => __('Отменена'),
            self::Expired => __('Истекла'),
            self::Used => __('Использовано'),
            self::Lapsed => __('Разрешение сгорело'),
        };
    }

    public function isFinal(): bool
    {
        return ! in_array($this, [self::Pending, self::Approved], true);
    }
}
