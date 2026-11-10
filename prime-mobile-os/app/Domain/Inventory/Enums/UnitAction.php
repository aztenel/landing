<?php

namespace App\Domain\Inventory\Enums;

/**
 * Business actions that change a unit status. Each maps to rows of the TZ 02 §10.3 table (UnitTransitions::TABLE).
 */
enum UnitAction: string
{
    case Receive = 'receive';                       // Приход (новый), Излишек, Входящие остатки
    case ReceiveUsed = 'receive_used';              // Приход б/у, Акт trade-in (также входящие остатки б/у)
    case MarkReady = 'mark_ready';                  // «Готов к продаже»
    case SendToPreparation = 'send_to_preparation'; // «Отправить на подготовку»
    case Reserve = 'reserve';                       // Добавление в корзину
    case ReleaseReservation = 'release_reservation'; // Удаление из корзины, истечение 30 мин
    case Order = 'order';                           // Заказ
    case CancelOrder = 'cancel_order';              // Отмена заказа; истёк срок брони без предоплаты
    case Sell = 'sell';                             // Продажа
    case CustomerReturn = 'customer_return';        // Возврат покупателя
    case CheckDecision = 'check_decision';          // «Решение по проверке»
    case TradeInKnown = 'tradein_known';            // Акт trade-in (наш же телефон вернулся)
    case TransferSend = 'transfer_send';            // Перемещение: отправка
    case TransferReceive = 'transfer_receive';      // Приём / отказ
    case WriteOff = 'write_off';                    // Списание, Недостача
    case SupplierReturn = 'supplier_return';        // Возврат поставщику
    case ToRepair = 'to_repair';                    // «В ремонт»
    case OpeningBalance = 'opening_balance';        // Входящие остатки дня X (audit №3: also on_check, defective)
    case LegacyReturn = 'legacy_return';            // Возврат по продаже до перехода ВДП (audit №3)
    case SurplusKnown = 'surplus_known';            // Ревизия: найден после списания (audit №41)
    case ReceiveKnown = 'receive_known';            // Приход того же экземпляра, вернувшегося от поставщика (audit №41)
    case MarkDefective = 'mark_defective';          // «Брак на подготовке» (кнопка, без журнала; audit №41)
    case Reversal = 'reversal';                     // Сторно документа, который сделал переход
}
