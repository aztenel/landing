<?php

namespace App\Domain\Approvals\Exceptions;

use App\Support\Exceptions\UserFacingException;

/**
 * A request cannot be created / decided / cancelled for a business reason («У вас 3 заявки ждут ответа…»,
 * «Заявка уже решена», «Подтвердите PIN…»). The message is for the person at the screen.
 */
class ApprovalException extends UserFacingException
{
    public string $reason = 'approval';
}
