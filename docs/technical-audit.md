# Комплексный технический аудит Eneba Marketplace

Дата: 12 ноября 2026. Проверялось фактическое рабочее дерево `/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment`, включая существующие незакоммиченные изменения, а не только HEAD. Аудит аналитический: исправления не выполнялись.

## 1. Executive Summary

Проект представляет собой небольшой, в целом понятный Laravel + React MVP: каталог, поиск, предложения продавцов, гостевая корзина и Sanctum Bearer-auth. Текущая структура позволяет развивать приложение без переписывания. Наиболее полезны унификация доступного остатка, устранение двух ошибок синхронизации состояния и воспроизводимые тесты React-поведения.

**16 групп находок: Critical — 0, High — 0, Medium — 8, Low — 8.** В число включены подтверждённые ошибки и явно обозначенные ограничения роста. Отсутствие checkout, payments и order ownership не представлено как обнаруженная уязвимость: этих компонентов пока нет.

Основные подтверждённые ошибки:

1. При одном и том же состоянии БД страница игры показывает quantity=5 и одно предложение, а пакетный API корзины — quantity=0/available=false, если цифровых ключей нет.
2. Вкладка с устаревшим остатком может перезаписать более новое количество корзины другой вкладки.
3. Корзина допускает более 100 позиций, но её загрузчик в этом случае перестаёт загружать все предложения.
4. Ошибка logout во время проверки `/api/me` может оставить защищённую страницу в бесконечном состоянии проверки.
5. Числовой ID за пределами BIGINT вызывает HTTP 500 вместо контролируемой ошибки.

Основные ограничения роста: неограниченная выдача предложений одной игры; поиск со сканированием названий без отдельного throttle; файловый production cache для rate limits; отсутствие постоянного UI regression suite. На нынешних 40 играх / 120 предложениях / 493 ключах не доказана проблема пропускной способности. Измерения небольшой БД не являются нагрузочным тестированием.

**Граница оценки:** это работоспособная основа каталога и авторизации, но ещё не готовая система реальных продаж. Согласованность оплаты, заказа и выдачи ключа должна стать отдельным обязательным этапом до коммерческого checkout. Подтверждённой критической уязвимости, SQL injection, XSS, утечки ключей или обхода действующей авторизации не выявлено. Это не гарантия их полного отсутствия.

## 2. Architecture Assessment

### Фактическая структура

Backend организован по стандартным слоям Laravel: `app/Models`, `app/Http/Controllers`, `app/Http/Resources`, middleware, migrations и seeders. Frontend содержит pages/components, отдельные auth/cart contexts, API-функции и hooks навигации. Доменов Orders/Payments/Checkout пока нет; `/checkout` и `/orders` показывают защищённый ProtectedPage.

**Это обычный Laravel-монолит с частичной группировкой frontend-функций, а не уже выделенные модули Modular Monolith.** Нет явных границ Catalog/Identity/Inventory/Orders с публичными контрактами. Для текущего объёма это не самостоятельный дефект и не основание переносить всё в Modules, вводить микросервисы, Repository/DTO/Interface на каждый запрос.

HTTP validation и Eloquent-запросы в небольших контроллерах допустимы. Выделять application action следует при появлении переиспользуемой транзакционной операции — например, резервирования ключей, а не ради формального соответствия SOLID. Реальное нарушение единого бизнес-правила сейчас — две независимые трактовки доступного остатка (F01).

API последователен: Resources формируют публичные поля, коллекции имеют data, каталог пагинирован. `/games/search` зарегистрирован до numeric `/games/{game}`, `/offers` не конфликтует с ними. `auth:sanctum` + EnsureUserIsActive закрывают me/logout. Backend остаётся источником цен; frontend-сумма — только отображение, не существующая операция покупки.

Есть небольшие точки связанности: общий HTTP transport находится в `api/games.ts`, от него зависит auth; список защищённых путей повторяется в App, AuthProvider и redirect allowlist формы. Это не требует новых слоёв, но при следующем расширении маршрутов стоит обеспечить единый контракт. Абортируемые запросы, discriminated unions и version guard — уместные решения; F04 показывает конкретный крайний случай, где lifecycle ещё не завершён корректно.

## 3. Scalability Assessment

| Область | Сейчас | При росте |
|---|---|---|
| Каталог | paginate(12), стабильный ID tie-break | COUNT и большие OFFSET требуют измерений; cursor pagination сейчас не обязательна |
| Предложения корзины | До 100 ID, eager game/seller, SQL COUNT доступных ключей | Хорошая ограниченная выдача; сначала согласовать лимит корзины, F03 |
| Предложения игры | Вся availableOffers collection | Нет ограничения числа продавцов/DOM, F06 |
| Поиск | ILIKE + word_similarity, preview limit=6 | Функциональное условие и сортировка сканируют активные имена; F08 |
| Пагинация UI | Все номера страниц | Количество кнопок линейно last_page, F07 |
| Backend replicas | Токены в PostgreSQL, API без server sessions | Авторизация переносима между instances; file cache делает throttling локальным, F09 |
| Цифровые ключи | Индекс доступных ключей, encrypted cast | Импорт/выдача потребуют транзакций и защиты от дубликатов; это будущая работа |
| Демо-seeding | Малый фиксированный каталог | DigitalKeySeeder загружает и расшифровывает все ключи: не использовать как production importer |

