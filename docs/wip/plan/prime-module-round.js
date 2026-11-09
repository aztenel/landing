export const meta = {
  name: 'prime-module-round',
  description: 'Build Prime Mobile OS modules in isolated worktrees, adversarially review each, then merge, verify and push',
  whenToUse: 'One development round of Prime Mobile OS: args = {round, modules:[{slug,title,brief,port,mig}]}',
  phases: [
    { title: 'Build', detail: 'one builder per module in its own git worktree' },
    { title: 'Review', detail: 'adversarial review + fixes on top of the builder branch' },
    { title: 'Merge', detail: 'merge into claude/nifty-johnson-nwlyrg, full tests, smoke, push' },
  ],
}

const R = args
const SETUP = '/home/user/wt-setup.sh'
const C109 = '/tmp/claude-0/-home-user-landing/b152ef79-188a-5b56-9ca1-4c2f4a735f00/scratchpad/c109/r1041/chrome-linux/chrome'

const COMMON = `Ты — старший Laravel/Filament-разработчик проекта Prime Mobile OS: своя система для магазина телефонов Prime Mobile (Казахстан, владелец Кайрат) вместо облачной sfon.kz. Делаем версию 1.0 под сдачу заказчику, НЕ MVP: всё в твоём объёме должно работать полностью — с правами, понятными ошибками, iPhone-видом и тестами.

## Где ты работаешь
- Ты в отдельном git worktree (проверь \`pwd\`; это НЕ /home/user/landing). Главная копия /home/user/landing — только читать: ничего там не меняй и не запускай там git-команд, меняющих состояние.
- Параллельно с тобой в другом worktree работает другой агент над другим модулем — не рассчитывай на его код в этом раунде.
- PostgreSQL 16 и Redis уже запущены. Пакеты НЕ добавлять (composer require / npm install запрещены). Уже есть: Laravel 12, Filament 3.3, Livewire 3 (+Alpine), spatie/laravel-permission (teams = organization), spatie/laravel-activitylog, openspout/openspout (Excel), chillerlan/php-qrcode, picqer/php-barcode-generator, @zxing/browser + @zxing/library (скан камерой), Chart.js (внутри Filament), Pest 3, Tailwind 3.4, Vite (target chrome109/safari15). PHP: gd, intl, exif, zip, redis, pgsql.

## Что прочитать до кода (обязательно)
- prime-mobile-os/ENGINEERING.md — правила.
- prime-mobile-os/docs/CORE.md — ядро: §0, §1, §3–§6, §11–§13 (проведение документов, LockPlan, права, скрытие себестоимости, ограничения).
- prime-mobile-os/docs/UI-KIT.md и prime-mobile-os/docs/modules/*.md, prime-mobile-os/docs/MODULES.md — если есть (их пишут модули прошлых раундов): как добавлять экраны, общие компоненты, контракты других модулей. Пользуйся ими, не делай второй вариант того же.
- Разделы ТЗ из своей задачи (docs/tz/*.md) — по своим пунктам ЦЕЛИКОМ (ищи их через grep по номерам Э…/П…/О…/Ф…/§). «Единые решения» в docs/tz/README.md главнее разделов.
- Макеты: docs/design/screens/**/*.png (смотри картинки инструментом Read) и их исходники docs/design/src/erp.py, mobile.py, vitrina/vitrina.py — палитра, отступы, классы Tailwind, логотип (logo_mark, SVG), иконки Lucide. Экран должен выглядеть как макет, а не как «Filament по умолчанию».

## Люди и устройства
- Ноутбук магазина: Windows 7 + Chrome 109, экран 1366×768. Всё обязано работать там: никаких color-mix(), oklch(), container queries, CSS nesting, JS новее ES2020 без сборки. Chrome 109 для проверки: ${C109} (Playwright: chromium.launch({executablePath: '<путь>', args: ['--no-sandbox']})). Обычный Chromium: require('playwright').chromium.launch().
- Продавцы: iPhone 12+ (Safari). Владелец: iPhone 17 Pro Max. Касание ≥ 44 px, шрифт полей ≥ 16 px (иначе Safari зумит), safe-area, без горизонтальной прокрутки.
- Пользователи не айтишники: тексты простые, русские; ошибка = что случилось + что делать (тексты ошибок из ТЗ 04 — дословно).

## Правила кода (подробно — ENGINEERING.md, CORE.md)
- Код и комментарии — английский. Тексты интерфейса — русский через __('Русский текст'); казахский перевод — lang/modules/<slug>/kk.json (загрузчик описан в UI-KIT.md; если UI-KIT.md ещё нет — просто пиши через __()).
- Остатки, деньги, долги, бонусы меняются только документами через Poster/Reverser и Ledgers ядра. Никаких прямых UPDATE балансов и INSERT в журналы.
- У каждой бизнес-таблицы organization_id + трейт BelongsToOrganization; составные FK (organization_id, x_id) как в ядре; id из запроса — через LockPlan / проверку фирмы.
- Деньги — Money (целые тиыны), курс — Rate. Никаких float для денег.
- Права — AccessChecker / $ctx->authorize. Навигация и кнопки скрыты без права, И КАЖДОЕ действие Livewire/Filament проверяет право на сервере: публичные свойства Livewire подделываются из браузера (цена, скидка, id чужой фирмы — не верить клиенту).
- Себестоимость, поставщик, прибыль не попадают в HTML/JSON/Livewire-snapshot без права (forViewer / hideForViewer / hiddenFieldsFor).
- Где класть: домен app/Domain/<Module>/..., Filament app/Filament/<Module>/..., Livewire app/Livewire/<Module>/..., шаблоны resources/views/<slug>/..., маршруты routes/modules/<slug>.php (или как в UI-KIT.md), тесты tests/Feature/<Module>/, tests/Unit/<Module>/. Миграции: database/migrations/<метка>_<module>_<what>.php, метки времени ТОЛЬКО из твоего диапазона (дан в задаче).
- Общие файлы (AdminPanelProvider, bootstrap/providers.php, DatabaseSeeder, routes/web.php, routes/console.php, app.css, tailwind.config.js, AppServiceProvider, config/prime.php) — только добавлением строк. Чужие модули не переписывай; если без этого никак — минимально и опиши в отчёте.
- Демо-данные модуля — свой сидер (вызов добавь в DatabaseSeeder внутри ветки «не production»), чтобы экраны выглядели как на макетах.

## Проверка перед сдачей (обязательно всё)
1. \`php artisan migrate:fresh --seed\` проходит (своя БД из .env).
2. \`DB_DATABASE=prime_mobile_test_<slug> php artisan test\` — ВЕСЬ набор зелёный (без этой переменной ты сломаешь чужие прогоны — всегда указывай её). Твои тесты: сценарии ТЗ по твоим пунктам, права по ролям (продавец/админ/владелец/бухгалтер), чужая фирма, ошибки; после проведений и сторно — expect(Reconciliation::problems())->toBe([]).
3. \`npm run build\` без ошибок.
4. Живая проверка: \`php artisan serve --host=127.0.0.1 --port=<свой порт>\` в фоне (адреса и вход — в UI-KIT.md; иначе /admin). Демо-вход: Кайрат (владелец) +77010000001 / password; продавцы Айгерим +77010000002, Данияр +77010000003 / password. Playwright-скриншоты каждого своего экрана: 1366×768 в Chrome 109 и в Chromium, iPhone (devices['iPhone 13'], devices['iPhone 15 Pro Max']) для экранов с iPhone-видом. Смотри их сам (Read), сравнивай с макетами и правь, пока не похоже. Нет ошибок в консоли и 500-х. В конце останови сервер.
5. 4–12 ключевых скриншотов — в prime-mobile-os/docs/screens/<slug>/*.png (deviceScaleFactor 1, лучше < 400 КБ): их увидит заказчик.
6. prime-mobile-os/docs/modules/<slug>.md: что сделано по пунктам ТЗ (Э…/П…/§…), публичные классы и как ими пользоваться другим модулям, точки расширения, допущения (с пометкой «Допущение:»), что НЕ сделано и почему.

## Git
- Коммить в свою ветку небольшими осмысленными коммитами (сообщения на английском) — не реже чем каждые 20–30 минут работы, даже незаконченное (префикс «WIP:»). Работа может оборваться из-за лимита — всё незакоммиченное придётся восстанавливать. Каждое сообщение коммита заканчивается двумя строками:
Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011fUD2GwfwoYwYw2BSmKBmo
- Не пушить, не трогать другие ветки. Не коммитить .env, vendor, node_modules, public/build, ключи, токены и пароли (кроме демо-пароля «password» в сидере — он только не в production).
- В конце \`git status\` чистый.

## Финальный ответ (это отчёт, а не письмо человеку)
По-русски, коротко: ветка и последний коммит; что сделано по пунктам ТЗ; что не сделано и почему; допущения; как проверено (число тестов, пути скриншотов); что должны знать следующие модули (контракты, классы).`

