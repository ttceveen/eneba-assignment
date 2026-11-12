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
.\scripts\production-db.ps1 -Action Migrate -DryRun
.\scripts\production-db.ps1 -Action Migrate
```

Перед применением требуется отдельное ручное подтверждение `MIGRATE NEON`; подробный процесс и отдельная команда сидеров — в [production-database.md](production-database.md). Секреты читаются из файла, не передаются текстом команд. Команды требуют доступа к БД из вашей сети, обходятся без PHP/Composer в Windows и функций Render SSH/one-off jobs. Выполняйте миграции осознанно до запуска соответствующей версии приложения. **В рамках этой задачи команды выполнялись только на отдельной локальной тестовой БД, не на Neon.**

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

## Исправление Render: exec frankenphp / Operation not permitted

В базовом образе `dunglas/frankenphp:1-php8.4-bookworm` исполняемый файл имеет mode 0755, owner root:root и file capability `cap_net_bind_service=ep`. До исправления штатные production ENTRYPOINT/CMD при `docker run --rm --cap-drop=ALL eneba-backend-prod` воспроизвели точный текст `/usr/local/bin/docker-php-entrypoint: 9: exec: frankenphp: Operation not permitted` и код 126. Это отказ Linux exec из-за file capabilities, отсутствующих в bounding set, а не отсутствие бита исполнения или прав на storage. Механизм подтверждён локально; фактические настройки безопасности Render не инспектировались.

В финальную стадию Dockerfile перед USER app добавлен `setcap -r /usr/local/bin/frankenphp`. Для PORT=10000 и других непривилегированных портов (>=1024) эта capability не нужна. Права 0755, USER app (UID 1000), production ENTRYPOINT/CMD, конфигурация HTTP и PostgreSQL оставлены прежними. Новых зависимостей, сервисов или привилегий не добавлено.

Проверено после сборки `docker build -f backend/Dockerfile.prod -t eneba-backend-prod ./backend`:

- Запуск штатными ENTRYPOINT/CMD с `--cap-drop=ALL --security-opt no-new-privileges=true` прошёл; PID 1 — frankenphp, UID 1000, все Cap* равны 0, NoNewPrivs=1. `getcap /usr/local/bin/frankenphp` пуст, mode 0755.
- PORT=18083, отдельный host-порт 18082: `/`, `/up`, `/api/health`, `/robots.txt` — 200; `/api/missing` и `/.env` — 404.
- Отдельный локальный PostgreSQL 17: подключение Laravel с sslmode=require/channel_binding=require прошло по TLS 1.3; права storage/bootstrap/cache корректны, запись в исходники запрещена. Development-база не использовалась.
- SIGTERM: shutdown complete, exit code 0, OOMKilled=false. Временные контейнеры и сеть удалены.
- Хэши Compose, development Dockerfile/entrypoint, локальных .env и связанных production-конфигураций не изменились. Development-контейнеры сохранили ID, StartedAt, RestartCount и состояние healthy. Пользовательские staged изменения не затрагивались.

Повторный deploy на Render выполняет пользователь после отправки изменений в GitHub. Здесь не выполнялись push, deploy, вход в Render или вызовы его API/CLI. Проверка исключает воспроизведённую причину EPERM; другие ограничения реальной платформы требуют проверки повторным deployment.

Основание: [FrankenPHP — running with no capabilities](https://frankenphp.dev/docs/docker/#running-with-no-capabilities), [Linux capabilities: execve и bounding set](https://man7.org/linux/man-pages/man7/capabilities.7.html).

## Исправление production CORS

Безопасная HTTP-диагностика опубликованных приложений выявила две независимые причины:

- JS bundle `https://eneba-assignment-phi.vercel.app/assets/index-BWOFwMqq.js` формировал fetch на `http://localhost:8000/api/health`. Нельзя определить по bundle, отсутствовала ли переменная при сборке или была задана локальным значением, но фактический адрес запроса подтверждён. Код использует VITE_API_BASE_URL, не VITE_API_URL.
- GET `https://eneba-assignment-tpln.onrender.com/api/health` с Origin `https://eneba-assignment-phi.vercel.app` вернул 200 и JSON {"status":"ok","database":"connected"}, но Access-Control-Allow-Origin был `http://localhost:5173`. OPTIONS для того же origin вернул 204 с тем же неправильным allow-origin и только GET/OPTIONS, Content-Type/Accept. Редиректов или ошибки API в этих ответах не было. Значение переменной Render не читалось: подтверждена эффективная конфигурация по HTTP-заголовкам.

