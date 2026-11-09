<?php

namespace App\Domain\Tenancy\Access\Exceptions;

use App\Domain\Tenancy\Access\AccessDecision;
use App\Support\Exceptions\UserFacingException;

/**
 * The action exceeds a limit or is «только заявкой»: the document is NOT posted and stays a draft.
 * The Approvals module catches it, creates an approval request (type, limit, value) and later re-posts
 * the draft with documents.approval_request_id set (TZ 03 §6, TZ 01 П9).
 */
class ApprovalRequired extends UserFacingException
{
    public string $reason = 'approval_required';

    public function __construct(public readonly AccessDecision $decision, public readonly string $approvalType, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $decision->message);
        $this->context = [
            'approval_type' => $approvalType,
            'permission' => $decision->permission,
            'limit' => $decision->limit?->value,
            'limit_value' => $decision->limitValue,
            'value' => $decision->actualValue,
        ];
    }
}
