# Поиск игр

Главная `/` поддерживает популярность, All Games (`/?sort=alphabetical`) и поиск (`/?q=witcher&page=2`). Отдельной страницы поиска нет. Games и All Games открывают свои обычные режимы без q.

## Backend

`GET /api/games/search?q=witcher&page=1` возвращает прежние Game Resource, `links` и `meta`, по 12 игр. В pagination links сохраняется нормализованный q. Только активные игры.

Ранжирование PostgreSQL: точное совпадение без учёта регистра → начало названия → подстрока через ILIKE → нечёткое совпадение. Внутри группы используется `word_similarity` из pg_trgm, затем ID. Порог нечёткого включения — 0.45; это практическая поддержка опечаток, а не гарантия исправления любого слова. Триграммы учитывают слова, поэтому пунктуация может влиять на нечёткие совпадения иначе, чем на буквальный ILIKE.

Все значения SQL передаются через bindings. Для ILIKE `%`, `_` и выбранный escape-символ `!` экранируются. Запрос обрезается по краям, должен быть непустой строкой до 255 символов. Невалидные q/page/preview дают 422. Максимальный page вычисляется из PHP_INT_MAX и размера страницы, как у обычного каталога. Валидная страница за пределами выдачи возвращает пустой массив.

`GET /api/games/search?q=witch&preview=1` использует тот же запрос и ранжирование, но возвращает максимум 6 ресурсов в `data` и уникальные без учёта регистра названия из них в `suggestions`. Не загружает весь каталог. Цены и отсутствующие в модели поля не добавляются.

Новая миграция `2026_11_12_000003_enable_pg_trgm_for_game_search` выполняет только `CREATE EXTENSION IF NOT EXISTS pg_trgm` на PostgreSQL. SQLite её пропускает; она не подменяет PostgreSQL-поиск другой реализацией. Без PostgreSQL/расширения endpoint возвращает 503. `down()` намеренно не удаляет потенциально общее расширение. Таблицы games и game_popularities, прежние миграции, модели, сидеры и обычные сортировки не изменены.

Индексы не добавлены: текущий каталог мал; fuzzy-функция может потребовать сканирования активных названий. Перед ростом нагрузки нужны измерения EXPLAIN и отдельное решение об индексах/выборке кандидатов. Публичный API пока не имеет отдельного rate limit поиска.

## Панель и история

Поле Header открывает модальную фиолетовую панель с фокусом в поиске; Escape/стрелка закрывают её, не меняя URL. Панель работает и на странице игры. Debounce 280 мс; пустой текст не запрашивается. Предыдущий fetch отменяется, результаты дополнительно привязаны к нормализованному запросу. Ошибки HTTP и невалидный JSON отображаются контролируемо.

Enter или выбор подсказки закрывает панель и открывает первую страницу `/?q=...`. Preview открывает существующие детали игры. Черновик и открытость панели сохраняются в sessionStorage по history key и URL, поэтому Back из preview восстанавливает панель. Native dialog обеспечивает модальность, фокус и Escape; прокрутка фона блокируется только на время открытия.

Home использует прежнюю сетку и существующий алгоритм useCatalogScroll. Query включён в загрузку и ключ состояния; пагинация сохраняет q и router state. Back ждёт успешных данных/DOM, восстанавливает anchor ID/offset с fallback на scrollY. Ошибка API не перезаписывает успешный снимок.

При подтверждении поиска в router state сохраняется предшествующая запись обычного каталога (режим, URL с page, history index). Последняя такая запись также хранится в sessionStorage для поиска со страницы игры. Очистка подтверждённого поиска возвращает к этой записи через browser history, включая её сохранённую прокрутку. Пагинация поиска переносит этот router state. Ссылки карточек и preview передают известную исходную запись в router state. Кеш sessionStorage сам по себе не считается доказательством доступности старого history index. Если запись истории уже недоступна, открывается сохранённый URL с restoreCatalogKey: hook читает прежний снимок только при совпадении URL и ждёт успешных данных, как при Back. Если снимка нет, используется начало каталога; без исходного URL — `/`. Без sessionStorage поиск и навигация продолжают работать, но черновик/позиция не сохраняются.

