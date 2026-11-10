<?php

namespace App\Domain\Storefront\Services;

use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Storefront\Models\Lead;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Anonymous requests from the site (TZ 05 §11): no name, no phone, no IP. The person writes in WhatsApp with the code
 * СЗ-…; the seller finds the request by the code in the ERP and works it: take → book / sell / trade-in → close.
 */
final class Leads
{
    public const FIRST = 1001;

    public function create(string $type, ?string $code, array $payload, ?int $storeId, string $lang, ?string $source, ?string $page, ?int $price = null): Lead
    {
        abort_unless(array_key_exists($type, Lead::TYPES), 422);
        for ($try = 0; $try < 5; $try++) {
            try {
                return DB::transaction(function () use ($type, $code, $payload, $storeId, $lang, $source, $page, $price) {
                    $seq = max(self::FIRST - 1, (int) Lead::query()->max('seq')) + 1;

                    return Lead::query()->create([
                        'seq' => $seq, 'number' => 'СЗ-'.$seq, 'type' => $type, 'public_code' => $code, 'price' => $price,
                        'payload' => $payload ?: null, 'store_id' => $storeId, 'lang' => $lang === 'kk' ? 'kk' : 'ru',
                        'source' => $source ? mb_substr($source, 0, 40) : null, 'page' => $page ? mb_substr($page, 0, 200) : null,
                        'status' => 'clicked', 'created_at' => now(),
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                continue;   // two clicks at the same moment took one number — take the next
            }
        }
        throw new \RuntimeException('Could not number a site request');
    }

    public static function find(string $q): ?Lead
    {
        $n = (int) preg_replace('/\D/', '', $q);

        return $n ? Lead::query()->where('seq', $n)->first() : null;
    }

    public function take(Lead $lead, User $user): void
    {
        if ($lead->status === 'closed') {
            throw UserFacingException::because('lead_closed', __('Заявка уже закрыта'));
        }
        $lead->update(['status' => 'in_work', 'taken_by' => $user->id, 'taken_at' => now()]);
        activity('site')->causedBy($user)->withProperties(['number' => $lead->number])->log('lead.taken');
    }

    public function close(Lead $lead, string $outcome, ?string $comment, User $user, ?int $documentId = null): void
    {
        if (! array_key_exists($outcome, Lead::OUTCOMES)) {
            throw UserFacingException::because('lead_outcome', __('Выберите итог'));
        }
        $lead->update(['status' => 'closed', 'outcome' => $outcome, 'comment' => $comment ?: null, 'closed_at' => now(),
            'taken_by' => $lead->taken_by ?? $user->id, 'taken_at' => $lead->taken_at ?? now(), 'result_document_id' => $documentId ?? $lead->result_document_id]);
        activity('site')->causedBy($user)->withProperties(['number' => $lead->number, 'outcome' => $outcome])->log('lead.closed');
    }

    /** §11.3 п. 5: a sale of the unit within 7 days closes its open requests with «купил». */
    public function onSale(int $documentId, array $unitIds): void
    {
        $codes = StockUnit::query()->whereIn('id', $unitIds ?: [0])->pluck('public_code')->filter()->all();
        if (! $codes) {
            return;
        }
        Lead::query()->whereIn('public_code', $codes)->where('status', '!=', 'closed')->where('created_at', '>=', now()->subDays(7))
            ->update(['status' => 'closed', 'outcome' => 'bought', 'result_document_id' => $documentId, 'closed_at' => now()]);
    }
}
