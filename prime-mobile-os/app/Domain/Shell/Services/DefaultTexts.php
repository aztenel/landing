<?php

namespace App\Domain\Shell\Services;

use App\Domain\Tenancy\OrganizationSettings;

/**
 * Texts the firm can edit in Settings → «Гарантия и тексты» (TZ 04 Э26). Until the owner writes his own,
 * these drafts are used. Допущение: legal wording is not checked by a lawyer (TZ 00-kazakhstan).
 */
final class DefaultTexts
{
    public const STAFF_RULES = "Я работаю с данными клиентов только для продажи, гарантии и доставки.\n"
        ."Не фотографирую и не пересылаю документы, телефоны и ИИН клиентов, не выгружаю базу клиентов.\n"
        ."Не сообщаю никому свой пароль и PIN, не оставляю кассу открытой, когда отхожу.\n"
        .'Все мои действия в системе записываются в журнал действий.';

    public const CONSENT_RU = 'Я согласен(на) на сбор и обработку моих персональных данных (имя, телефон, история покупок) '
        .'магазином {магазин} для продажи, гарантийного обслуживания и уведомлений о покупках. Согласие можно отозвать в магазине.';

    public const WARRANTY_RU = "Гарантия на {модель}, IMEI {IMEI}, продан {дата_продажи}, действует до {гарантия_до}.\n"
        ."Гарантия не распространяется на механические повреждения, следы влаги и вскрытия.\n"
        .'Обращайтесь с этим талоном в {магазин}.';

    public const RETURN_TERMS = 'Возврат и обмен — в течение 14 дней при сохранении товарного вида, комплекта и чека.';

    public const RECEIPT_FOOTER = 'Спасибо за покупку! Prime Mobile — телефоны с гарантией.';

    public static function staffRules(OrganizationSettings $settings): string
    {
        return (string) ($settings->get('texts.staff_rules') ?: self::STAFF_RULES);
    }
}
