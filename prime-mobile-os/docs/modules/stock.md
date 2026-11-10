# Модуль «Склад» (stock) — Э8, Э9, Э10, Э13, Э23, Э24

| Экран | Адрес | Класс |
|---|---|---|
| Склад — Электроника (Э8) | `/stock` | `App\Filament\Stock\Pages\Phones` |
| Склад — Аксессуары (Э9) | `/stock/accessories` | `App\Filament\Stock\Pages\Accessories` |
| Паспорт телефона (Э10) | `/unit/{PM-код или IMEI}` — маршрут **`erp.stock.units.show`** | `App\Livewire\Stock\UnitPassport` |
| Прайс (Э13) | `/prices` | `App\Filament\Stock\Pages\Prices` |
| Поставщики (Э23) | `/suppliers` | `App\Filament\Stock\Pages\Suppliers` |
| Справочник (Э24) | `/catalog` | `App\Filament\Stock\Pages\Catalog` |
| Excel склада | `/stock/export?…фильтры` — `erp.stock.export` | `App\Http\Controllers\Stock\ExportController` |

## Правила

- Список и Excel строятся **одним запросом** `App\Domain\Inventory\Queries\UnitList` — в Excel ровно то, что на экране.
- Себестоимость, наценка, поставщик — только с правами `stock.view_cost` / `stock.view_supplier`: колонки не выводятся, фильтры по ним игнорируются, в Excel колонок нет. Тест проверяет HTML, состояние Livewire и файл Excel.
- Статус телефона меняется только переходами `UnitTransitions` (`UnitStatusService`) или документами. Ручного переключателя нет.
- Цена — только через `PriceService` (лимит L14, журнал `price_changes`). Больше одной цены сразу — право `price.bulk`.
- Модели общие для всех фирм: название модели меняет только владелец; варианты и списки — `catalog.edit`; казахские названия — `catalog.translate`.

## Точки для других модулей

- **Сайт**: вкладка «Сайт» паспорта и колонка «Сайт» в списке — сейчас заглушки; модуль сайта добавляет фото, флаг показа и условия П4.1.
- **Печать этикеток / ценников**: кнопки «Этикетка» выключены, пока нет модуля печати; он подключает действие по выбранным `stock_units.id`.
- **Перемещение, списание, исправление карточки, гарантия** (раунд 3): кнопки паспорта по статусу добавляются, когда у модуля есть маршрут.
- **Приход**: кнопка «+ Приход» появляется, когда есть маршрут `erp.intake.create`.
