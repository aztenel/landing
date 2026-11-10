# Незаконченная работа и план (пауза 09.11.2026)

> **10.11.2026: раунд 1 закончен и слит в код** (каркас, заявки, смены, денежные документы). Патчи `shell/` и `approvals/` больше не нужны — оставлены для истории. Актуальное — [`../STATUS.md`](../STATUS.md).

Здесь лежит то, что ещё **не слито** в основной код, и всё нужное, чтобы продолжить. Общая сводка — [`../STATUS.md`](../STATUS.md).

## Что здесь

| Путь | Что это |
|---|---|
| `shell/*.patch` | Каркас ERP (раунд 1), незаконченный, без тестов |
| `approvals/*.patch` | Заявки, уведомления, смены (раунд 1), незаконченный, без тестов |
| `plan/round-R1.json`, `round-R2.json`, `round-R3.json` | Задания модулей для раундов 1–3 |
| `plan/prime-module-round.js` | Сценарий раунда: строитель → проверяющий → слияние |
| `plan/wt-setup.sh` | Подготовка отдельной копии проекта для агента: своя ветка, своя база, свой порт |

## Как вернуть незаконченную работу (для разработчика)

Из корня репозитория, на ветке `claude/nifty-johnson-nwlyrg`:

```bash
git checkout -b wip/shell claude/nifty-johnson-nwlyrg
```

```bash
git am docs/wip/shell/*.patch
```

```bash
git checkout -b wip/approvals claude/nifty-johnson-nwlyrg
```

```bash
git am docs/wip/approvals/*.patch
```

```bash
git checkout claude/nifty-johnson-nwlyrg
```

Ветки `wip/shell` и `wip/approvals` — это точка, с которой раунд 1 продолжается (в `round-R1.json` у модулей стоит `"resume": true`).

## Как запустить раунд (для Claude)

1. Скопировать `plan/wt-setup.sh` в `/home/user/wt-setup.sh` и сделать исполняемым.
2. Проверить путь к Chrome 109 в `plan/prime-module-round.js` (константа `C109`). Он во временной папке и после нового контейнера может отсутствовать — тогда скачать заново (Chromium 109, сборка Playwright 1041).
3. Запустить Workflow со сценарием `plan/prime-module-round.js` и `args` из `round-RN.json`.
4. После раунда: проверить слияние, `php artisan test`, запушить.

**Важно после аудита (10.11.2026).** Ядро изменилось (см. `prime-mobile-os/docs/CORE.md` §14). При продолжении раунда 1:
- `approvals`: смены (`shifts` на магазин, `Shifts`, `ShiftGuard`, `documents.shift_id`), `reconciliation_runs` и команда ночной сверки, тип документа РКС вместо `shift_close` — уже в ядре; миграции WIP с теми же таблицами убрать, логику построить поверх ядра.
- `shell`: доступ к магазинам — `membership_stores` с галочками «Продажи»/«Финансы»; права просмотра бухгалтера — отдельные (`*.view`).

Когда раунд 1 будет слит, папку `docs/wip/` можно удалить.
