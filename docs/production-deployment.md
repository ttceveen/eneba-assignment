# Production backend: локальная подготовка

`backend/Dockerfile.prod` — отдельный образ PHP 8.4 / FrankenPHP в обычном режиме, без Octane. Контекст сборки — только `backend/`. Development Compose не меняется.

## Сборка и запуск

Из корня проекта в PowerShell:

```powershell
docker build -f backend/Dockerfile.prod -t eneba-backend-prod ./backend
# Показать новый ключ; самостоятельно сохраните его в файле ниже.
docker run --rm eneba-backend-prod php artisan key:generate --show
```

Самостоятельно создайте `backend/.env.production.local` (игнорируется Git и Docker build). Пример содержимого; замените значения, не добавляйте кавычки вокруг значений для `docker --env-file`:

```dotenv
APP_KEY=base64:REPLACE_WITH_GENERATED_KEY
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-BACKEND.onrender.com
FRONTEND_URL=https://YOUR-FRONTEND.vercel.app
DB_CONNECTION=pgsql
DATABASE_URL=postgresql://USER:URL_ENCODED_PASSWORD@HOST/DATABASE?sslmode=require&channel_binding=require
LOG_CHANNEL=stderr
LOG_LEVEL=info
PORT=10000
```

Для локального запуска укажите в этом файле адрес отдельной тестовой PostgreSQL, доступной контейнеру, и `APP_URL=http://localhost:18080`. На Windows адрес PostgreSQL на хосте — `host.docker.internal`; для отдельной Docker-сети используйте имя её контейнера и добавьте `--network ИМЯ_СЕТИ`. Параметры TLS должны соответствовать тестовому серверу; `sslmode=disable&channel_binding=disable` допустимы только для локального сервера без TLS. Не подключайте тест к существующей development-базе.

```powershell
docker run --rm --name eneba-backend-prod-local --env-file backend/.env.production.local -p 127.0.0.1:18080:10000 eneba-backend-prod
```

Проверки: `http://localhost:18080/up`, `/api/health` (также проверяет БД), `/robots.txt`. Корень также отвечает Laravel. Остановка из другого терминала: `docker stop --timeout 10 eneba-backend-prod-local`.

FrankenPHP слушает `0.0.0.0:$PORT` (по умолчанию 10000), обслуживает статические файлы и Laravel через `/app/public/index.php`. На Render TLS завершается платформой; внутри контейнера используется HTTP. Entry point через `exec` оставляет сервер главным процессом и передаёт SIGTERM. Для Artisan передайте другую команду после имени образа. Миграции и генерация ключа автоматически не выполняются. `storage` и `bootstrap/cache` доступны пользователю app (UID 1000), исходники ему недоступны для записи.

Composer устанавливает lock-файл с `--no-dev --optimize-autoloader`. Tinker/PsySH исключаются только из копии Composer-зависимостей внутри builder: исходные composer.json/lock и development-зависимости остаются прежними; остальные версии не обновляются (`--minimal-changes`). Итоговый образ содержит Composer, но не PHPUnit, Pint, Pail, Collision и Tinker. `.env`, локальные кэши, логи и тесты исключены из build context. APP_KEY и DATABASE_URL задаются только при запуске; config cache с секретами при сборке не создаётся. PHP и Laravel пишут ошибки в stderr.

## Render, Neon и Vercel — действия пользователя

Все действия в облачных сервисах выполняете вы; локальная подготовка их не создаёт и не настраивает.

1. Самостоятельно создайте PostgreSQL в Neon и получите строку подключения. Для миграций используйте direct connection URL, предоставленный Neon.
2. Сохраните URL с `sslmode=require&channel_binding=require` в локальном `backend/.env.production.local`. Спецсимволы в логине/пароле должны быть URL-encoded. Ключ APP_KEY создайте один раз и храните одинаковым для CLI и backend.
3. Вручную выполните из корня проекта:

```powershell
docker run --rm --env-file backend/.env.production.local eneba-backend-prod php artisan migrate --force
docker run --rm --env-file backend/.env.production.local eneba-backend-prod php artisan migrate:status
```

Секреты читаются из файла, не передаются текстом команд. Команды требуют доступа к БД из вашей сети, обходятся без PHP/Composer в Windows и функций Render SSH/one-off jobs. Выполняйте миграции осознанно до запуска соответствующей версии приложения. **В рамках этой задачи команды выполнялись только на отдельной локальной тестовой БД, не на Neon.**