Read-only EXPLAIN ANALYZE выполнен на локальной рабочей PostgreSQL в `BEGIN READ ONLY ... ROLLBACK`. Репрезентативный predicate/sort поиска дал Seq Scan + Sort; алфавитный список — Seq Scan + top-N Sort. На 40 строках это разумные планы, **не доказательство необходимости немедленно добавлять GIN/functional indexes**. Проверялся predicate/sort, а не полный endpoint с агрегатами offers. Индекс pg_trgm нельзя рекомендовать вслепую: нужно подобрать индексируемое условие кандидатов с сохранением ранжирования и проверить план на реалистичном объёме.

N+1 сетевых SQL-запросов в существующих index/show/batch callers не обнаружен. `withCount` использует коррелированный SQL aggregate, а не PHP-запрос на каждое предложение. Тесты подтверждают постоянное число запросов для batch. Оно не означает постоянную стоимость: размер просматриваемого остатка и количество совпадений всё равно важны.

### Будущий checkout — условия безопасности, а не текущие уязвимости

До реальных продаж необходимо спроектировать и проверить:

- Атомарное резервирование доступных ключей с блокировками/условными изменениями состояния; недопустим отдельно выполненный SELECT available, а затем безусловный UPDATE.
- Невозможность присвоить один key row двум заказам, защищённую DB constraint и транзакцией. Отдельно решить область уникальности самого кода между разными key rows: randomized encryption не даёт сравнивать plaintext через UNIQUE ciphertext.
- Серверную повторную проверку цены, валюты, активности, региональных правил и доступного количества. Сохранённый frontend total не является ценой заказа.
- Стабильный idempotency key создания checkout и уникальность provider event/payment identifiers. Повторный webhook не должен повторно менять заказ и выдавать ключ.
- Проверку подписи и суммы/currency/payment status провайдера, а не доверие return URL браузера; обработку повторной и изменённой последовательности событий.
- Срок жизни reservation, отмену/возврат остатка и согласование БД с внешним провайдером без удержания долгой DB-транзакции во время сетевой оплаты.
- Серверную owner authorization заказов/ключей. React RequireAuth не заменяет проверку владельца.

Нет оснований заранее создавать универсальный payment framework, микросервисы или распределённые транзакции. Эти требования надо реализовать вместе с первым реальным checkout.

## 4. Security Assessment

### Authentication и Authorization

Sanctum создаёт стандартные personal access tokens, срок — семь дней; logout отзывает currentAccessToken. `sanctum.guard=[]` исключает session fallback. User имеет hashed cast, пароль скрыт, UserResource явно перечисляет публичные поля. В API нет вывода session identifiers или цифровых ключей. Middleware проверяет active при каждом защищённом запросе, поэтому ранее выданный токен blocked-пользователя не обходит запрет. Тесты подтверждают revoked/expired/blocked и отказ web session.

Токен хранится только в sessionStorage; устаревшая запись localStorage удаляется. Auth-клиент не помещает токен в URL, использует Authorization и запрещает HTTP API URL в production. `credentials: omit` исключает cookies из API-auth. Обычная web CSRF сохранена, что проверено отдельным тестом при включённом реальном middleware path.

