# Отчёт о настройке Docker

Проверено 10 ноября 2026. Проект: C:\Users\ttcev\Desktop\projects\eneba-assignment.

## Результат

Все три сервиса собраны, запущены и оставлены работающими: backend healthy, frontend healthy, postgres healthy. PHP 8.4.27, Laravel 13.40.0, Node.js 24.22.2, Vite 8.3.7. Backend и frontend запускаются под UID 1000. PostgreSQL не публикует порт на Windows, данные находятся в постоянном Docker volume.

- http://localhost:8000 — HTTP 200.
- http://localhost:5173 — HTTP 200.
- http://localhost:8000/api/health — HTTP 200, {"status":"ok","database":"connected"}; выполняется реальный SELECT 1 через Laravel.
- Windows curl.exe подтвердил доступность frontend и API по localhost.
- Все 3 штатные миграции применены к PostgreSQL; повторный migrate сообщает Nothing to migrate.
- composer test: 2 passed, 2 assertions. Это штатные тесты свежего Laravel-шаблона, не бизнес-тесты.
- composer validate --strict и check-platform-reqs: успешно.
- npm run build: успешно (TypeScript + production Vite).
- npm run lint: 0 ошибок и предупреждений.
- Chromium/Playwright: React получил ответ API, отобразил успешное подключение к PostgreSQL; ошибок JavaScript 0.
- HMR: временное изменение заголовка отобразилось без перезагрузки страницы; исходный файл восстановлен, проверка повторена после перезапуска.
- docker compose down и повторный up -d --build --wait: успешно, все сервисы healthy, миграции и данные тома сохранены.

Скриншот браузера: environment.png.

## Проблемы и решения

1. Стандартный shell sandbox не запускался из-за отсутствующего bwrap. Команды выполнены через разрешённый механизм повышения прав; настройки безопасности не менялись.
2. В проекте были пустые backend/frontend и три пользовательских текстовых документа. Приложения инициализированы официальными шаблонами; пользовательские документы сохранены. Неиспользуемые Vite/npm-конфигурации Laravel-шаблона удалены: отдельный frontend находится в frontend/.
3. Composer-шаблонизатор сообщил о git dubious ownership внутри временного контейнера. Распаковка Laravel завершилась успешно; глобальные Git-настройки не менялись. Постоянный backend запускается под UID 1000.
4. Windows Computer Use не удалось инициализировать из-за ошибки sandboxCwd. Проверка выполнена headless Chromium через Playwright, отдельно проверены порты из Windows curl.exe.
5. Для Chromium не хватало библиотек Linux. Они распакованы только в /tmp/eneba-browser-check; устаревший индекс пакетов обновлён также только в /tmp. Глобальная установка библиотек не выполнялась.
6. Laravel и frontend изначально не были установлены. Использованы PHP 8.4 и стабильная ветка Laravel 13; зависимости зафиксированы в composer.lock/package-lock.json. Дополнительные сервисы, OAuth и платежи не добавлялись.

## Запуск и остановка

Из корня проекта:

```powershell
docker compose up -d --build
docker compose down
```

Подробные команды Artisan, Composer, PHPUnit, npm и логов — в ../README.md.

## Файлы

Из существующих файлов изменены только .gitattributes (LF для Docker) и .gitignore (зависимости и результаты сборки). Созданы backend/ и frontend/ со штатными исходниками, lock-файлами и локальными env-примерами; compose.yaml, docker/php/Dockerfile, docker/php/entrypoint.sh, docker/frontend/Dockerfile, .dockerignore, .env.example, README.md и документы docs/. Локальные .env игнорируются Git. Бизнес-функциональность не добавлена. Коммит и push не выполнялись.

Полный список созданных файлов, предназначенных для Git (без vendor, node_modules, секретов и runtime-кэшей):

- `.dockerignore`
- `.env.example`
- `README.md`
- `backend/.editorconfig`
- `backend/.env.example`
- `backend/.gitattributes`
- `backend/.gitignore`
- `backend/AGENTS.md`
- `backend/CLAUDE.md`
- `backend/README.md`
- `backend/app/Http/Controllers/Controller.php`
- `backend/app/Models/User.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/artisan`
- `backend/bootstrap/app.php`
- `backend/bootstrap/cache/.gitignore`
- `backend/bootstrap/providers.php`
- `backend/composer.json`
- `backend/composer.lock`
- `backend/config/app.php`
- `backend/config/auth.php`
- `backend/config/cache.php`
- `backend/config/cors.php`
- `backend/config/database.php`
- `backend/config/filesystems.php`
- `backend/config/logging.php`
- `backend/config/mail.php`
- `backend/config/queue.php`
- `backend/config/services.php`
- `backend/config/session.php`
- `backend/database/.gitignore`
- `backend/database/factories/UserFactory.php`
- `backend/database/migrations/0001_01_01_000000_create_users_table.php`
- `backend/database/migrations/0001_01_01_000001_create_cache_table.php`
- `backend/database/migrations/0001_01_01_000002_create_jobs_table.php`
- `backend/database/seeders/DatabaseSeeder.php`
- `backend/phpunit.xml`
- `backend/public/.htaccess`
- `backend/public/favicon.ico`
- `backend/public/index.php`
- `backend/public/robots.txt`
- `backend/resources/views/welcome.blade.php`
- `backend/routes/api.php`
- `backend/routes/console.php`
- `backend/routes/web.php`
- `backend/storage/app/.gitignore`
- `backend/storage/app/private/.gitignore`
- `backend/storage/app/public/.gitignore`
- `backend/storage/framework/.gitignore`
- `backend/storage/framework/cache/.gitignore`
- `backend/storage/framework/cache/data/.gitignore`
- `backend/storage/framework/cache/locks/.gitignore`
- `backend/storage/framework/sessions/.gitignore`
- `backend/storage/framework/testing/.gitignore`
- `backend/storage/framework/views/.gitignore`
- `backend/storage/logs/.gitignore`
- `backend/tests/Feature/ExampleTest.php`
- `backend/tests/TestCase.php`
- `backend/tests/Unit/ExampleTest.php`
- `compose.yaml`
- `docker/frontend/Dockerfile`
- `docker/php/Dockerfile`
- `docker/php/entrypoint.sh`
- `docs/ai-request.txt`
- `docs/ai-session.md`
- `docs/environment.png`
- `frontend/.env.example`
- `frontend/.gitignore`
- `frontend/.oxlintrc.json`
- `frontend/README.md`
- `frontend/index.html`
- `frontend/package-lock.json`
- `frontend/package.json`
- `frontend/public/favicon.svg`
- `frontend/public/icons.svg`
- `frontend/src/App.module.css`
- `frontend/src/App.tsx`
- `frontend/src/assets/hero.png`
- `frontend/src/assets/vite.svg`
- `frontend/src/index.css`
- `frontend/src/main.tsx`
- `frontend/tsconfig.app.json`
- `frontend/tsconfig.json`
- `frontend/tsconfig.node.json`
- `frontend/vite.config.ts`
- `docs/setup-report.md`
