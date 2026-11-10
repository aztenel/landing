# UI-KIT — как строить экраны Prime Mobile OS

Каркас ERP (модуль `shell`). Всё ниже уже работает и проверено в Chrome 109 (Windows 7) и в эмуляции iPhone. Код — английский, тексты интерфейса — русские через `__('…')`.

## 1. Запуск и вход (локально)

```bash
php artisan migrate:fresh --seed
```

```bash
npm run build
```

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

- ERP: `http://erp.localhost:8000` (Chrome сам ведёт `*.localhost` на 127.0.0.1). Адрес домена — `PRIME_ERP_DOMAIN`, сайта — `PRIME_SHOP_DOMAIN` (`config/prime.php`).
- Демо-вход: Кайрат (владелец) `+7 701 000 00 01`, продавцы Айгерим `…02` и Данияр `…03`, пароль `password` (только не в production).
- Камера-сканер работает только по `https` (или на `localhost`).
- Тесты рендерят страницы без сборки Vite (`withoutVite()` в `tests/TestCase.php`). После новых классов Tailwind в Blade — `npm run build`, иначе стиль не появится.

## 2. Новый экран (Filament-страница)

Файл в `app/Filament/<Модуль>/Pages/` — подхватывается сам (`ShellPlugin`, PanelProvider не трогать).

```php
class Sale extends \Filament\Pages\Page
{
    use \App\Filament\Shell\Concerns\HasErpAccess;

    protected static string|array|null $permission = 'sale.create';      // нет права → нет пункта меню и 403
    protected static ?\App\Support\Ui\MenuGroup $menuGroup = \App\Support\Ui\MenuGroup::Work;  // Работа / Учёт / Управление
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?int $navigationSort = 20;
    protected static ?string $slug = 'sale';                              // адрес из ТЗ 04 §1
    protected static string $view = 'pos.pages.sale';
}
```

- **Каждое действие** Livewire проверяет право на сервере: `\App\Support\Ui\Access::authorize('sale.discount')` или через документ (`Poster` → `$ctx->authorize`). Публичные свойства Livewire подделываются из браузера — цену, скидку, id не брать на веру.
- Себестоимость и поставщик скрываются сами при загрузке моделей (`HasSensitiveFields`, CORE §14), но не выводите их в Blade без `Access::allows('stock.view_cost')`.
- Ресурсы (`Resources/`), виджеты (`Widgets/`), кластеры (`Clusters/`) — так же по папкам модуля.

## 3. Маршруты, переводы, места в шапке

- Маршруты модуля: `routes/modules/<slug>.php`, внутри `App\Support\Routing\ModuleRoutes::erp(fn () => …)` (ERP-домен, авторизация каркаса), `ModuleRoutes::erpGuest(…)` (без входа), для сайта — `ModuleRoutes::shop(…)`.
- Казахский перевод: `lang/modules/<slug>/kk.json` (ключ — русский текст).
- Места каркаса (`App\Support\Ui\ShellHook`): `TOPBAR_BELL` (колокольчик модуля заявок), `TOPBAR_STATUS` (смена), `TOPBAR_RATE` (курс), `SIDEBAR_STORE_STATUS`, `SIDEBAR_FOOTER`, `USER_MENU_ITEMS`, `MOBILE_HEADER_END`, `BANNER`:

```php
FilamentView::registerRenderHook(ShellHook::TOPBAR_BELL, fn (): string => Blade::render('@livewire(\App\Livewire\Approvals\Bell::class)'));
```

## 4. Горячие клавиши (ТЗ 04 §0.6)

Страница отдаёт `hotkeys()`; глобальный диспетчер (`resources/js/erp.js`) выполняет и показывает их по «?»:

```php
public function hotkeys(): array
{
    return [
        \App\Support\Ui\Hotkey::make('F9', __('Оплатить'))->call('openPayment'),
        \App\Support\Ui\Hotkey::make('F2', __('Скан'))->focus('#pos-search'),
    ];
}
```

Разрешённые клавиши: F2, F3, F4, F6–F10, Ctrl+Enter, Ctrl+P, Esc.

## 5. Сканер

- **USB-сканер** работает везде сам: быстрый ввод с Enter в конце превращается в событие `pm-scan` (поле поиска не засоряется).
- **Камера iPhone**: `PM.scanner.open()` (кнопка в нижней панели уже есть). Code-128, EAN-13, QR, фонарик, ручной ввод.
- Подписка на странице (Alpine):

```html
<div x-data x-on:pm-scan.window="$wire.addByCode($event.detail.code)"></div>
```

`detail`: `{code, kind: 'imei'|'barcode'|'text', valid, source: 'usb'|'camera'|'manual'}`. IMEI проверяется по Луну (`PM.isImei`). Автодобавление через 0,8 с — `PM.autoAdd(() => …)`.

## 6. Компоненты (`<x-ui::…>`)

| Компонент | Пример |
|---|---|
| Логотип | `<x-ui::logo />`, `<x-ui::logo :wordmark="false" />` |
| Иконка Lucide | `<x-ui::icon name="scan" class="h-5 w-5" />` (только набор Lucide — `App\Support\Ui\Icon`) |
| Клавиша | `<x-ui::kbd>F9</x-ui::kbd>` |
| Аватар | `<x-ui::avatar :name="$user->name" />` |

Деньги — `Money::format()` («489 000 ₸»), IMEI — класс `num` / `font-mono`. Цвета — токены: `bg-background`, `bg-card`, `text-muted-foreground`, `border-border`, `bg-primary` (акцент фирмы), `text-ok` / `text-warn` / `text-bad`. Никаких `color-mix`, `oklch`, container queries — Chrome 109.

## 7. iPhone и «старый ноутбук»

- Ширина меньше 1024 px — iPhone-вид: шапка с логотипом, нижняя панель вкладок (пункты меню по правам + «Ещё»), кнопка скана по центру. Касание ≥ 44 px, поля ≥ 16 px (иначе Safari зумит).
- Брейкпоинты темы: `desk` 1024, `wide` 1280 (меню развёрнуто), `hd` 1440.
- Режим «старый ноутбук» — класс `legacy` на `<html>` (настройка устройства, Э29). Chrome ≤ 109 получает предложение включить его.
- Установка как приложение: Chrome «Установить приложение», iPhone «На экран Домой» (`public/manifest.webmanifest`).

## 8. Что уже есть в каркасе

Э1 вход по телефону, первый вход, приглашение, блокировка экрана, выбор фирмы · Э2 рамка «Статистики» (цифры — модуль отчётов) · Э22 курс доллара · Э25 сотрудники, роли с лимитами, устройства · Э26 настройки фирмы · Э27 журнал действий · Э29 «Это устройство». Скриншоты — `docs/screens/shell/`.