**Принятый риск, не найденный XSS:** sessionStorage доступен JavaScript. XSS или скомпрометированный скрипт смогут прочитать токен; отсутствие localStorage ограничивает длительность хранения, но не устраняет этот риск. В просмотренном React-коде нет dangerouslySetInnerHTML/eval и пользовательский текст выводится обычным JSX. Следует сохранять это свойство, проверять будущие HTML-интеграции; CSP — дополнительная защита, а не найденное средство эксплуатации. См. [OWASP Web Storage](https://cheatsheetseries.owasp.org/cheatsheets/HTML5_Security_Cheat_Sheet.html#storage-apis).

Rate limits существуют, но есть распределённое ограничение file store и общий bucket login/register — F09/F10. Фактическая интерпретация client IP за Render proxy не проверялась: нужен staging-check доверенных proxy headers, без слепого доверия произвольному X-Forwarded-For. Это гипотеза конфигурационного риска, не доказанный обход ограничения.

Защищённых заказов/выдачи ключей в API нет, поэтому подтвердить IDOR/BOLA заказов невозможно. Публичное получение игр/предложений по ID соответствует их текущему назначению; само по себе это не IDOR.

### Input, secrets, configuration

SQL поиска использует bindings и экранирует LIKE metacharacters; SQL injection по просмотренным путям не обнаружен. Register принимает whitelist данных, status исключён из fillable, unique constraint дополняет проверку email. Numeric route имеет неполную проверку диапазона — F05.

Production Dockerfile задаёт APP_DEBUG=false, production PHP settings, non-root USER; Caddy admin выключен, TLS предполагается на Render edge. Production entrypoint не запускает migrations/key generation. `.dockerignore` исключает `.env*`, auth.json и приватные ключи, в том числе промежуточный image stage. Реальные Render/Vercel/Neon settings, edge TLS и headers не проверялись.

Проведён ограниченный pattern scan текущих текстовых файлов Git/worktree. Подтверждённых реальных секретов не найдено: совпадения оказались placeholder/fixture и строками генерации временных credentials. Это не полное secret scanning истории Git, изображений или внешних артефактов. `.env.production.local` не читался, значения секретов не выводились. Большой исторический docs/ai-session.md следует продолжать проверять перед публикацией.

Демо-seeders явно содержат фиктивные цены/ключи и стандартную factory account. Это не обнаруженные реальные production credentials и не доказанная production-компрометация. У DatabaseSeeder есть дополнительная эксплуатационная особенность: UserFactory зависит от dev-only Faker, поэтому он не является пригодным production bootstrap при `composer install --no-dev`. DigitalKeySeeder не является импортёром реальных ключей. Ничего из этого в ходе аудита не запускалось против рабочей БД.

Composer audit --locked и npm audit --package-lock-only: известных advisory не обнаружено; abandoned Composer packages также не сообщены. Проверка registry не доказывает отсутствие supply-chain угроз и не заменяет scanning ОС контейнера.

## 5. Dead Code & Duplication

### Кандидаты на удаление/очистку, не выполненную этим аудитом

| Элемент | Уверенность и зависимости | Можно ли удалить / что проверить |
|---|---|---|
| [frontend/src/assets/hero.png](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/assets/hero.png), [frontend/src/assets/vite.svg](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/assets/vite.svg) | Высокая: нет imports, CSS URL или ссылок index.html; отсутствуют в output bundle. F15 | Вероятно да после проверки визуальных материалов/запланированного использования; повторить build. Favicon — другой файл, его не удалять |
| [backend/tests/Unit/ExampleTest.php:14](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/tests/Unit/ExampleTest.php:14) | Высокая: только assertTrue(true); PHPUnit discovery запускает его, но он не проверяет продукт. F15 | Да как тестовую заглушку, после проверки suite discovery. Feature ExampleTest имеет полезную роль smoke GET / и не эквивалентен пустому unit test |
| [backend/bootstrap/cache/packages.php:1](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/bootstrap/cache/packages.php:1), [backend/bootstrap/cache/services.php:1](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/bootstrap/cache/services.php:1) | Это **используемые** Laravel manifests, но сгенерированные и отслеживаемые Git. F14 | Убирать из контроля версий, не запрещать их runtime generation. Проверить composer package:discover, local install и production no-dev image |
| [backend/routes/console.php:6](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/routes/console.php:6) inspire | Высокая как scaffold, но есть косвенная доступность через Artisan | Опционально; сначала проверить документацию/ручные workflows. На архитектуру и безопасность не влияет |
| Stateful-domain/middleware часть [backend/config/sanctum.php:16](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/config/sanctum.php:16), [backend/config/sanctum.php:79](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/config/sanctum.php:79) | Не нужна текущему stateless API; пакет может читать config динамически | Не объявлять весь config мёртвым: guard, expiration и token_prefix участвуют в Sanctum. Упрощение только с package integration tests |
| /sanctum/csrf-cookie | Не используется frontend; зарегистрирован vendor provider и имеет web middleware | Можно отдельно решить, отключать ли пакетный route. Это не обход Bearer-auth и не основание глобально отключать CSRF |
| sessions/password_reset_tokens, queue tables/config | Не используются текущими API-функциями, но относятся к стандартной Laravel инфраструктуре | Не удалять автоматически. Проверить driver settings, console commands и будущие требования; нужны отдельные migrations/решение |

Подтверждённых неиспользуемых React-компонентов/hooks/imports по текущему графу и TypeScript/Oxlint не найдено. Сопоставление CSS Modules с обращениями styles.* не дало подтверждённых мёртвых классов; это не заменяет полного visual coverage.

Composer/npm зависимости не объявляются мёртвыми по отсутствию import: Sanctum регистрирует guard/route/provider, PHP extensions и dev tools используются косвенно, vite/react plugins участвуют в сборке, типовые npm-пакеты нужны compiler. Достоверно ненужной зависимости не доказано; ставить новый анализатор ради списка удалений не требуется.

### Дублирование и сложность

Существенное дублирование — доступность/остаток в Game.availableOffers и CartOfferResource (F01). Менее критичны protected paths в App/AuthProvider/форме, byte-limit password validation в register/login, два способа money formatting. Не нужно заменять эти небольшие конструкции универсальными фабриками: сначала устранить правило, которое уже расходится, затем при реальном расширении выделить маленькие общие функции/constants.

Scroll restoration сложнее обычного scrollTo, но сохраняет history key, anchor ID/offset, fallback scrollY и ждёт API/DOM. Это оправдано требованиями точного Back/Forward; переписывать его на новый state manager не требуется. Основной долг — отсутствие постоянных UI-тестов, F12.

## 6. Database Assessment

### Что обеспечено БД

- users: BIGINT PK, unique email, first/last_name длиной 100, обязательные timestamps, CHECK active/blocked PostgreSQL.
- offers: DECIMAL(10,2), nonnegative price/quantity CHECK, FK seller/game, unique seller_id+game_id. Денежные строки не заменяются float в persisted calculations.
- digital_keys: FK offer, обязательные поля, PostgreSQL CHECK available/reserved/sold, encrypted value на уровне Eloquent и hidden при сериализации.
- game_popularities: game_id PK/FK, nonnegative purchase_count, cascade при удалении игры, ranking index с ID tie-break.
- удаление игр/продавцов/предложений с зависимыми offers/keys не делает неявной каскадной массовой потери ключей: основные FK используют default NO ACTION. Каскад счётчика популярности уместен.

### Что остаётся в приложении / будущем процессе

Согласованность Offer.quantity и числа доступных keys не гарантируется CHECK/FK. Game автоматически создаёт popularity внутри transaction при обычном Eloquent save/create; bulk SQL/insert/withoutEvents обходят событие. Сейчас это не подтверждённая ошибка существующих callers, но будущий importer должен сохранять инвариант, иначе popular INNER JOIN скроет игру без popularity.

Нет DB-гарантии уникальности plaintext цифрового кода и эксклюзивного назначения ключа заказу. Пока нет importer/checkout — это будущие требования, не доказанная повторная продажа. Не следует делать UNIQUE по randomized ciphertext: это не обеспечивает уникальность исходного значения. Сам факт наличия PK key row также не решает повторную выдачу.

### Индексы и производительность

Read-only просмотр pg_indexes подтвердил: partial offers(game_id,price) для активного положительного stock, unique(seller_id,game_id), partial digital_keys(offer_id,id) WHERE status=available и стандартные token/email indexes. Они соответствуют текущим read paths. Не выявлено явно избыточного дублирующего индекса.

Полных индексов offers.game_id и digital_keys.offer_id для всех статусов нет. PostgreSQL не создаёт индекс referencing FK автоматически. Это имеет значение для будущих delete/reconciliation/full-status queries, но нынешний доступный остаток использует подходящий partial index; добавление индексов без измерений не объявляется обязательным исправлением. [PostgreSQL FK/index guidance](https://www.postgresql.org/docs/17/ddl-constraints.html#DDL-CONSTRAINTS-FK).

GIN/functional name indexes отсутствуют, однако current EXPLAIN на 40 играх не показывает необходимость срочной оптимизации. Нельзя судить о latency Neon по локальной БД. Большие OFFSET/COUNT, количество offers и число keys на offer требуют профиля до изменения стратегии.

Миграции сохраняют историю и содержат down. Преобразование users заранее проверяет длины имён, но на большом числе пользователей использует O(N) память и индивидуальные updates; удаляемые legacy-поля и status при rollback не восстанавливают прежние значения. Перед применением к непустой production users нужны review/backups. Миграция pg_trgm намеренно не удаляет общее расширение на down — это разумная мера сохранности.

## 7. Code Quality

Сильные стороны: небольшие классы, typed relation methods, PHP attributes Fillable/Hidden, API whitelists, явные React state unions, AbortController cleanup, целые центы BigInt, безопасный parse повреждённой корзины. Нет избыточных DTO/repository/service слоёв.

Конкретные слабые места: F01 и F04 — корректность; F11 — не включён постоянный strict; F16 — Pint style. Проверка `tsc --strict` на временной копии уже проходит, поэтому здесь нет утверждения о найденном TypeScript runtime/type defect. Включение guardrail возможно с малым риском.

Ни один просмотренный request effect не демонстрирует подтверждённой утечки таймеров/listeners: поисковый debounce и fetch отменяются, scroll RAF/listeners очищаются, предыдущие ответы проверяются. F04 — ошибка завершения состояния, а не утечка памяти. Map известных stock хранит просмотренные ID на время жизни вкладки; на текущем MVP это не утечка, при тысячах посещений можно измерить размер, прежде чем добавлять eviction.

## 8. Test Coverage & Quality

### Выполнено

| Проверка | Результат |
|---|---|
| Основной PHPUnit, изолированная копия + SQLite :memory: | 99 tests, 1053 assertions, PASS |
| PostgreSQL search suite, новый tmpfs PostgreSQL | 8 tests, 153 assertions, PASS |
| PostgreSQL offers/digital keys suite, отдельный новый tmpfs PostgreSQL | 26 tests, 83 assertions, PASS |
| Native Node frontend tests | 128 PASS |
| TypeScript app/node | PASS |
| Дополнительный TypeScript --strict | PASS; конфигурация исходников не менялась |
| Oxlint | 0 warnings / 0 errors |
| Pint по исходникам | FAIL: два single_quote style несоответствия, F16 |
| Production frontend build | PASS, временная копия; JS около 296.5 kB / gzip 93.1 kB |
| Composer audit --locked | advisory/abandoned не сообщены |
| npm audit --package-lock-only | 0 reported vulnerabilities |
| PowerShell production-db offline tests | PASS, Docker/Read-Host mocked; реальные DB/Docker операции скрипта не выполнялись |

Временный audit bootstrap не включён в оценку Pint исходников. Тесты выполнялись без установки зависимостей, с существующими vendor/node_modules. PostgreSQL suites запускались по отдельности в новых контейнерах на отдельной сети с read-only mount исходников, без подключения рабочей БД; контейнеры/сеть удалены. Основная PHPUnit-защита SQLite не ослаблялась.

### Дополнительные безопасные воспроизведения

- Отдельный HTTP-kernel probe в SQLite :memory: показал расхождение stock и общий throttle bucket.
- HTTP-kernel probe числового ID использовал локальную PostgreSQL с default_transaction_read_only=on: обычный ID — 200, значение за пределом BIGINT — 500. Debug выключен, body/секреты не выводились.
- Chromium выполнял собранный frontend во временном origin с полностью перехваченными API fixtures: stale-tab stock, корзина 101 позиции, 500 страниц → 502 кнопки, logout503 во время pending me. Fixtures не отправлялись в рабочий API/БД.
- Текущие файлы проекта сверены по SHA-256 до/после проверок. До создания отчёта изменений существующих файлов и неожиданных новых файлов не было.

### Качество существующих тестов

Backend покрывает важные negative cases, status flags, Hash/secret hiding, expiry/revocation, pagination/order, invalid input, encryption round-trip и PostgreSQL constraints. Изоляция Tests/TestCase сильнее простого переопределения DB_DATABASE: проверяются реальное PDO и memory database. Query-count тесты полезны как guard от N+1.

Но тесты OfferPresentation закрепляют quantity=5 без соответствующих пяти ключей, а OfferIndex отдельно проверяет count(keys). Отсутствует cross-endpoint invariant, поэтому оба suite зелёные при противоречивом API. Часть query-count assertions проверяет форму SQL/точное количество, которое придётся осмысленно пересмотреть при изменении правила stock, а не слепо сохранять.

Frontend Node tests проверяют API parsing и pure cart operations. Они не монтируют React, не исполняют hooks, storage events, dialog/Router/layout effects. Замена import.meta.env через чтение исходника удобна без новых зависимостей, но не доказывает работоспособность UI-сборки. Документы содержат результаты прежних Chromium-проверок; постоянного browser suite в репозитории нет. В дереве не найден CI workflow, автоматически запускающий эти проверки. F02/F04 показывают практическое следствие пробела.

Для следующих исправлений нужны targeted tests: актуализация stock между tabs; >100 items; failed logout во время pending me; возврат stale me после нового login; loading/error/missing anchor Back/Forward; совпадение catalog/details/batch доступности. Не требуется 100% покрытия.

### Что не проверено

Не проводились: destructive security testing, нагрузочный flood, реальные платежи/заказы, production login/Neon, сканирование ОС production image, полный Git-history secret scan, OCR изображений, полный visual/accessibility audit. Production image не пересобирался, инфраструктура не менялась. Отсутствующие компоненты не получили выдуманную оценку защищённости.

## 9. Findings

Приоритет означает последствия, а не наличие эксплойта. «Риск роста» — подтверждённая структура/настройка с условным будущим эффектом; это не заявление о нынешней деградации или успешном обходе.

| ID | Приоритет | Категория | Проблема | Файл/строка | Рекомендация |
|---|---|---|---|---|---|
| F01 | Medium | Correctness / inventory | Разные правила stock в details/catalog и batch | [backend/app/Models/Game.php:54](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/app/Models/Game.php:54), [backend/app/Http/Resources/CartOfferResource.php:12](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/app/Http/Resources/CartOfferResource.php:12) | Общий серверный контракт доступности и cross-endpoint test |
| F02 | Medium | React / concurrency | Устаревший stock вкладки перезаписывает новое количество | [frontend/src/cart/CartProvider.tsx:23](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/cart/CartProvider.tsx:23) | Не считать stale in-memory stock авторитетом внешнего storage event |
| F03 | Medium | Cart contract | 101 допустимая позиция ломает загрузку всей корзины | [frontend/src/cart/cart.ts:21](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/cart/cart.ts:21), [frontend/src/api/games.ts:148](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/api/games.ts:148) | Согласовать общий лимит корзины и API |
| F04 | Medium | Auth / async | Failed logout инвалидирует pending me без восстановления state | [frontend/src/auth/AuthProvider.tsx:46](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/auth/AuthProvider.tsx:46) | Завершать failed logout через восстановление проверки; сохранить stale-response protection |
| F05 | Low | API validation | Числовой overflow route ID даёт 500 | [backend/routes/api.php:23](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/routes/api.php:23) | Проверять диапазон BIGINT до binding |
| F06 | Medium | Scalability | Все предложения игры загружаются/рендерятся без лимита | [backend/app/Http/Controllers/GameController.php:45](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/app/Http/Controllers/GameController.php:45), [frontend/src/pages/GameDetails/GameDetails.tsx:56](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/pages/GameDetails/GameDetails.tsx:56) | Ограниченная выдача/пагинация offers с отдельным summary |
| F07 | Low | React performance | Все номера страниц создаются через last_page | [frontend/src/pages/Home/Home.tsx:61](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/pages/Home/Home.tsx:61) | Ограниченное окно страниц |
| F08 | Medium | Search / abuse resistance | Fuzzy scan без отдельного серверного throttle | [backend/app/Http/Controllers/GameSearchController.php:54](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/app/Http/Controllers/GameSearchController.php:54), [backend/routes/api.php:21](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/routes/api.php:21) | Разумный throttle; оптимизировать по измерениям роста |
| F09 | Medium | Deployment / rate limiting | File cache делает счётчики throttling локальными instance | [backend/Dockerfile.prod:7](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/Dockerfile.prod:7) | Общий limiter store перед scale-out; проверить proxy IP |
| F10 | Low | Auth policy | Login/register делят неявный throttle bucket | [backend/routes/api.php:25](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/routes/api.php:25) | Именованные независимые политики либо явно документированный общий лимит |
| F11 | Low | TypeScript | Strict не закреплён в config | [frontend/tsconfig.app.json:2](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/tsconfig.app.json:2) | Зафиксировать strict после текущего успешного прогона |
| F12 | Medium | Regression coverage | Нет постоянных React/Router/storage UI-тестов и CI gate | [frontend/tests/auth.test.mjs:5](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/tests/auth.test.mjs:5), [frontend/package.json:6](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/package.json:6) | Сохранить ключевые browser сценарии и запускать регулярно |
| F13 | Low | Operations | Нет расписания удаления expired personal access tokens | [backend/routes/console.php:1](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/routes/console.php:1), [backend/app/Http/Controllers/AuthController.php:66](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/app/Http/Controllers/AuthController.php:66) | Настроить стандартный Sanctum prune-expired с retention |
| F14 | Low | Generated config | Bootstrap manifests отслеживаются Git | [backend/bootstrap/cache/packages.php:1](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/bootstrap/cache/packages.php:1), [backend/bootstrap/cache/services.php:1](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/bootstrap/cache/services.php:1) | Не версионировать генерируемые cache artifacts |
| F15 | Low | Dead scaffold | Два assets без ссылок, пустой unit-example | [frontend/src/assets/hero.png](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/assets/hero.png), [frontend/src/assets/vite.svg](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/frontend/src/assets/vite.svg), [backend/tests/Unit/ExampleTest.php:14](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/tests/Unit/ExampleTest.php:14) | Подтвердить назначение и убрать отдельной уборкой |
| F16 | Low | Formatting | Pint обнаруживает два source style нарушения | [backend/database/seeders/GameSeeder.php:24](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/database/seeders/GameSeeder.php:24), [backend/tests/Feature/GamePopularityTest.php:114](/mnt/c/Users/ttcev/Desktop/projects/eneba-assignment/backend/tests/Feature/GamePopularityTest.php:114) | Отдельный минимальный форматирующий diff |

### F01 — Разные источники доступности

**Подтверждено** реальными Resources/HTTP kernel в изолированной БД: offer.quantity=5, ключей нет → details quantity=5/offers_count=1, batch quantity=0/available=false. `Game::availableOffers` фильтрует activity/quantity/seller, а batch дополнительно считает keys. Текущий OfferPresentationTest намеренно ожидает quantity=5 даже при меньшем числе keys.

**Последствие:** «доступно/от ...» в каталоге, Add to cart и корзина расходятся. Это не доказательство покупки отсутствующего ключа: checkout отсутствует. **Исправление:** определить значение quantity и единое SQL-правило для summary/details/batch, не дублировать его в Resource. **Риск изменения:** средний, меняется контракт availability и тестовые ожидания. **До production:** желательно для публичного MVP; обязательно до реальных продаж.

### F02 — Stale stock другой вкладки

**Подтверждено Chromium fixtures:** вкладка деталей знает stock=2; корзина получила stock=5. Увеличение количества с 2 до 3 во второй вкладке завершается сохранением 2. Storage listener первой вкладки ограничивает external event по старому knownStock, затем persistence effect пишет ограниченную копию обратно.

**Последствие:** потеря пользовательского изменения и невозможность выбрать действительный остаток до обновления старой вкладки. **Исправление:** разделить отображаемый snapshot stock и применение внешнего cart state; актуализировать остаток через API до авторитетной перезаписи. Не нужны CRDT или серверная корзина. **Риск:** средний — проверить отсутствие storage-event loops и сохранение min/max. **До production:** желательно в MVP; финансовый ущерб не доказан.

### F03 — Несогласованный максимум корзины

**Подтверждено:** addCartItem/parseCart не ограничивают число предложений, getOffers rejects >100 unique IDs до fetch. Chromium с 101 валидной по формату позицией показал общую ошибку и ноль запросов offers. На нынешних 120 предложениях размер >100 возможен без расширения набора данных.

**Последствие:** ни одна позиция не получает актуальную цену/остаток, хотя допустимые 100 могли бы загрузиться. Удаление вручную остаётся доступным, данные не уничтожаются. **Исправление:** согласовать продуктовый лимит и предотвращать добавление сверх него с понятным сообщением; альтернативу batch-частями рассматривать только если большие корзины нужны. **Риск:** низкий/средний, проверить старые сохранённые корзины. **До production:** желательно в MVP.

### F04 — Зависание проверки после failed logout

**Подтверждено Chromium:** вход на protected route запускает delayed me; Header позволяет logout. Logout503 → signOut увеличивает version и бросает ошибку, successful старый me затем отвергается version guard. checkedKey остаётся от предыдущего location, computed loading=true; на экране остаётся Checking your account... до новой навигации/focus/reload.

**Исправление:** обеспечить завершение/повтор проверки при неуспешном logout. Нельзя просто удалить version guard — это может вернуть пользователя после успешного выхода. **Риск изменения:** средний, нужны тесты противоположных порядков ответов. **До production:** желательно исправить в MVP; это подтверждённая ошибка UI-state, не обход auth.

### F05 — Недостаточный numeric route constraint

**Подтверждено** read-only HTTP-kernel запросом к локальной PostgreSQL: `/api/games/1` → 200, `/api/games/9223372036854775808` → 500. whereNumber проверяет только цифры; значение не помещается в PG BIGINT и ошибка возникает в route binding.

**Исправление:** согласованный 404/422 и проверка диапазона до SQL. **Последствие:** неправильная обработка пользовательского ввода и лишние error logs; production debug=false не даёт основания утверждать утечку stack trace. **Риск изменения:** низкий. **До production:** полезное небольшое исправление, не критический blocker.

### F06 — Неограниченный offers list

**Подтверждено кодом:** show eager-load не имеет limit/pagination, Resource сериализует все offers, React строит весь список. N+1 отсутствует, но объём данных не ограничен.

**Последствие при большом числе продавцов:** память PHP, размер ответа и DOM растут линейно. На пяти demo sellers проблемы не измерены. **Исправление:** ограниченный список/пагинация с общими min_price/offers_count, которые не должны случайно стать числом только загруженной страницы. **Риск:** средний, меняется API/UI contract. **До production:** не обязательно для нынешнего каталога; нужно перед ростом sellers.

### F07 — Неограниченная UI-пагинация

**Подтверждено Chromium:** meta.last_page=500 создаёт 502 кнопки. **Исправление:** first/last + соседние страницы/ellipsis. **Последствие:** большой DOM и неудобная навигация, а не превышение SQL page size. **Риск:** низкий, проверить доступность и Back/Forward. **До production:** для текущих четырёх страниц необязательно.

### F08 — Стоимость поиска и отсутствие отдельного throttle

**Подтверждено:** серверный route не имеет throttle, SQL использует word_similarity/ILIKE OR и ранжирование. read-only план показал Seq Scan; preview LIMIT ограничивает ответ, не гарантирует ограниченную работу поиска. Browser debounce защищает только обычный клиент.

**Риск при росте/нагрузке:** дорогие публичные запросы; успешный DoS не проверялся и не заявляется. **Исправление:** ограничить частоту поиска, снять планы на типичном объёме; затем выбрать candidate search/index/operator с проверкой релевантности. **Риск:** низкий для throttle, средний для смены поиска. **До production:** базовая защита желательна; дорогая индексация не обязательна на 40 строках.

### F09 — Process/instance-local throttling store

**Подтверждено Docker default:** CACHE_STORE=file; route throttle использует cache. При отсутствии внешнего override instances имеют разные файловые счётчики; redeploy их теряет. Фактические Render overrides не исследовались.

**Исправление:** перед горизонтальным масштабированием общий rate limiter store; existing database cache или Redis выбирать по нагрузке, а не автоматически добавлять новую зависимость. Отдельно проверить client IP/proxy trust. **Риск изменения:** средний, необходимы multi-instance/staging tests. **До production:** единый store нужен перед scale-out, не доказанная необходимость Redis для single-instance MVP.

### F10 — Общий bucket разных auth routes

**Подтверждено изолированным HTTP probe:** пять failed login (422) → первый register с того же IP получает 429. Два positional throttle используют одинаковую request signature без отдельного prefix.

**Исправление:** определить, намеренно ли это общий лимит. Если нужны объявленные независимые 10 login/5 register, использовать named policies с разными keys. **Риск изменения:** низкий; не ослабить общий brute-force контроль. **До production:** желательно документировать/уточнить, не доказан security bypass.

### F11 — Strict только в ручной проверке

**Подтверждено config:** strict/noImplicitAny/strictNullChecks не включены и нет extends, который их задаёт. **При этом ручной --strict прошёл**, найденных type errors нет.

**Исправление:** закрепить успешный stricter contract в config/CI. **Последствие:** будущие nullable/implicit-any ошибки иначе могут пройти обычный build. **Риск изменения:** низкий по текущему результату. **До production:** необязательно, P2 guardrail, а не обнаруженный runtime дефект.

### F12 — Нет постоянного regression suite для UI-state

**Подтверждено деревом:** четыре Node test files работают с функциями/HTTP parsing, не монтируют React/Router; browser сценарии описаны в docs, но тестов их выполнения/CI нет. F02/F04 не обнаруживаются текущими зелёными unit suites.

**Исправление:** сохранить узкий набор lifecycle/storage/navigation/auth browser tests, включая фактические воспроизведения, затем автоматизировать запуск. Не писать тесты, повторяющие CSS или каждый implementation detail. **Риск:** низкий для продукта, средний по поддержке harness. **До production:** желательно в MVP; особенно перед изменениями scroll/auth/checkout.

### F13 — Не настроено удаление expired tokens

**Подтверждено доступным кодом:** login создаёт новую запись, logout удаляет только текущую; console schedules/prune jobs не найдены. Expiry корректно запрещает доступ, но не удаляет row автоматически. Внешний scheduler на Render неизвестен.

**Исправление:** стандартный `sanctum:prune-expired` с выбранным retention и подтверждённым запуском scheduler. [Sanctum token expiration](https://laravel.com/docs/13.x/sanctum#token-expiration). **Риск:** низкий, проверить retention/backup policy. **До production:** не блокирует небольшой MVP; нужен до накопления заметного объёма токенов.

### F14 — Генерируемые manifests в Git

**Подтверждено git ls-files:** packages.php/services.php tracked, содержат в том числе dev providers. Production .dockerignore исключает cache, а build выполняет discovery — поэтому это **не доказанная загрузка dev providers в production**.

**Исправление:** перестать версионировать generated files, сохранив каталог/его .gitignore и штатную генерацию. **Риск:** низкий/средний, проверить чистый composer install и no-dev image. **До production:** не обязательно; устраняет шум и риск устаревших локальных manifests.

### F15 — Подтверждённые scaffold candidates

Два src/assets не входят в актуальный граф сборки; unit ExampleTest проверяет только true. **Последствие:** небольшой мусор и лишний тестовый счётчик, не runtime performance incident. **Исправление/проверки:** см. раздел 5, отдельная уборка после подтверждения назначения. **Риск:** низкий. **До production:** необязательно. Другие Laravel classes/relations не признаны мёртвыми по отсутствию прямых вызовов.

### F16 — Два Pint style несоответствия

**Подтверждено полным source scan:** single_quote в GameSeeder и GamePopularityTest. Это предпочтения Pint, не утверждение о поломке PSR-12/runtime. **Исправление:** минимальный форматирующий diff отдельно от бизнес-изменений. **Риск:** низкий. **До production:** необязательно, кроме случая обязательного formatter gate.

## 10. Recommended Action Plan

### P0 — обязательно до production

По проверенным реализованным read/auth сценариям не выявлено Critical/High, требующих срочного архитектурного переписывания. Не следует выдавать необязательные индексы, modules folders, strict или косметику за обязательный production blocker.

Перед **реальными продажами**, в отличие от демонстрационного каталога, обязательны согласованный stock contract F01 и отдельная реализация/проверка checkout-инвариантов раздела 3: серверная цена, owner authorization, эксклюзивная выдача ключа, payment authenticity/idempotency. Сейчас этих гарантий нет потому, что checkout не реализован, а не потому, что найден действующий платёжный exploit.

При будущем production rollout отдельно подтвердить фактические HTTPS/debug/APP_KEY/Neon TLS и client-IP настройки. В аудите production не обследовался; это проверка развёртывания, не утверждение, что текущая инфраструктура настроена неправильно.

### P1 — желательно в рамках MVP

1. Добавить regression cases F01/F02/F03/F04 к существующим тестам, затем исправить конкретные ошибки. Польза высокая, сложность средняя, риск средний. Сначала зафиксировать общий смысл stock и проверить все три API-пути.
2. Восстановить завершение auth check после failed logout, сохранив защиту от позднего me после успешного выхода. Польза высокая для надёжности, изменение небольшое, тесты критичны.
3. Согласовать лимит корзины с API; предусмотреть старые oversized сохранённые корзины. Польза средняя, сложность низкая/средняя, риск невысокий.
4. Обрабатывать overflow ID контролируемо, уточнить независимость auth rate buckets. Польза средняя, сложность/риск низкие.
5. Сохранить минимальные UI regression tests и базовый automated check pipeline F12. Польза высокая, объём ограничить найденными сценариями; покрытие 100% не нужно.
6. Добавить разумную server-side защиту search requests, проверить proxy/IP и limiter store на staging. Shared store — перед несколькими backend instances, не повод заранее вводить Redis.

### P2 — можно отложить до сигналов роста

- Пагинация offers при росте sellers; ограниченная пагинация UI до сотен страниц.
- Планирование/индексы поиска и большие OFFSET на реалистичных данных, не на 40 строках; полные FK indexes — под реальные maintenance queries.
- Стандартная очистка expired tokens, фиксация strict, cleanup tracked manifests/assets и Pint style.
- Постепенные модульные границы Catalog/Identity/Inventory/Orders вместе с новыми use cases. Не вводить слои только ради названия Modular Monolith.
- Production-importer вместо demo DigitalKeySeeder только при появлении реального импорта; не расширять демо-сидер до универсального механизма.

## 11. Positive Findings

- Laravel conventions и компактный код вместо чрезмерных абстракций; бизнес-сложность пока не спрятана в цепочке сервисов.
- Resource whitelists и eager loading; ограниченный batch offers устраняет прежний обход каталога.
- PostgreSQL constraints действительно проверяются отдельным реальным suite, SQLite guard основного suite не ослаблен.
- Пароли hashed, токены стандартные Sanctum с expiry/current-token revoke; blocked проверяется сервером, web session не авторизует API.
- DigitalKey encrypted cast + hidden и отсутствие key values в публичных запросах/ответах.
- DECIMAL цены и BigInt cents для сумм; frontend total не создаёт заказ и не инициирует оплату.
- Безопасная обработка повреждённой корзины, сохранение отсутствующих offers без их тихого удаления.
- Сортировка с ID tie-break, SQL bindings/LIKE escaping, preview limit и cancellation debounce.
- Scroll restoration по history key и anchor после загрузки данных, fallback без обхода других страниц; listeners/RAF/fetch имеют cleanup.
- Production Dockerfile без автоматических migrations и генерации ключа, non-root runtime, исключение секретов из build context.
- Ручной Neon workflow с точным подтверждением операции, TLS/channel_binding validation, отдельным seed command и offline tests, не раскрывающими output.
- Документация явно отмечает demo data и текущие ограничения; предыдущие изменения не были откатаны.

**Результат аудита — только этот отчёт.** Исправления, изменения бизнес-данных рабочей БД, миграций, зависимостей и production-инфраструктуры не выполнялись. Все тестовые записи создавались только в одноразовых SQLite/tmpfs БД; рабочая PostgreSQL использовалась read-only. Коммиты, push и deploy не выполнялись.
