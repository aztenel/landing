<?php

namespace App\Domain\Tenancy\Access;

/**
 * The global list of permissions (TZ 03 §3 matrix + §4.1 visibility switches). Only developers change it.
 *
 * Matrix cell codes (columns: owner, admin, senior_seller, seller, accountant):
 *   Y ✔ allowed · L allowed within a limit (§5) · A only via approval («З») · V view only (👁)
 *   S own store only («М») · O own records only («С») · - not allowed
 *
 * A role gets the spatie permission for every cell except '-'. Cells 'A' additionally store
 * role_limits(code = permission, value = 0) → AccessChecker answers «нужна заявка» every time.
 * Scope cells (S/O/V) are kept as metadata: store scope is enforced by memberships.store_ids,
 * own-records/view-only — by the screens of the modules.
 */
final class PermissionCatalog
{
    public const GROUPS = [
        'sale' => 'Продажа',
        'customers' => 'Клиенты, долги, бонусы',
        'stock' => 'Склад и товар',
        'prices' => 'Цены и сайт',
        'tradein' => 'Trade-in',
        'money' => 'Деньги',
        'documents' => 'Документы',
        'reports' => 'Отчёты',
        'settings' => 'Настройки и безопасность',
        'visibility' => 'Видимость полей',
    ];

