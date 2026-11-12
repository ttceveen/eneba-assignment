# Eneba — локальное окружение разработки

PHP 8.4 / Laravel 13, React / TypeScript / Vite / React Router / CSS Modules, Node.js 24 LTS, PostgreSQL 17. Требуется Docker Desktop с Linux containers и Docker Compose. Команды выполняются из `C:\Users\ttcev\Desktop\projects\eneba-assignment` в PowerShell.

## Запуск

```powershell
Copy-Item .env.example .env
Copy-Item frontend/.env.example frontend/.env
docker compose up -d --build
docker compose ps
docker compose logs -f backend frontend
```

Копируйте примеры только при первом запуске: существующие `.env` сохраняйте. Backend сам создаёт `backend/.env`, генерирует локальный APP_KEY, устанавливает Composer-зависимости и выполняет миграции после готовности PostgreSQL. Frontend выполняет `npm ci`. Первый запуск требует интернета и занимает несколько минут. Lock-файлы следует хранить в Git.

- Frontend: http://localhost:5173
- Backend: http://localhost:8000
- Проверка API и подключения к БД: http://localhost:8000/api/health
- Проверка Laravel: http://localhost:8000/up

Страница frontend запрашивает API из браузера и показывает результат подключения к PostgreSQL. Бизнес-функциональность не реализована. CORS разрешён для FRONTEND_URL. Параметры БД, BACKEND_URL, FRONTEND_URL и VITE_API_BASE_URL задаются в корневом `.env`; Compose передаёт их контейнерам. При изменении значений выполните `docker compose up -d --force-recreate`. `frontend/.env` используется при запуске Vite вне Compose. APP_KEY хранится только в игнорируемом backend/.env.

PostgreSQL доступен backend по имени `postgres:5432`; порт на Windows не опубликован. Данные сохраняются в именованном томе. Значения пароля в примерах предназначены только для локальной разработки. Изменение POSTGRES_* не меняет учётную запись уже инициализированной БД: для существующих данных меняйте её средствами PostgreSQL. Backend и frontend работают под UID 1000. Стандартный PostgreSQL entrypoint подготавливает том и запускает сервер под пользователем postgres.

Исходники подключены bind mounts; vendor и node_modules — отдельные Linux-тома. Для HMR на Windows включён polling Vite. Docker shell-скрипты должны иметь LF.

## Команды

```powershell
# Artisan и миграции
docker compose exec backend php artisan about
docker compose exec backend php artisan migrate
docker compose exec backend php artisan migrate:status
# Composer и PHPUnit
docker compose exec backend composer install
# Сначала проверить фактическое безопасное подключение (без RefreshDatabase)
docker compose exec -T backend php vendor/bin/phpunit --filter TestDatabaseIsolationTest --do-not-cache-result
# Затем полный набор; phpunit.xml принудительно задаёт SQLite in-memory
docker compose exec -T backend php vendor/bin/phpunit --do-not-cache-result
# npm, сборка и проверка frontend
docker compose exec frontend npm install
docker compose exec frontend npm run build
docker compose exec frontend npm run lint
# Runtime-проверка API без дополнительных зависимостей (Node.js 24)
docker compose exec -T frontend node --test tests/games.test.mjs
# Логи и состояние
docker compose logs -f
docker compose ps
# Остановка с сохранением данных
docker compose down
# Повторный запуск
docker compose up -d --build
```

`docker compose down -v` удаляет данные БД и тома зависимостей — не используйте для обычной остановки. PHPUnit принудительно задаёт APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory: и очищает DB_URL/DATABASE_URL в environment и $_SERVER, перекрывая значения Compose. Tests\TestCase до RefreshDatabase проверяет конфигурацию и реальный PDO SQLite in-memory; создание PostgreSQL или файлового SQLite-подключения в тестах запрещено. Небезопасная конфигурация (включая cached config) завершает тест до миграций. Все Feature-тесты должны наследовать Tests\TestCase; не обходите защиту и не переопределяйте createApplication без вызова родителя. PostgreSQL-тесты этим набором не поддерживаются. Рабочая PostgreSQL не используется. Реальное подключение PostgreSQL проверяется API health и миграциями. После изменения composer.lock перезапустите backend; после изменения package-lock.json перезапустите frontend. Для новых PHP-расширений пересоберите образ.

## История AI

Полный текст исходного задания находится в `docs/ai-request.txt`. Экспорт текущей сессии — `docs/ai-session.md`: полные доступные записи запросов, ответов и инструментов до указанного времени экспорта, без сокращения содержимого; секреты редактируются. Последующие сообщения после момента экспорта в файл автоматически не добавляются. История чата остаётся в Codex.
