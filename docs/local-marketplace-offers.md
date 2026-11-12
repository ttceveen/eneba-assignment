# Локальные продавцы, предложения и тестовые ключи

## Выполнено в рабочей локальной PostgreSQL

Подключение проверено через Laravel: pgsql, Docker host `postgres`, база `eneba`. Применены только три ожидавшие миграции Seller/Offer/DigitalKey; все имеют статус Ran. Новых изменений схемы и правок существующих миграций нет.

Запущены по отдельности SellerSeeder, OfferSeeder и DigitalKeySeeder. Для существующих 40 игр созданы **5 продавцов, 120 предложений и 493 ключа**. Предложений на игру — 2–4, цены различаются. Все новые продавцы/предложения активны, ключи available, сумма quantity = 493. Значения ключей уникальны, явно тестовые и хранятся зашифрованными.

Повторный запуск всех трёх сидеров не изменил ни количество, ни отпечатки содержимого записей. Содержимое games и game_popularities совпадает с дампом до работы. APP_KEY не менялся, значения ключей не печатались.

```powershell
docker compose exec -T backend php artisan migrate:status
docker compose exec -T backend php artisan db:seed --class=SellerSeeder --no-interaction
docker compose exec -T backend php artisan db:seed --class=OfferSeeder --no-interaction
docker compose exec -T backend php artisan db:seed --class=DigitalKeySeeder --no-interaction
```

Сидеры сохраняют существующие записи, используя firstOrCreate. DigitalKeySeeder сравнивает отпечатки расшифрованных значений в памяти и создаёт только недостающие тестовые ключи через Eloquent. Это ручная подготовка тестовых данных: автоматической синхронизации остатков в приложении нет. Если у предложения уже есть не-available ключи либо ключей больше quantity, сидер завершится ошибкой и откатит свою транзакцию, не удаляя ключи и не меняя quantity/статусы. DatabaseSeeder и существующие игровые сидеры не запускались.

## API

Существующие `GET /api/games` и `GET /api/games/search` (включая preview) дополнены:

- `min_price`: decimal-строка с двумя знаками или null;
- `offers_count`: число доступных предложений.

Предложения доступны, когда продавец активен, само предложение активно и quantity > 0. Общая Eloquent-связь availableOffers применяется и к агрегатам, и к деталям. Каталог использует withMin/withCount и не загружает коллекции предложений или ключей. Популярность, All Games, поиск и пагинация по 12 сохранены.

`GET /api/games/{id}` также возвращает `offers`, отсортированные по price и id. Каждый элемент содержит только id, seller {id,name}, price и quantity. Продавцы загружаются пакетно. Минимум/количество на странице игры вычисляются из уже загруженного списка. Цифровые ключи не читаются и не входят в ответы. Исходные поля id/name/location/img и pagination envelope сохранены.

## Frontend

Карточки получают минимальную цену и количество с API, отображают «От €…» и число предложений. При отсутствии — «Нет предложений», без подставной цены. Настоящая нулевая цена показывается как €0.00. На странице игры отображаются продавец, цена и доступное количество без кнопок покупки. Сетка, Header/Footer, поиск и навигация сохранены; высота карточек одинакова.

Типы и runtime-проверки расширены для decimal-цен, счётчиков и безопасной структуры предложений. Старые ответы без новых полей по-прежнему принимаются клиентом. Неполные/невалидные новые данные приводят к существующему контролируемому состоянию ошибки.

## Проверки

- Основной PHPUnit в изолированной SQLite: **84 теста, 903 проверки**. Добавлены MarketplaceSeederTest и OfferPresentationTest; существующие API-тесты адаптированы к добавленным публичным полям.
- PostgreSQL-модели/ограничения: **26 тестов, 83 проверки**, отдельная временная БД.
- PostgreSQL-поиск: **8 тестов, 153 проверки**, отдельная временная БД.
- Node API tests: **107 тестов**.
- TypeScript, Oxlint, Pint и production build прошли.
- Каталог: 2 SQL-запроса на страницу; детали: 3 запроса с route binding. Число запросов не растёт с числом предложений; digital_keys не запрашиваются публичным API.
- В обоих режимах все 40 сводок API совпали с независимым группирующим SQL.
- Chromium на настоящем `http://localhost:5173`: реальные цены/счётчики, детали, Back, All Games/page 2 и восстановление третьего ряда на мобильном экране, Header search/preview/Enter. Ширины 320/375/768/1024/1440, одинаковые высоты карточек, нет горизонтального скролла и JS/console errors.
- Отсутствие предложений и нулевая цена дополнительно проверены API-фикстурами в браузере без изменения рабочих записей; backend покрывает эти состояния отдельными тестами.

Neon, production-конфигурация, зависимости и ранее подготовленные структуры таблиц не изменялись. Нет checkout, резервирования, блокировок, выдачи ключей или автоматического управления остатками. Deploy, commit и push не выполнялись.

## Файлы текущей задачи

- `backend/app/Http/Controllers/GameController.php`
- `backend/app/Http/Controllers/GameSearchController.php`
- `backend/app/Http/Resources/GameResource.php`
- `backend/app/Http/Resources/OfferResource.php`
- `backend/app/Models/Game.php`
- `backend/database/seeders/DigitalKeySeeder.php`
- `backend/database/seeders/OfferSeeder.php`
- `backend/database/seeders/SellerSeeder.php`
- `backend/tests/Feature/GameAlphabeticalTest.php`
- `backend/tests/Feature/GameIndexTest.php`
- `backend/tests/Feature/GameRankingTest.php`
- `backend/tests/Feature/GameShowTest.php`
- `backend/tests/Feature/MarketplaceSeederTest.php`
- `backend/tests/Feature/OfferPresentationTest.php`
- `backend/tests/Postgres/GameSearchTest.php`
- `docs/local-marketplace-offers.md`
- `frontend/src/api/games.ts`
- `frontend/src/components/GameCard/GameCard.module.css`
- `frontend/src/components/GameCard/GameCard.tsx`
- `frontend/src/pages/GameDetails/GameDetails.module.css`
- `frontend/src/pages/GameDetails/GameDetails.tsx`
- `frontend/src/pages/Home/Home.tsx`
- `frontend/tests/games.test.mjs`