const buildPrompt = m => `${COMMON}

# Твоя задача: модуль «${m.title}» (slug: ${m.slug}) — раунд ${R.round}
Подготовка (первая команда, из своего worktree): \`bash ${SETUP} ${m.slug} wip/${m.slug}${m.resume ? ' wip/' + m.slug : ''}\`. Работай в <worktree>/prime-mobile-os.${m.resume ? `
ВНИМАНИЕ: это ПРОДОЛЖЕНИЕ. Прошлая попытка оборвалась из-за лимита; её работа уже в ветке wip/${m.slug} (последний коммит «WIP: …», возможно, недописанный и непроверенный). Сначала разберись: \`git log claude/nifty-johnson-nwlyrg..HEAD --stat\`, прочитай сделанное, прогони тесты, затем доделай задачу целиком. Не начинай с нуля и не выбрасывай рабочий код без причины.` : ''} Твой порт: ${m.port}. Тестовая БД: prime_mobile_test_${m.slug}. Метки миграций: ${m.mig}.

${m.brief}`

const reviewPrompt = (m, rep) => `${COMMON}

# Твоя задача: жёсткое ревью и доработка модуля «${m.title}» (slug: ${m.slug}) — раунд ${R.round}
Строитель сдал ветку wip/${m.slug}. Подготовка: \`bash ${SETUP} ${m.slug}r wip/${m.slug}-r wip/${m.slug}\` (создаст твою ветку wip/${m.slug}-r поверх его работы). Работай в <worktree>/prime-mobile-os. Твой порт: ${m.port + 100}. Тестовая БД: prime_mobile_test_${m.slug}r (то есть \`DB_DATABASE=prime_mobile_test_${m.slug}r php artisan test\`). Метки новых миграций: ${m.mig}.

## Что должно было быть сделано (задача строителя)
${m.brief}

## Отчёт строителя
${rep ? rep : '(отчёта нет — строитель, видимо, упал. Посмотри git log wip/' + m.slug + ', пойми, что сделано, и доделай модуль сам по задаче выше.)'}

## Как ревьюить
Исходи из того, что ошибки ЕСТЬ, и ищи их по пяти линзам:
1. Полнота по ТЗ: пройди каждый пункт своих разделов ТЗ (поля, кнопки, горячие клавиши, тексты ошибок, iPhone-вид, права по ролям) и составь таблицу «пункт → есть / частично / нет». Всё «частично/нет», что можно сделать, — доделай.
2. Учёт и ядро: только Poster/Ledgers, сторно работает, блокировки, курс в документе, Money без float, Reconciliation::problems() пусто.
3. Безопасность: право на сервере в КАЖДОМ действии Livewire/Filament; подмена публичных свойств Livewire (цена, скидка, id, store_id); чужая фирма; XSS ({!! !!}, вывод пользовательских строк); массовое присвоение; себестоимость/поставщик/прибыль в HTML, JSON и wire:snapshot у продавца.
4. Вид и устройства: сам сними скриншоты в Chrome 109 и в iPhone-эмуляции, сравни с макетами docs/design/screens, поправь вёрстку; 1366×768 без обрезаний; касания ≥ 44 px на iPhone.
5. Тесты: недостающие сценарии ТЗ и крайние случаи; тест должен падать, если сломать код.
Исправляй сам, с тестами, коммить в wip/${m.slug}-r. Дополни docs/modules/${m.slug}.md разделом «Ревью» (найдено / исправлено / осталось).
В отчёте: сколько найдено, что исправлено (список), что осталось и почему, итог тестов, ветка и последний коммит.`

