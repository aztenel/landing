<?php

namespace App\Domain\Ledger;

use App\Domain\Debts\Posting\DebtPaymentHandler;
use App\Domain\Inventory\Posting\PurchaseHandler;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Posting\ApprovalGate;
use App\Domain\Ledger\Posting\HandlerRegistry;
use App\Domain\Ledger\Posting\Handlers\OpeningBalanceHandler;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockManager;
use App\Domain\Ledger\Posting\NoApprovals;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Money\Posting\ExpenseHandler;
use App\Domain\Money\Posting\FxConvertHandler;
use App\Domain\Sales\Posting\CustomerReturnHandler;
use App\Domain\Sales\Posting\SaleHandler;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the core: ledgers, posting engine, the handler registry with the core document types.
 * Modules register their own handlers in their providers (see docs/CORE.md §4).
 */
class LedgerServiceProvider extends ServiceProvider
{
    /** Core handlers. Every other DocumentType answers NotImplementedHandler until a module registers one. */
    public const CORE_HANDLERS = [
        'opening_balance' => OpeningBalanceHandler::class,
        'purchase' => PurchaseHandler::class,
        'cost_entry' => \App\Domain\Inventory\Posting\CostEntryHandler::class,
        'cash_move' => \App\Domain\Money\Posting\CashMoveHandler::class,
        'sale' => SaleHandler::class,
        'customer_return' => CustomerReturnHandler::class,
        'expense' => ExpenseHandler::class,
        'fx_convert' => FxConvertHandler::class,
    ];

    public function register(): void
    {
        foreach ([InventoryLedger::class, MoneyLedger::class, DebtLedger::class, BonusLedger::class, LockManager::class, Poster::class, Reverser::class] as $class) {
            $this->app->singleton($class);
        }
        $this->app->singletonIf(ApprovalGate::class, NoApprovals::class);

        $this->app->singleton(HandlerRegistry::class, function ($app) {
            $registry = new HandlerRegistry($app);
            foreach (self::CORE_HANDLERS as $type => $handler) {
                $registry->register(DocumentType::from($type), $handler);
            }
            $registry->register(DocumentType::DebtPaymentIn, DebtPaymentHandler::incoming($app->make(MoneyLedger::class), $app->make(DebtLedger::class)));
            $registry->register(DocumentType::DebtPaymentOut, DebtPaymentHandler::outgoing($app->make(MoneyLedger::class), $app->make(DebtLedger::class)));

            return $registry;
        });
    }
}
