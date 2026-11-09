<?php

namespace App\Domain\Notifications\Enums;

/**
 * Notification types of TZ 04 Э28.1 and TZ 06 §4.3 («мгновенные события владельцу»), plus the approval lifecycle
 * (TZ 03 §6). Each type has default channels; a user overrides them in notification_preferences
 * (Э26 «Уведомления»: «событие × (пуш / Telegram)»). In-app (the bell) is always on.
 */
enum NotificationType: string
{
    // approvals (TZ 03 §6.3–6.6)
    case ApprovalNew = 'approval_new';
    case ApprovalReminder = 'approval_reminder';
    case ApprovalEscalated = 'approval_escalated';
    case ApprovalDecided = 'approval_decided';
    case ApprovalDecidedByOther = 'approval_decided_by_other';
    case ApprovalExpired = 'approval_expired';
    // instant owner events (TZ 06 §4.3)
    case BelowCostSale = 'below_cost_sale';
    case DocumentReversed = 'document_reversed';
    case BigDiscount = 'big_discount';
    case ShiftDiscrepancy = 'shift_discrepancy';
    case CashNotClosed = 'cash_not_closed';
    case CustomerReturn = 'customer_return';
    case CustomerExport = 'customer_export';
    case NewDevice = 'new_device';
    case StorefrontMismatch = 'storefront_mismatch';
    case ReconciliationFailed = 'reconciliation_failed';
    // bell (TZ 04 Э28.1)
    case CollectionPending = 'collection_pending';
    case TransferIncoming = 'transfer_incoming';
    case DebtDue = 'debt_due';
    case SupplierDue = 'supplier_due';
    case OrderOverdue = 'order_overdue';
    case ImeiBlocked = 'imei_blocked';
    case WarrantyDue = 'warranty_due';
    case DailySummary = 'daily_summary';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::ApprovalNew => __('Новая заявка'),
            self::ApprovalReminder => __('Напоминание о заявке'),
            self::ApprovalEscalated => __('Заявка ждёт решения (передана админу)'),
            self::ApprovalDecided => __('Ответ на мою заявку'),
            self::ApprovalDecidedByOther => __('Решение админа по заявке'),
            self::ApprovalExpired => __('Заявка истекла'),
            self::BelowCostSale => __('Продажа ниже себестоимости проведена'),
            self::DocumentReversed => __('Сторно документа'),
            self::BigDiscount => __('Большая скидка в чеке'),
            self::ShiftDiscrepancy => __('Расхождение кассы'),
            self::CashNotClosed => __('Касса не закрыта'),
            self::CustomerReturn => __('Возврат покупателю проведён'),
            self::CustomerExport => __('Выгрузка клиентов в Excel'),
            self::NewDevice => __('Вход с нового устройства'),
            self::StorefrontMismatch => __('Сайт и склад разошлись'),
            self::ReconciliationFailed => __('Ночная проверка не сошлась'),
            self::CollectionPending => __('Инкассация ждёт приёма'),
            self::TransferIncoming => __('Входящее перемещение'),
            self::DebtDue => __('Срок долга клиента'),
            self::SupplierDue => __('Срок оплаты поставщику'),
            self::OrderOverdue => __('Заказ просрочен'),
            self::ImeiBlocked => __('Телефон заблокирован в госбазе'),
            self::WarrantyDue => __('Гарантийное обращение: срок'),
            self::DailySummary => __('Сводка дня'),
            self::System => __('Системное сообщение'),
        };
    }

    /** Default: push to the phone (PWA). Feed-only types never push (TZ 03 §6.6 p.4). */
    public function defaultPush(): bool
    {
        return ! in_array($this, [
            self::ApprovalDecidedByOther, self::ApprovalExpired, self::TransferIncoming, self::DebtDue,
            self::SupplierDue, self::WarrantyDue, self::System,
        ], true);
    }

    /** Default: the Telegram copy (TZ 01 П9 p.2, TZ 06 §4.1) — only for what the owner must not miss. */
    public function defaultTelegram(): bool
    {
        return in_array($this, [
            self::ApprovalNew, self::ApprovalReminder, self::ShiftDiscrepancy, self::CashNotClosed,
            self::ReconciliationFailed, self::DailySummary,
        ], true);
    }

    /** Types shown in the owner settings table «что присылать» (Э26 «Уведомления»). */
    public static function ownerConfigurable(): array
    {
        return [
            self::ApprovalNew, self::ApprovalReminder, self::BelowCostSale, self::DocumentReversed, self::BigDiscount,
            self::ShiftDiscrepancy, self::CashNotClosed, self::CustomerReturn, self::CustomerExport, self::NewDevice,
            self::StorefrontMismatch, self::ReconciliationFailed, self::CollectionPending, self::OrderOverdue,
            self::ImeiBlocked, self::DailySummary,
        ];
    }

    /** Lucide icon name for the bell (UI modules). */
    public function icon(): string
    {
        return match ($this) {
            self::ApprovalNew, self::ApprovalReminder, self::ApprovalEscalated, self::ApprovalDecided,
            self::ApprovalDecidedByOther, self::ApprovalExpired => 'shield-check',
            self::BelowCostSale, self::BigDiscount => 'badge-percent',
            self::DocumentReversed => 'undo-2',
            self::ShiftDiscrepancy, self::CashNotClosed, self::CollectionPending => 'wallet',
            self::CustomerReturn => 'rotate-ccw',
            self::CustomerExport => 'file-spreadsheet',
            self::NewDevice => 'laptop',
            self::StorefrontMismatch => 'globe',
            self::ReconciliationFailed => 'triangle-alert',
            self::TransferIncoming => 'truck',
            self::DebtDue, self::SupplierDue => 'hand-coins',
            self::OrderOverdue => 'calendar-clock',
            self::ImeiBlocked => 'ban',
            self::WarrantyDue => 'wrench',
            self::DailySummary => 'chart-column',
            self::System => 'info',
        };
    }
}