    /**
     * code => [group, title, owner, admin, senior_seller, seller, accountant]
     */
    public const MATRIX = [
        // 3.1 Продажа (касса)
        'shift.open_close' => ['sale', 'Открыть / закрыть смену, ввести наличные', 'Y', 'S', 'S', 'S', '-'],
        'sale.create' => ['sale', 'Продажа: скан, корзина, оплата', 'Y', 'S', 'S', 'S', '-'],
        'sale.discount' => ['sale', 'Скидка на строку или чек', 'Y', 'L', 'L', 'L', '-'],
        'sale.below_cost' => ['sale', 'Продажа ниже себестоимости', 'Y', 'A', 'A', 'A', '-'],
        'sale.price_up' => ['sale', 'Изменить цену в чеке вверх', 'Y', 'Y', 'Y', 'Y', '-'],
        'sale.credit' => ['sale', 'Продажа в долг клиенту', 'Y', 'L', 'L', 'L', '-'],
        'sale.no_imei_check' => ['sale', 'Продажа телефона без отметки госбазы', 'Y', 'A', 'A', 'A', '-'],
        'sale.reserve' => ['sale', 'Резерв в корзине (30 мин)', 'Y', 'Y', 'Y', 'Y', '-'],
        'order.create' => ['sale', 'Заказ (бронь) с предоплатой', 'Y', 'S', 'S', 'S', '-'],
        'order.cancel_any' => ['sale', 'Отменить чужой резерв / заказ', 'Y', 'Y', 'Y', 'A', '-'],
        'return.accessory' => ['sale', 'Возврат покупателю: аксессуары', 'Y', 'L', 'L', 'A', '-'],
        'return.phone' => ['sale', 'Возврат покупателю: телефон', 'Y', 'L', 'A', 'A', '-'],
        'return.exchange' => ['sale', 'Обмен (возврат + новая продажа)', 'Y', 'L', 'A', 'A', '-'],
        'sale.fiscal_mark' => ['sale', 'Номер фискального чека, «чек пробит»', 'Y', 'Y', 'Y', 'Y', '-'],
        'sale.reprint' => ['sale', 'Повторная печать чека / гарантийного талона', 'Y', 'Y', 'Y', 'O', '-'],
        'sale.send' => ['sale', 'Отправить чек и гарантию клиенту', 'Y', 'Y', 'Y', 'Y', '-'],
        'sale.set_seller' => ['sale', 'Выбрать другого продавца в чеке', 'Y', 'Y', 'Y', '-', '-'],
        // 3.2 Клиенты, долги клиентов, бонусы
        'customer.create' => ['customers', 'Создать клиента, найти по телефону', 'Y', 'Y', 'Y', 'Y', 'V'],
        'customer.edit' => ['customers', 'Изменить имя/телефон клиента', 'Y', 'Y', 'Y', 'O', '-'],
        'customer.list' => ['customers', 'Список всех клиентов, поиск по имени', 'Y', 'Y', 'Y', 'Y', 'Y'],
        'customer.export' => ['customers', 'Выгрузка клиентов в Excel', 'Y', 'A', '-', '-', 'Y'],
        'customer.merge' => ['customers', 'Объединить дубли клиентов', 'Y', 'Y', '-', '-', '-'],
        'customer.privacy' => ['customers', 'Обезличить клиента / выгрузить его данные', 'Y', '-', '-', '-', '-'],
        'debt.customer_pay' => ['customers', 'Принять оплату долга клиента', 'Y', 'Y', 'Y', 'Y', '-'],
        'debt.customer_postpone' => ['customers', 'Перенести срок долга', 'Y', 'Y', 'L', 'A', '-'],
        'debt.customer_adjust' => ['customers', 'Корректировка долга вручную', 'Y', 'A', '-', '-', '-'],
        'debt.customer_report' => ['customers', 'Отчёт «Долги клиентов»', 'Y', 'Y', 'Y', 'V', 'Y'],
        'bonus.redeem' => ['customers', 'Бонусы: списать при оплате', 'Y', 'Y', 'Y', 'Y', '-'],
        'bonus.manual' => ['customers', 'Бонусы вручную', 'Y', 'L', '-', '-', '-'],
        'bonus.settings' => ['customers', 'Правила бонусов', 'Y', '-', '-', '-', '-'],
        // 3.3 Склад и товар
        'stock.receive' => ['stock', 'Приход электроники по IMEI', 'Y', 'S', 'S', 'S', '-'],
        'stock.cost_edit' => ['stock', 'Ввести / изменить себестоимость в приходе', 'Y', 'Y', '-', '-', '-'],
        'stock.receive_acc' => ['stock', 'Приход аксессуаров', 'Y', 'Y', 'Y', 'Y', '-'],
        'stock.import' => ['stock', 'Импорт прихода из Excel', 'Y', 'Y', '-', '-', '-'],
        'stock.receive_on_credit' => ['stock', 'Приход в долг поставщику', 'Y', 'Y', '-', '-', '-'],
        'stock.imei_check' => ['stock', 'Отметка госбазы IMEI', 'Y', 'Y', 'Y', 'Y', '-'],
        'stock.prepare' => ['stock', 'Б/у: «На подготовку» / «Готов к продаже»', 'Y', 'Y', 'Y', 'Y', '-'],
        'stock.prepare_cost' => ['stock', 'Затраты на подготовку б/у', 'Y', 'Y', '-', '-', '-'],
        'transfer.send' => ['stock', 'Перемещение: отправить', 'Y', 'Y', 'Y', 'S', '-'],
        'transfer.accept' => ['stock', 'Перемещение: принять / отказать', 'Y', 'Y', 'S', 'S', '-'],
        'revision.scan' => ['stock', 'Ревизия сканером: сканировать', 'Y', 'Y', 'Y', 'Y', '-'],
        'revision.post' => ['stock', 'Ревизия: провести итог', 'Y', 'Y', '-', '-', '-'],
        'stock.write_off' => ['stock', 'Списание', 'Y', 'L', 'A', 'A', '-'],
        'stock.return_supplier' => ['stock', 'Возврат поставщику', 'Y', 'Y', '-', '-', '-'],
        'stock.passport' => ['stock', 'Паспорт телефона', 'Y', 'Y', 'Y', 'Y', 'V'],
        'stock.other_stores' => ['stock', 'Остатки других магазинов фирмы', 'Y', 'Y', 'Y', 'Y', 'V'],
        'catalog.edit' => ['stock', 'Справочник: модели, память, цвет, ОЗУ', 'Y', 'Y', '-', '-', '-'],
        'catalog.translate' => ['stock', 'Казахские названия товаров', 'Y', 'Y', 'Y', '-', '-'],
        'supplier.edit' => ['stock', 'Поставщики: создать, изменить', 'Y', 'Y', '-', '-', '-'],
        'label.print' => ['stock', 'Печать ценников и термоэтикеток', 'Y', 'Y', 'Y', 'Y', '-'],
        'stock.unit_edit' => ['stock', 'Исправить карточку экземпляра', 'Y', 'Y', 'A', 'A', '-'],
        'stock.cost_adjust' => ['stock', 'Корректировка себестоимости', 'Y', '-', '-', '-', '-'],
        'catalog.services' => ['stock', 'Услуги и акции «подарок к покупке»', 'Y', 'Y', '-', '-', '-'],
        // 3.4 Цены и онлайн-каталог
        'price.edit' => ['prices', 'Изменить розничную цену', 'Y', 'L', 'A', '-', '-'],
        'price.bulk' => ['prices', 'Массовая смена цен', 'Y', 'A', '-', '-', '-'],
        'price.kaspi' => ['prices', 'Цена для Kaspi-магазина', 'Y', 'Y', '-', '-', '-'],
        'site.photo' => ['prices', 'Фото телефона для сайта', 'Y', 'Y', 'Y', 'Y', '-'],
        'site.visibility' => ['prices', 'Показать / скрыть товар на сайте', 'Y', 'Y', 'Y', '-', '-'],
        'site.order' => ['prices', 'Порядок товаров, «хит»', 'Y', 'Y', '-', '-', '-'],
        'site.description' => ['prices', 'Описание, грейд, батарея', 'Y', 'Y', 'Y', 'Y', '-'],
        'site.content' => ['prices', 'Баннеры, акции, тексты сайта', 'Y', 'Y', '-', '-', '-'],
        'site.rules' => ['prices', 'Видимость категорий / брендов на сайте', 'Y', 'Y', '-', '-', '-'],
        'site.leads' => ['prices', 'Заявки с сайта', 'Y', 'Y', 'Y', 'Y', '-'],
        'site.settings' => ['prices', 'Настройки сайта', 'Y', '-', '-', '-', '-'],
        // 3.5 Trade-in
        'tradein.create' => ['tradein', 'Акт trade-in', 'Y', 'Y', 'Y', 'Y', '-'],
        'tradein.buyout' => ['tradein', 'Выкуп б/у за наличные из кассы', 'Y', 'L', 'L', 'L', '-'],
        'tradein.over_grid' => ['tradein', 'Выкуп дороже сетки цен', 'Y', 'L', 'L', 'A', '-'],
        'tradein.grid' => ['tradein', 'Сетка цен скупки', 'Y', '-', '-', '-', '-'],
        'tradein.hint' => ['tradein', 'Подсказка цен выкупа/продажи за 90 дней', 'Y', 'Y', 'Y', 'Y', '-'],
        // 3.6 Деньги
        'money.balance' => ['money', 'Баланс: остатки касс и счетов', 'Y', 'S', 'V', 'V', 'V'],
        'expense.create' => ['money', 'Расход из кассы магазина', 'Y', 'L', 'L', 'L', '-'],
        'expense.bank' => ['money', 'Расход со счёта (безнал)', 'Y', 'L', '-', '-', '-'],
        'expense.articles' => ['money', 'Статьи расходов', 'Y', 'Y', '-', '-', 'Y'],
        'money.collect' => ['money', 'Инкассация', 'Y', 'Y', 'Y', '-', '-'],
        'money.transfer' => ['money', 'Перевод между счетами, обмен валюты', 'Y', 'Y', '-', '-', '-'],
        'rate.set' => ['money', 'Курс доллара', 'Y', 'Y', '-', '-', '-'],
        'debt.supplier' => ['money', 'Оплата поставщику, долги поставщикам', 'Y', 'Y', '-', '-', 'V'],
        'money.adjust' => ['money', 'Корректировка кассы', 'Y', 'L', '-', '-', '-'],
        'money.accounts' => ['money', 'Счета и комиссии', 'Y', '-', '-', '-', '-'],
        'payroll.manage' => ['money', 'Начисления продавцам, выплаты', 'Y', '-', '-', '-', 'V'],
        'payroll.own' => ['money', 'Свой заработок', 'Y', 'Y', 'Y', 'Y', '-'],
        // 3.7 Документы
        'doc.reverse' => ['documents', 'Сторно проведённого документа', 'Y', 'L', 'A', 'A', '-'],
        'doc.backdate' => ['documents', 'Документ задним числом (до 3 дней)', 'Y', 'Y', '-', '-', '-'],
        'period.close' => ['documents', 'Закрыть период (месяц)', 'Y', '-', '-', '-', 'V'],
        'doc.drafts_any' => ['documents', 'Черновики других сотрудников', 'Y', 'Y', 'S', '-', '-'],
        'doc.opening_balance' => ['documents', 'Входящие остатки (день X)', 'Y', '-', '-', '-', '-'],
        // 3.8 Отчёты
        'report.dashboard' => ['reports', '«Статистика» владельца', 'Y', '-', '-', '-', 'Y'],
        'report.own' => ['reports', 'Мои продажи за день / смену', 'Y', 'Y', 'Y', 'Y', '-'],
        'report.sales' => ['reports', 'Продажи магазина без прибыли', 'Y', 'Y', 'Y', '-', 'Y'],
        'report.profit' => ['reports', 'Прибыль, себестоимость продаж, маржа', 'Y', '-', '-', '-', 'Y'],
        'report.stock_value' => ['reports', 'Остатки в деньгах по себестоимости', 'Y', 'Y', '-', '-', 'Y'],
        'report.aging' => ['reports', '«Залежался», «заканчивается»', 'Y', 'Y', 'Y', '-', '-'],
        'report.used' => ['reports', 'Отчёт по б/у', 'Y', 'Y', '-', '-', 'Y'],
        'report.no_fiscal' => ['reports', '«Продажи без фискального чека»', 'Y', 'Y', 'Y', '-', 'Y'],
        'report.approvals' => ['reports', '«Заявки за период»', 'Y', 'Y', '-', '-', '-'],
        'report.export' => ['reports', 'Экспорт отчётов в Excel', 'Y', 'Y', '-', '-', 'Y'],
        'org.export_all' => ['reports', 'Полная выгрузка фирмы', 'Y', '-', '-', '-', '-'],
        // 3.9 Настройки, сотрудники, безопасность
        'approval.decide' => ['settings', 'Решать заявки', 'Y', 'L', '-', '-', '-'],
        'staff.manage' => ['settings', 'Сотрудники: пригласить, роль, лимиты', 'Y', '-', '-', '-', '-'],
        'staff.dismiss' => ['settings', 'Заблокировать / уволить сотрудника', 'Y', 'A', '-', '-', '-'],
        'role.manage' => ['settings', 'Роли: создать, изменить', 'Y', '-', '-', '-', '-'],
        'device.manage' => ['settings', 'Устройства', 'Y', 'Y', '-', '-', '-'],
        'audit.view' => ['settings', 'Журнал действий', 'Y', 'S', '-', '-', 'V'],
        'org.settings' => ['settings', 'Настройки фирмы', 'Y', 'Y', '-', '-', '-'],
        'org.security' => ['settings', 'Правила безопасности', 'Y', '-', '-', '-', '-'],
        'org.support_access' => ['settings', 'Допуск интегратора', 'Y', '-', '-', '-', '-'],
        'device.prefs' => ['settings', 'Настройки своего устройства', 'Y', 'Y', 'Y', 'Y', 'Y'],
        // 4.1 Видимость полей (переключатели «Видимость», TZ 03 §16.1 п. 3)
        'stock.view_cost' => ['visibility', 'Себестоимость экземпляра / средняя аксессуара', 'Y', 'Y', '-', '-', 'Y'],
        'stock.view_supplier' => ['visibility', 'Поставщик, курс и валюта закупа', 'Y', 'Y', '-', '-', 'Y'],
        'profit.view' => ['visibility', 'Прибыль строки / чека / дня, наценка', 'Y', '-', '-', '-', 'Y'],
        'customer.view_iin' => ['visibility', 'ИИН полностью', 'Y', 'Y', '-', '-', '-'],
        'money.view_bank_balances' => ['visibility', 'Остатки безналичных счетов', 'Y', 'Y', '-', '-', 'Y'],
    ];

    private const ROLE_COLUMNS = [
        RoleTemplates::OWNER => 2,
        RoleTemplates::ADMIN => 3,
        RoleTemplates::SENIOR_SELLER => 4,
        RoleTemplates::SELLER => 5,
        RoleTemplates::ACCOUNTANT => 6,
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::MATRIX);
    }

    public static function title(string $code): string
    {
        return self::MATRIX[$code][1] ?? $code;
    }

    public static function group(string $code): string
    {
        return self::MATRIX[$code][0] ?? 'other';
    }

    /** Matrix cell for a role template: Y, L, A, V, S, O or '-'. */
    public static function mode(string $code, string $role): string
    {
        return self::MATRIX[$code][self::ROLE_COLUMNS[$role]] ?? '-';
    }

    /** @return list<string> permissions granted to a role template */
    public static function grantedTo(string $role): array
    {
        return array_values(array_filter(self::codes(), fn ($code) => self::mode($code, $role) !== '-'));
    }

    /** @return list<string> permissions that a role template may use only via approval («З») */
    public static function approvalOnlyFor(string $role): array
    {
        return array_values(array_filter(self::codes(), fn ($code) => self::mode($code, $role) === 'A'));
    }
}
