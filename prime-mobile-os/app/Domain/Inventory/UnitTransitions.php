<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Exceptions\InvalidUnitTransition;

/**
 * Allowed unit status transitions — exactly the table of TZ 02 §10.3, kept in code as ONE list and verified by
 * tests/Unit/Inventory/UnitTransitionsTest. Any transition not in the table is a server error.
 * null = «—» (the unit does not exist in stock yet / its creation was reversed).
 */
final class UnitTransitions
{
    /**
     * [from statuses, to statuses, action, TZ wording]
     *
     * @var list<array{0: list<string|null>, 1: list<string>, 2: UnitAction, 3: string}>
     */
    public const TABLE = [
        [[null], ['in_stock'], UnitAction::Receive, 'Приход (новый), Излишек, Входящие остатки'],
        [[null], ['preparing'], UnitAction::ReceiveUsed, 'Приход б/у, Акт trade-in'],
        [['preparing'], ['in_stock'], UnitAction::MarkReady, '«Готов к продаже» (кнопка, без журнала)'],
        [['in_stock'], ['preparing'], UnitAction::SendToPreparation, '«Отправить на подготовку»'],
        [['in_stock'], ['reserved'], UnitAction::Reserve, 'Добавление в корзину'],
        [['reserved'], ['in_stock'], UnitAction::ReleaseReservation, 'Удаление из корзины, истечение 30 мин'],
        [['in_stock'], ['ordered'], UnitAction::Order, 'Заказ'],
        [['ordered'], ['in_stock'], UnitAction::CancelOrder, 'Отмена заказа; истёк срок брони без предоплаты'],
        [['in_stock', 'reserved', 'ordered'], ['sold'], UnitAction::Sell, 'Продажа'],
        [['sold'], ['in_stock', 'preparing', 'on_check', 'defective'], UnitAction::CustomerReturn, 'Возврат покупателя'],
        [['on_check'], ['in_stock', 'preparing', 'defective'], UnitAction::CheckDecision, '«Решение по проверке»'],
        [['sold'], ['preparing'], UnitAction::TradeInKnown, 'Акт trade-in (наш же телефон вернулся от клиента)'],
        [['in_stock'], ['in_transit'], UnitAction::TransferSend, 'Перемещение: отправка'],
        [['in_transit'], ['in_stock'], UnitAction::TransferReceive, 'Приём / отказ'],
        [['in_stock', 'preparing', 'defective'], ['written_off'], UnitAction::WriteOff, 'Списание, Недостача'],
        [['in_stock', 'on_check', 'defective'], ['returned_to_supplier'], UnitAction::SupplierReturn, 'Возврат поставщику'],
        [['defective'], ['preparing'], UnitAction::ToRepair, '«В ремонт»'],
        // added by the audit (docs/audit/decisions.md, А6 / №3, №41)
        [[null], ['in_stock', 'preparing', 'on_check', 'defective'], UnitAction::OpeningBalance, 'Входящие остатки дня X'],
        [[null], ['in_stock', 'preparing', 'on_check', 'defective'], UnitAction::LegacyReturn, 'Возврат по продаже до перехода (ВДП)'],
        [['written_off'], ['in_stock', 'preparing'], UnitAction::SurplusKnown, 'Ревизия: найден после списания'],
        [['in_transit', 'on_check'], ['written_off'], UnitAction::WriteOff, 'Списание: потерян в пути, недостача на проверке'],
        [['preparing'], ['defective'], UnitAction::MarkDefective, '«Брак на подготовке» (кнопка, без журнала)'],
        [['preparing'], ['returned_to_supplier'], UnitAction::SupplierReturn, 'Возврат поставщику с подготовки'],
        [['returned_to_supplier'], ['in_stock', 'preparing'], UnitAction::ReceiveKnown, 'Приход того же экземпляра от поставщика'],
        // «любой → предыдущий: Сторно документа, который сделал переход» — see assertReversal()
    ];

    /** Statuses that block a reversal: the unit is held by someone's cart or an order right now. */
    private const HELD = [UnitStatus::Reserved, UnitStatus::Ordered];

    public static function allowed(?UnitStatus $from, ?UnitStatus $to, UnitAction $action): bool
    {
        if ($action === UnitAction::Reversal || $to === null) {
            return false; // reversal is checked by assertReversal(); nothing but a reversal leads to «—»
        }
        foreach (self::TABLE as [$fromList, $toList, $rowAction]) {
            if ($rowAction === $action && in_array($from?->value, $fromList, true) && in_array($to->value, $toList, true)) {
                return true;
            }
        }

        return false;
    }

    public static function assert(?UnitStatus $from, ?UnitStatus $to, UnitAction $action): void
    {
        if (! self::allowed($from, $to, $action)) {
            throw InvalidUnitTransition::because('invalid_transition', sprintf(
                'Переход «%s» → «%s» запрещён для действия «%s»',
                UnitStatus::labelOf($from), UnitStatus::labelOf($to), $action->value,
            ), ['from' => $from?->value, 'to' => $to?->value, 'action' => $action->value]);
        }
    }

    /** Reversal returns the unit exactly to the status written in the journal (status_before), possibly «—». */
    public static function assertReversal(?UnitStatus $current, ?UnitStatus $restoreTo): void
    {
        if (in_array($current, self::HELD, true)) {
            throw InvalidUnitTransition::because('unit_held', sprintf(
                'Сторно невозможно: телефон сейчас «%s». Сначала уберите его из корзины / отмените заказ',
                UnitStatus::labelOf($current),
            ));
        }
    }

    /**
     * All allowed (from, to) pairs of the table, for tests and the «паспорт» screen.
     *
     * @return list<array{0: string|null, 1: string}>
     */
    public static function pairs(): array
    {
        $pairs = [];
        foreach (self::TABLE as [$fromList, $toList]) {
            foreach ($fromList as $from) {
                foreach ($toList as $to) {
                    $pairs[($from ?? '—').'>'.$to] = [$from, $to];
                }
            }
        }

        return array_values($pairs);
    }
}