В Laravel cors.php localhost сохранён отдельным разрешённым origin, FRONTEND_URL задаёт production-origin (fallback — конкретный Vercel-домен). Разрешены GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS и Content-Type/Accept/Authorization/X-Requested-With. CORS остаётся в стандартном Laravel middleware, без Caddy-заголовков или сторонних пакетов. supports_credentials=false: текущий fetch не использует cookies/credentials, авторизация и Sanctum пока не реализованы. CSRF и middleware безопасности не менялись. Будущая cookie-авторизация потребует отдельной настройки.

Во frontend fallback localhost действует только при DEV. Если production API URL отсутствует, страница показывает ошибку конфигурации и не выполняет fetch к localhost. Формирование endpoint осталось: base URL с `/api` плюс `/health`, без повторного `/api`.

**Действия пользователя:**

| Платформа | Переменная | Точное значение |
|---|---|---|
| Render | FRONTEND_URL | https://eneba-assignment-phi.vercel.app |
| Vercel | VITE_API_BASE_URL | https://eneba-assignment-tpln.onrender.com/api |

Задайте значения без завершающего слеша. Настройка только VITE_API_URL не влияет на текущий код. Самостоятельно отправьте изменения в GitHub, обновите переменные нужного production-окружения и выполните deployment backend и **пересборку frontend**: Vite встраивает API URL при build. После этого повторите GET/OPTIONS с production Origin: allow-origin должен точно совпасть с Vercel origin; bundle не должен отправлять API-запрос на localhost. До этих действий опубликованные версии сохраняют диагностированные ошибки.

**Локальная проверка:** отдельный PHPUnit-контейнер без исходных .env, реальных credentials, внешней БД или миграций — 8 tests, 91 assertions, --fail-on-warning успешно. Новый CorsTest проверяет production/local GET, preflight всех REST-методов и bearer-заголовков, запрещённый origin и CORS у 404/500. PHPUnit задаёт FRONTEND_URL только для тестов, чтобы результат не зависел от локальных переменных разработчика.

Реальные GET/OPTIONS к отдельному временному FrankenPHP подтвердили 200/204, оба разрешённых origins, отсутствие allow-origin у постороннего origin и CORS для API 404. Тестовый API использовал SQLite in-memory SELECT 1, не PostgreSQL. Frontend собран в отдельном Node-контейнере из read-only копии: TypeScript/Vite и lint прошли, 0 предупреждений; bundle содержит правильный Render URL без localhost:8000 и /api/api. Chromium загрузил этот bundle с production frontend origin и получил успешный CORS-ответ от изолированного Laravel (API GET перенаправлен тестом локально); ошибок JavaScript нет. Это локальная проверка сборки, не повторный публичный deploy.

Первый изолированный PHPUnit-прогон сообщил предупреждения об отсутствующем .env: исправлено созданием пустого .env только во временном контейнере. Предупреждение React о setState в effect устранено инициализацией состояния по наличию API URL. Исходные локальные .env не менялись.

Development-контейнеры не пересоздавались, не перезапускались и не останавливались; ID/StartedAt/RestartCount и защищённые файлы совпали до/после, все healthy. Миграции не выполнялись, volumes и development-база не затрагивались. Временные контейнеры удалены. Настройки Render/Vercel/Neon не менялись; push, commit и deployment не выполнялись. История дополнена доступными сообщениями до времени экспорта, без перезаписи прежних записей; последующий финальный ответ в экспорт не входит.

Дополнительный прогон с унаследованным FRONTEND_URL=http://localhost:5173 выявил приоритет $_SERVER над PHPUnit env-настройкой. Тестовый origin теперь задаётся через PHPUnit server force=true; повторный строгий прогон прошёл: 8 tests, 91 assertions. Это изменение только тестовой конфигурации.
