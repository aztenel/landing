<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Ledger\Models\Shift;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Money\Models\MoneyAccount;

/**
 * Checks the open shift at posting and reversal (audit №18), after the handler wrote its entries, inside the same
 * transaction. The open shift row is taken FOR SHARE: a shift close (FOR UPDATE of that row) waits for postings in
 * flight. Lock order: organizations → (row locks of LockManager) → shifts → document counter.
 */
final class ShiftGuard
{
    /** @return int|null id of the open shift of the document's store (stored as documents.shift_id) */
    public static function check(Document $document, ShiftRequirement $requirement, Document $entriesOf): ?int
    {
        $stores = [];
        if ($requirement === ShiftRequirement::Always && $document->store_id) {
            $stores[] = (int) $document->store_id;
        }
        if ($requirement !== ShiftRequirement::Never) {
            $cashStores = MoneyAccount::query()
                ->whereIn('id', MoneyEntry::query()->where('document_id', $entriesOf->id)->select('account_id'))
                ->where('type', 'cash')->whereNotNull('store_id')->pluck('store_id')->map(fn ($id) => (int) $id)->all();
            $stores = array_merge($stores, $cashStores);
        }
        $stores = array_values(array_unique($stores));
        sort($stores);

        $own = null;
        foreach ($stores as $storeId) {
            $shift = Shift::query()->where('store_id', $storeId)->where('status', 'open')->lock('for share')->first();
            if (! $shift) {
                throw PostingException::because('shift_closed', 'Смена не открыта. Откройте смену', ['store_id' => $storeId]);
            }
            if ($storeId === (int) $document->store_id) {
                $own = (int) $shift->id;
            }
        }
        if ($own === null && $document->store_id) {
            $own = Shift::query()->where('store_id', $document->store_id)->where('status', 'open')->value('id');
        }

        return $own === null ? null : (int) $own;
    }
}
