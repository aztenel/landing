<?php

namespace App\Support\Ui;

/**
 * Named places of the ERP frame where modules put their own pieces (Filament render hooks):
 *
 *   FilamentView::registerRenderHook(ShellHook::TOPBAR_BELL, fn (): string => Blade::render('@livewire(\App\Livewire\Approvals\Bell::class)'));
 *
 * When nothing is registered the shell renders its default (bell without notifications, store info, rate chip).
 */
final class ShellHook
{
    /** Bell with the red dot and its panel (module «Заявки», Э28). Default: bell «Уведомлений нет». */
    public const TOPBAR_BELL = 'prime::topbar.bell';

    /** Left of the rate chip: shift status, short info (module «Смены»). */
    public const TOPBAR_STATUS = 'prime::topbar.status';

    /** Replaces the rate chip. Default: App\Livewire\Shell\RateDialog chip (Э22). */
    public const TOPBAR_RATE = 'prime::topbar.rate';

    /** Second line of the store plate in the sidebar: «Смена с 09:00» (module «Смены»). Default: «N магазин». */
    public const SIDEBAR_STORE_STATUS = 'prime::sidebar.store-status';

    /** Under the menu groups, above the user block. */
    public const SIDEBAR_FOOTER = 'prime::sidebar.footer';

    /** Extra items of the user menu (above «Выйти»), Blade of <x-ui::menu-item>. */
    public const USER_MENU_ITEMS = 'prime::user-menu.items';

    /** Right side of the iPhone header (left of the avatar). */
    public const MOBILE_HEADER_END = 'prime::mobile.header-end';

    /** Thin bar on top of every page (support access, training copy). */
    public const BANNER = 'prime::banner';
}