4. Вручную настройте Render Docker service: Docker build context `./backend`, Dockerfile `./backend/Dockerfile.prod`, health check `/up`; задайте APP_KEY, APP_URL, FRONTEND_URL, DB_CONNECTION, DATABASE_URL, APP_ENV, APP_DEBUG, LOG_CHANNEL из примера. PORT предоставляет Render (по умолчанию образ использует 10000). Поле Root Directory оставьте пустым, чтобы пути были относительно корня репозитория. Не задавайте устаревшие DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD и DB_URL вместе с DATABASE_URL.
5. Самостоятельно настройте Vercel: Root Directory `frontend`, build `npm run build`, output `dist`, env `VITE_API_BASE_URL=https://YOUR-BACKEND.onrender.com/api`. Production-сборка локально при установленном Node.js:

```powershell
cd frontend
npm ci
$env:VITE_API_BASE_URL = "https://YOUR-BACKEND.onrender.com/api"
npm run build
```

Адрес API подставляется Vite при сборке: после изменения переменной пересоберите frontend. В этой задаче frontend собирался из read-only копии в отдельном Node-контейнере. Production Dockerfile для frontend не создаётся.

## Подключение и границы проверки

В pgsql-конфигурации DATABASE_URL имеет приоритет над DB_URL. Laravel разбирает host/port/database/user/password и query-параметры URL; эти значения перекрывают отдельные DB_* при их наличии в URL. `sslmode` передаётся Laravel в PDO DSN. Установленный Laravel не передаёт `channel_binding` в DSN, поэтому production entrypoint извлекает его из URL и экспортирует стандартный `PGCHANNELBINDING` для libpq; параметр URL имеет приоритет над одноимённой переменной. Без query-параметра сохраняется PGCHANNELBINDING или стандартное `prefer`. Некорректное значение завершает entrypoint с ошибкой. Этот мост действует для HTTP и Artisan, запущенных обычным `docker run`; `--entrypoint` его обходит.

Локально подтверждены libpq 15.19, PostgreSQL 17, TLS 1.3, SCRAM и соединение с `sslmode=require&channel_binding=require`. Контрольный тест `sslmode=disable&channel_binding=require` отклонён libpq: требование channel binding не теряется. Проверены ручные миграции, отсутствие автоматических миграций, PORT=18081, HTTP 200/404, статика, non-root и SIGTERM. Это подтверждение локального пути подключения, **не тест совместимости с реальным endpoint Neon**. Render, Vercel, Neon, их сеть, proxy/pooling и публичный TLS не проверялись; деплой не выполнялся.

Сессии и файловый кэш в этом минимальном образе локальные и теряются при пересоздании контейнера Render. Постоянное хранение пользовательских файлов, несколько реплик, authentication/OAuth, фоновые задачи и Vercel SPA rewrites здесь не настраиваются.

Источники: [FrankenPHP Docker](https://frankenphp.dev/docs/docker/), [Laravel database URL](https://laravel.com/framework/docs/database/config), [libpq connection parameters](https://www.postgresql.org/docs/15/libpq-connect.html), [libpq environment](https://www.postgresql.org/docs/15/libpq-envars.html).

## Итог локальной проверки

Образ `eneba-backend-prod` собран командой из задания. `/`, `/up`, `/api/health` и `/robots.txt` вернули 200; отсутствующий API-маршрут и `/.env` — 404. PORT=18081 проверен через отдельный host-порт 18080. SIGTERM завершил FrankenPHP с кодом 0, OOM=false. До ручных миграций тестовая БД имела 0 таблиц; после команды migrate все три миграции отмечены Ran. Dev-зависимости и .env в финальном образе отсутствуют; Composer validate/check-platform-reqs прошли. Production-сборка frontend прошла, указанный VITE_API_BASE_URL найден в bundle.

Проверки обнаружили и помогли исправить: неверный флаг Composer remove (заменён на --update-no-dev), унаследованную запись в исходники из Windows (убрана внутри образа), запрет исполнения на временном tmpfs frontend-проверки (использован exec только для этого tmpfs). Существующие Dockerfile/Compose, .env, исходники frontend и Composer-файлы разработки не изменены; хэши проверены. ID dev-контейнеров, время запуска и RestartCount совпали до/после; все три healthy. Временные контейнеры и сеть теста удалены, тома development не затрагивались.

История запросов, ответов, ошибок и исправлений добавлена в `ai-session.md` без перезаписи прежних записей и без секретов. Экспорт охватывает доступные записи до указанного в дополнении времени; будущий финальный ответ туда не входит.