const mergePrompt = results => `Ты — интегратор проекта Prime Mobile OS (Laravel 12 + Filament 3, PostgreSQL). Раунд ${R.round}.
Работаешь в ГЛАВНОЙ копии: /home/user/landing (git-ветка claude/nifty-johnson-nwlyrg), проект /home/user/landing/prime-mobile-os. Это не worktree. БД: prime_mobile (разработка, из .env), prime_mobile_test (тесты, phpunit.xml). Пакеты не добавлять. Прочитай prime-mobile-os/ENGINEERING.md, docs/CORE.md §0, docs/UI-KIT.md и docs/modules/*.md (если есть).

Модули раунда и их ветки (бери wip/<slug>-r, если есть, иначе wip/<slug>):
${results.map(x => `- ${x.m.title} (slug ${x.m.slug})`).join('\n')}

Отчёты строителей и ревьюеров:
${results.map(x => `### ${x.m.title}\n**Строитель:**\n${x.build || '(нет отчёта)'}\n\n**Ревьюер:**\n${x.review || '(нет отчёта)'}`).join('\n\n')}

## Шаги
0. \`git status\` в /home/user/landing должен быть чистым (папку .claude/worktrees/ не коммитить — она в .gitignore). Если есть чужие незакоммиченные изменения — не выбрасывай, опиши в отчёте.
1. По очереди слей ветки: \`git merge --no-ff <ветка>\` с сообщением "Merge module <название> (round ${R.round})" и двумя строками в конце сообщения:
Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011fUD2GwfwoYwYw2BSmKBmo
   Конфликты: в файлах «только добавлением» (провайдеры, сидеры, маршруты, переводы, css, конфиг) оставляй обе стороны; смысловые конфликты — сведи так, чтобы работали оба модуля; совпавшие метки миграций — переименуй позднюю.
2. \`composer dump-autoload\`, \`php artisan migrate:fresh --seed\`, \`php artisan test\` (весь набор, зелёный), \`npm run build\`. Чини интеграционные ошибки (с тестами), коммить.
3. Дым-проверка: \`php artisan serve --host=127.0.0.1 --port=8000\` в фоне (адреса — в UI-KIT.md). Playwright в Chrome 109 (${C109}, args ['--no-sandbox']): вход владельцем (+77010000001 / password) и продавцом (+77010000002 / password), открой каждый экран модулей раунда — нет 500 и ошибок консоли; iPhone-экраны — в эмуляции devices['iPhone 13']. Остановить сервер.
4. Обнови prime-mobile-os/docs/MODULES.md: таблица «модуль | статус | что покрыто из ТЗ | известные пробелы | скриншоты»; добавь строки раунда ${R.round}, не удаляя прошлые.
5. Закоммить и \`git push -u origin claude/nifty-johnson-nwlyrg\` (при сетевой ошибке — до 4 повторов через 2, 4, 8, 16 с).
6. Уборка: каждый worktree из \`git worktree list\`, кроме /home/user/landing, у которого ветка wip/* уже слита в этом раунде (модули из списка выше; чужие и неслитые ветки НЕ трогай), — \`git worktree remove --force <путь>\`; затем \`git worktree prune\`; удали слитые ветки wip/* (\`git branch -D\` только после проверки \`git branch --merged\`); удали БД ${R.modules.map(m => `prime_mobile_${m.slug}, prime_mobile_test_${m.slug}, prime_mobile_${m.slug}r, prime_mobile_test_${m.slug}r`).join(', ')} командой \`su postgres -c "psql -q -c 'DROP DATABASE IF EXISTS <имя> WITH (FORCE)'"\`.
Никогда не коммить .env, vendor, node_modules, public/build, .claude/worktrees, ключи и пароли.

## Финальный ответ
По-русски, коротко: какие ветки слиты, конфликты и как решены, число тестов, сборка, дым-проверка (что открыл, что нашёл и починил), хэш запушенного коммита, открытые проблемы для следующего раунда.`

log(`Round ${R.round}: ${R.modules.map(m => m.slug).join(', ')}`)
const results = await pipeline(
  R.modules,
  m => agent(buildPrompt(m), { label: `build:${m.slug}`, phase: 'Build', isolation: 'worktree' }),
  (rep, m) => rep
    ? agent(reviewPrompt(m, rep), { label: `review:${m.slug}`, phase: 'Review', isolation: 'worktree' })
        .then(rev => ({ m, build: rep, review: rev }))
    : ({ m, build: null, review: null }),
)
const done = results.map((x, i) => x || { m: R.modules[i], build: null, review: null })
const ready = done.filter(x => x.build)
const failed = done.filter(x => !x.build).map(x => x.m.slug)
if (failed.length) log(`NOT merged (builder failed, work left in branch wip/<slug>): ${failed.join(', ')}`)
let merged = null
if (ready.length) {
  phase('Merge')
  merged = await agent(mergePrompt(ready), { label: 'merge', phase: 'Merge' })
}
return {
  round: R.round,
  failed,
  modules: done.map(x => ({ slug: x.m.slug, build: x.build, review: x.review })),
  merged,
}
