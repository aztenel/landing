<?php

namespace App\Domain\Approvals\Exceptions;

use App\Domain\Tenancy\Access\AccessDecision;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;

/**
 * There WAS an approval for this document, but it cannot be used: the operation changed after the answer
 * (TZ 03 §6.5 p.1), the 15 minutes passed (§6.4), the posted value is above what was allowed («Разрешить с
 * изменением»). It is an ApprovalRequired: the screen offers to send the request again, with this message.
 */
class ApprovalInvalidated extends ApprovalRequired
{
    public string $reason = 'approval_invalid';

    public static function changed(AccessDecision $decision, string $type, bool $isSale): self
    {
        $message = match (true) {
            $type === 'discount' => __('Заявка на скидку устарела: чек изменён после одобрения. Отправьте заявку снова'),
            $isSale => __('Чек изменился после разрешения. Отправьте заявку снова'),
            default => __('Документ изменился после разрешения. Отправьте заявку снова'),
        };
        $e = new self($decision, $type, $message);
        $e->context['why'] = 'changed';

        return $e;
    }

    public static function lapsed(AccessDecision $decision, string $type, int $minutes): self
    {
        $e = new self($decision, $type, __('Разрешение действовало :min минут и истекло. Отправьте заявку снова', ['min' => $minutes]));
        $e->context['why'] = 'lapsed';

        return $e;
    }

    public static function aboveApproved(AccessDecision $decision, string $type, string $what): self
    {
        $e = new self($decision, $type, __('Разрешено не больше: :what. Исправьте и проведите снова', ['what' => $what]));
        $e->context['why'] = 'above_approved';

        return $e;
    }
}