Кнопка очистки черновика не меняет каталог. Если текст совпадает с текущим подтверждённым q, очистка снимает поиск. В Header есть отдельная кнопка Clear search. Закрытие панели никогда не подтверждает и не очищает каталог.

## Безопасные проверки в Docker

Основной PHPUnit сохраняет жёсткую SQLite in-memory изоляцию:

```powershell
docker compose exec -T backend php vendor/bin/phpunit --filter TestDatabaseIsolationTest --do-not-cache-result
docker compose exec -T backend php vendor/bin/phpunit --do-not-cache-result
docker compose exec -T frontend node --test tests/games.test.mjs
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run build
```

Реальный pg_trgm проверяется отдельным Compose-проектом. Не объединяйте test-файл с compose.yaml:

```powershell
docker compose -p eneba-search-test -f compose.search-test.yaml up --build --abort-on-container-exit --exit-code-from search-backend
docker compose -p eneba-search-test -f compose.search-test.yaml down -v
```

PostgreSQL хранится в tmpfs, не публикует порт и не использует рабочие тома/сеть. Backend копирует код без `.env*` в tmpfs, устанавливает только существующие lock-зависимости. Отдельный PostgreSQL suite не использует RefreshDatabase, не меняет SQLite guard; перед единственным migrate проверяет marker, окружение, resolved connection, реальную БД/пользователя и пустую public schema. Фикстуры каждого теста откатываются транзакцией. Запуск suite вне этого окружения отклоняется. Для повторного запуска удалите только этот временный Compose-проект.

## Рабочая база и production

Во время реализации миграция применялась только во временной PostgreSQL. Рабочая PostgreSQL и Neon не изменялись. Чтобы включить поиск в рабочем окружении, сначала нужно отдельно применить новую миграцию; автоматического обхода отсутствующего расширения нет.

Для Neon требуется отдельное явное разрешение пользователя и прежний процесс [production-database.md](production-database.md). Никаких production-операций при build/startup не добавлено. До применения миграции старая версия каталога продолжает работать, а новый поиск сообщает об ошибке загрузки.

## Результаты проверки реализации

- Основной SQLite suite: **61 тест, 651 проверка**; исходная защита подключений сохранена.
- Отдельный PostgreSQL suite: **8 тестов, 153 проверки**, включая реальный pg_trgm и миграции в новой tmpfs-базе.
- Node API tests: **89 тестов**. Проверяют также runtime-валидацию search/preview.
- TypeScript и production build прошли; Oxlint и Pint — без ошибок.
- Chromium на production bundle с API из временной PostgreSQL: ввод/debounce/отмена, подсказки/preview, Enter с деталей, q/page/reload, Back/Forward, третий ряд на desktop/mobile, ошибка и задержанный Back, fallback при исчезнувшем anchor, очистка после пагинации и reload, прямое открытие игры с устаревшим history index, невалидный JSON, пустая выдача и пять ширин 320–1440 px. Необработанных JS-ошибок нет.
- Контрольные дампы рабочей PostgreSQL до/после совпали; Neon не использовался. Новых зависимостей нет. Deploy, commit и push не выполнялись.

## Изменённые и добавленные файлы

- `backend/app/Http/Controllers/GameSearchController.php`
- `backend/database/migrations/2026_11_12_000003_enable_pg_trgm_for_game_search.php`
- `backend/routes/api.php`
- `backend/tests/Feature/GameSearchValidationTest.php`
- `backend/tests/Postgres/GameSearchTest.php`
- `backend/tests/Postgres/phpunit.xml`
- `compose.search-test.yaml`
- `docs/game-search.md`
- `frontend/src/api/games.ts`
- `frontend/src/components/GameCard/GameCard.tsx`
- `frontend/src/components/Header/Header.module.css`
- `frontend/src/components/Header/Header.tsx`
- `frontend/src/components/SearchPanel/SearchPanel.module.css`
- `frontend/src/components/SearchPanel/SearchPanel.tsx`
- `frontend/src/hooks/useCatalogScroll.ts`
- `frontend/src/pages/Home/Home.module.css`
- `frontend/src/pages/Home/Home.tsx`
- `frontend/src/utils/catalogHistory.ts`
- `frontend/tests/games.test.mjs`
