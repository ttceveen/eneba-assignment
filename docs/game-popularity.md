# Активность и популярность игр

`games.is_active` — boolean NOT NULL DEFAULT TRUE. Существующие игры остаются активными; исходная миграция games и GameResource не менялись.

`game_popularities` содержит только:

- `game_id BIGINT NOT NULL PRIMARY KEY REFERENCES games(id) ON DELETE CASCADE`;
- `purchase_count BIGINT NOT NULL DEFAULT 0 CHECK (purchase_count >= 0)`.

Индексы: первичный ключ и `idx_game_popularity_ranking (purchase_count DESC, game_id ASC)`. Других индексов не добавлено. Миграция создаёт нулевые записи для существующих игр через INSERT SELECT ON CONFLICT DO NOTHING; уже имеющийся счётчик эта операция не перезаписывает. На пустой БД backfill не добавляет строк. PostgreSQL выполняет миграцию таблицы и backfill в одной транзакции.

## Создание и обновление

Связи — `Game::popularity()` / `GamePopularity::game()`. GamePopularity использует game_id без автоинкремента и timestamps. Новый Game получает boolean cast и default is_active=true.

Событие `Game::created` создаёт популярность 0. `Game::save()` оборачивает сохранение новой модели в транзакцию вместе с этим событием; ошибка создания популярности откатывает вставку игры. Это покрывает обычные Eloquent create/save/firstOrCreate. Сохранение существующей игры не пересоздаёт статистику. Обновление популярности не меняет games и его timestamps.

**Граница механизма:** прямые SQL/Query Builder insert/upsert, массовые вставки и операции с отключёнными событиями (`saveQuietly`, `withoutEvents`) не вызывают created. Они не гарантируют наличие дочерней записи; для них нужна явно предусмотренная согласованная вставка обеих записей в одной транзакции. Используйте обычный Eloquent-сценарий, если такой специальной операции нет.

## API

Каталог использует INNER JOIN, `games.is_active = true`, ORDER BY purchase_count DESC, game_id ASC и стандартный paginate(12). Resource по-прежнему отдаёт только id/name/location/img. Счётчик и is_active в публичный JSON не добавлены. Meta.total считает только активные игры; неактивная отдельная игра даёт 404.

Frontend не изменялся: он сохраняет порядок API, размер страницы, query page, состояния и отмену устаревших запросов.

## Локальные команды

```powershell
docker compose exec backend php artisan migrate
# Для пустой БД сначала создайте обычные игры; событие создаст их статистику.
docker compose exec backend php artisan db:seed --class=GameSeeder
# Отдельная, необязательная операция с демонстрационными значениями.
docker compose exec backend php artisan db:seed --class=GamePopularitySeeder
```

Демо-значения: Cyberpunk 2077 — 150; The Witcher 3 — 320; Elden Ring — 75; Baldur's Gate 3 — 200; GTA V — 110. Это **не реальные покупки**. По уточнению пользователя демо-сидер может заменять только нулевые значения; условный UPDATE защищает все ненулевые счётчики. Повторный запуск не сбрасывает значения. Если счётчик специально обнулить, повторный запуск демо-сидера снова назначит ему демо-значение. Другие поля games не обновляются.

Никакого увеличения счётчиков при покупках, заказов, платежей или интерфейса изменения активности здесь нет.

## Проверки

- PostgreSQL и SQLite in-memory: в каждом прогоне **24 tests, 398 assertions**, без предупреждений. Покрыты defaults, PK/FK/CHECK/NOT NULL/cascade, атомарный rollback, независимость обновлений, повторные сидеры, активность, ранжирование и пагинация.
- Для 31 активной игры из 40 тесты получили meta.total=31, last_page=3; неактивная игра с самым высоким счётчиком исключена. Статусы рабочих игр ради проверки не менялись.
- Локальная рабочая БД: 40 игр, 40 популярностей, 40 активных. До демо-сидера все счётчики были 0. ID, названия, img, location и timestamps существующих игр сохранены. Повторный migrate: Nothing to migrate; повторный демо-сидер не изменил счётчики.
- HTTP страницы 1/2/3/4: 12/12/12/4, total=40, last_page=4; первые игры — Witcher 3, Baldur's Gate 3, Cyberpunk, GTA V, Elden Ring. Нулевые счётчики отсортированы по ID, дубликатов между страницами нет.
- Frontend TypeScript/Vite build прошёл. Chromium проверил порядок API, страницы, reload/Back/Forward, детали игры, loading/empty/error, отмену позднего ответа и ширины 320–1440 px. JS errors=0. Рабочий frontend дополнительно проверен напрямую без перехватов.

## EXPLAIN (ANALYZE, BUFFERS)

На локальных 40 строках после ANALYZE: Seq Scan по 40 строкам каждой таблицы, Hash Join (40 строк), отдельный Sort (top-N heapsort, выбирает 12), Limit 12. Shared buffers: 2 hits. Execution Time: 0.072 ms, Planning Time: 0.237 ms — единичный локальный замер, не прогноз для production.

Индекс idx_game_popularity_ranking существует, но в этом плане не использован: для двух маленьких таблиц последовательное чтение и небольшая сортировка дешевле индексного пути. Настройки планировщика не принуждались; дополнительные индексы или искусственные рабочие данные не создавались.

## Production

Neon не подключался и не изменялся. Для production используйте процесс из production-database.md: сначала локальные проверки, затем отдельные пользовательские подтверждения миграций и выбранного сидера. Демо-сидер не запускается автоматически при миграции, старте backend или деплое.

```powershell
.\scripts\production-db.ps1 -Action Migrate -DryRun
.\scripts\production-db.ps1 -Action Seed -Seeder GamePopularitySeeder -DryRun
```
