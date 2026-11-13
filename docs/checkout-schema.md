# Подготовка данных Checkout

Добавлены orders, order_items, payments и поля назначения/резерва/отпечатка в digital_keys.
Схема описана ниже. InventoryService, CheckoutService и checkout API теперь реализованы: см. [checkout-services.md](checkout-services.md). Demo-оплата и получение оплаченных ключей описаны в [payments.md](payments.md). Фоновые задачи не реализованы.

## Схема и ограничения

- users 1:N orders; orders 1:N order_items и payments.
- offers 1:N order_items; order_items 1:N digital_keys.
- FK без каскадного удаления сохраняют историю при попытке удалить связанные аккаунты и предложения.
- Составной FK digital_keys(order_item_id, offer_id) → order_items(id, offer_id) запрещает назначение ключа чужого предложения. Для него необходим UNIQUE order_items(id, offer_id). Это служебный индекс для ссылочной целостности, а не дополнительное бизнес-ограничение.
- CHECK ограничивают статусы, суммы, положительное количество и согласованность полей ключа.
- orders: pending_payment / paid / expired / cancelled.
- payments: pending / succeeded / failed / cancelled; отдельные частичные UNIQUE допускают не более одного pending и одного succeeded на заказ. Они не запрещают сочетание pending+succeeded: последовательность попыток после оплаты контролируется будущей бизнес-логикой.
- UNIQUE(provider, external_id) действует для ненулевых external_id.
- Даты новых таблиц и резервов — timestamptz; денежные суммы — numeric(12,2), Eloquent decimal:2.
- Индексы: orders(user_id,created_at), pending orders(reserved_until); order_items(order_id), (offer_id), UNIQUE(id,offer_id); payments(order_id,created_at) и три частичных UNIQUE; digital_keys(order_item_id,offer_id), reserved digital_keys(reserved_until), UNIQUE(key_fingerprint). Существующий available (offer_id,id) сохранён.
- Откат сначала снимает расширение digital_keys, затем новые таблицы. При наличии назначений, отпечатков или истории down() отказывается удалять данные. Рабочие данные ради отката не очищать.

## Защита содержимого цифровых ключей

Локальный backfill завершён: проверены 493 записи, дубликаты не обнаружены, заполнены 493 отпечатка. Новая миграция 2026_11_13_000003_require_digital_key_fingerprints устанавливает PostgreSQL NOT NULL после проверки отсутствия NULL. UNIQUE(key_fingerprint) и проверка формата сохраняются. Миграция блокирует конкурентные записи между проверкой и ALTER; при NULL завершается ошибкой без изменения записей.

HMAC-SHA256 централизован в DigitalKey::fingerprint(). При создании или изменении key_value через Eloquent отпечаток вычисляется автоматически. Отпечаток и key_value скрыты при сериализации. UNIQUE действует глобально независимо от предложения и статуса. DuplicateDigitalKeyException возвращает понятное сообщение без SQL, bindings и цепочки исходного исключения; неуспешная запись откатывается до savepoint, сохраняя пригодность внешней транзакции.

DIGITAL_KEY_FINGERPRINT_SECRET — отдельный стабильный криптографически случайный секрет минимум 32 байта; совпадение с APP_KEY или его декодированным значением запрещено. Локально он хранится только в игнорируемом backend/.env, APP_KEY не менялся. Не коммитить, не печатать и не менять секрет без процедуры перевычисления отпечатков. Для Render секрет нужно позднее настроить отдельно через environment, с отдельным разрешением на production backfill/миграцию. Не использовать разные секреты у процессов, работающих с одной БД.

### Проверка и backfill другого окружения

Команда читает все записи через существующий Eloquent encrypted cast, проверяет вычисленные отпечатки, совпадение уже записанных значений и дубликаты. Только после полной проверки заполняет NULL в одной транзакции. Блокировка таблицы исключает конкурентный импорт на время операции. Ciphertext, timestamps, статусы и offers.quantity не меняются.

```sh
docker compose exec -T backend php artisan digital-keys:backfill-fingerprints --allow-decryption --dry-run
docker compose exec -T backend php artisan digital-keys:backfill-fingerprints --allow-decryption
```

Без --allow-decryption ключи не читаются. Dry-run тоже расшифровывает значения в памяти и для нового окружения требует разрешения. Выводятся только количества проверенных, конфликтующих, несовпадающих и заполненных записей. При конфликте изменений нет; автоматического удаления или исправления ключей нет. Повторный запуск после успешного backfill проверяет все записи и записывает 0. При ошибке записи транзакция откатывается.

Прямой SQL и bulk insert обходят события Eloquent. NOT NULL и UNIQUE отклоняют отсутствующий или повторяющийся отпечаток, но PostgreSQL самостоятельно не вычисляет HMAC из Laravel ciphertext. Все импорты должны пользоваться моделью либо тем же централизованным вычислением HMAC. Поддельный произвольный отпечаток в привилегированном прямом SQL не проверяется как HMAC: это граница доверия приложения и доступа к БД.

Neon этой задачей не изменялась. Любые production-операции требуют отдельного согласования согласно AGENTS.md.

## Остатки и границы гарантий

Источник истины — digital_keys; offers.quantity сохранён как материализованный счётчик, CHECK quantity >= 0 не менялся. Будущие переходы available→reserved уменьшают счётчик, reserved→available увеличивают, reserved→sold не меняют. Изменение ключа и счётчика должно быть одной транзакцией.

Этап создания схемы не добавлял триггеры счётчика или операции резервирования; последующая реализация InventoryService/CheckoutService описана в checkout-services.md. Схема не обеспечивает сама по себе 15-минутный срок, допустимые переходы sold→available, равенство числа ключей quantity позиции, сумм заказа/платежа или идемпотентную выдачу/webhooks. Это обязанности следующего этапа, с транзакциями и защитой конкурентного назначения. reserved_until хранит срок; конкретные 15 минут задаст будущая логика.

## Проверки

Основная suite: docker compose exec -T backend php vendor/bin/phpunit --do-not-cache-result.

PostgreSQL-специфичные проверки находятся в tests/Postgres/checkout.xml (CheckoutSchemaTest) и tests/Postgres/fingerprints.xml (DigitalKeyFingerprintTest). Запускать только с существующей схемой изоляции PostgreSQL suites: новый временный контейнер postgres:17, отдельная Docker-сеть, alias search-postgres, пустая БД/роль eneba_search_test; SEARCH_TEST_SANDBOX=1, APP_ENV=testing, DB_CONNECTION=pgsql, DB_HOST=search-postgres, DATABASE_URL и DB_URL пустые. Suite проверяет эти значения, фактическую базу и пустую схему до migrate. Нельзя направлять её на локальную рабочую eneba или Neon.

Пример команды внутри изолированного runner с тестовой копией backend и установленным vendor:

```sh
php vendor/bin/phpunit -c tests/Postgres/checkout.xml --do-not-cache-result
```

Все backfill-проверки используют только синтетические ключи временной БД. Основная SQLite suite проверяет регрессии существующих API, но не является доказательством PostgreSQL CHECK/частичных UNIQUE.
