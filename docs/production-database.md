# Обновление production-базы Neon

Все изменения схемы — только Laravel migrations. Сначала примените и проверьте их в локальной PostgreSQL Docker Compose. Production не обновляется при разработке, сборке, запуске приложения или деплое. Каждая операция Neon запускается вручную и требует отдельного явного подтверждения пользователя.

## 1. Проверить локально

Требуется Docker Desktop и команда `docker`, доступная в используемой PowerShell-сессии (`docker version`). Из корня проекта в PowerShell:

```powershell
docker compose exec backend php artisan migrate
# Тесты используют SQLite in-memory, а не рабочую PostgreSQL.
docker compose exec -T -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DATABASE_URL= -e DB_URL= -e CACHE_STORE=array -e SESSION_DRIVER=array -e LOG_CHANNEL=null backend php vendor/bin/phpunit --fail-on-warning --do-not-cache-result
```

Не запускайте тесты с RefreshDatabase на рабочей или production-базе. Для PostgreSQL-специфичной логики дополнительно используйте отдельную локальную тестовую PostgreSQL. Просмотрите `up()` новых миграций и выбранный сидер; перед production-изменением убедитесь, что доступна подходящая резервная копия/точка восстановления. Скрипт ограничивает команды, но не может доказать безопасность произвольного PHP-кода миграции или сидера.

## 2. Подготовить приватный env-файл

Самостоятельно заполните `backend/.env.production.local`. Он уже исключён из Git и Docker build context; не используйте `git add -f`. Достаточная основа:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:REPLACE_WITH_EXISTING_PRODUCTION_KEY
DB_CONNECTION=pgsql
DATABASE_URL=postgresql://USER:URL_ENCODED_PASSWORD@DIRECT_HOST.neon.tech/DATABASE?sslmode=require&channel_binding=require
```

Используйте существующий production APP_KEY и **direct connection URL** Neon (без `-pooler`). Ключи — uppercase `KEY=value`, без `export`, пробелов вокруг `=` и кавычек вокруг значения. Пароль внутри URL должен быть URL-encoded. Удалите из этого файла отдельные DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD и DB_URL: production-подключение задаётся только через DATABASE_URL. Остальные нужные приложению переменные можно сохранить.

Скрипт проверяет Neon-host, обязательный TLS и channel binding, не выводя значения. Настройки Render/Neon он не меняет; env-файл не копируется и не пересоздаётся.

## 3. Миграции

```powershell
# Только проверка конфигурации и план: Docker и БД не запускаются.
.\scripts\production-db.ps1 -Action Migrate -DryRun
# Реальная операция — только по вашему решению после локальной проверки.
.\scripts\production-db.ps1 -Action Migrate
```

Для запуска нужно вручную ввести **MIGRATE NEON**. Любой другой ответ отменяет операцию ещё до Docker build. После подтверждения скрипт собирает актуальный `backend/Dockerfile.prod` и выполняет `php artisan migrate --force --no-interaction` в одноразовом non-root контейнере. PHP/Composer в Windows, SSH и платные jobs Render не нужны.

## 4. Сидеры — отдельная операция

```powershell
.\scripts\production-db.ps1 -Action Seed -Seeder GameSeeder -DryRun
.\scripts\production-db.ps1 -Action Seed -Seeder GameSeeder
```

Нужно отдельно ввести **SEED GameSeeder NEON**. Выполнится только `db:seed --class=GameSeeder --force --no-interaction`; миграции автоматически не запускаются. Текущий GameSeeder идемпотентен и не обновляет существующие игры. Для другого сидера явно укажите его класс и сначала проверьте его локально; общий DatabaseSeeder без необходимости не запускайте.

У скрипта нет параметра автоматического подтверждения, произвольной Artisan-команды или destructive migration action. Не передавайте фразу подтверждения через pipeline. Raw-вывод Docker/Artisan скрыт даже при ошибке, чтобы исключить утечку credentials; показываются этапы и код ошибки. После успеха проверьте приложение и ожидаемое поведение API. После ошибки не считайте всю операцию атомарной: исправьте причину и проверьте состояние перед отдельным повторным запуском с новым подтверждением.

## 5. Проверка скрипта без Neon

```powershell
.\scripts\tests\production-db.tests.ps1
```

Это offline-тесты Windows PowerShell 5.1+: Docker и Read-Host подменены, env-файл содержит только фиктивные значения. Проверяются DryRun, отмена, подтверждения, точные команды, защита секретов, ошибки Docker и некорректная конфигурация. Реальный env-файл не читается, Docker и Neon не вызываются. Если Windows блокирует локальные `.ps1`, можно запустить только этот процесс через `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\tests\production-db.tests.ps1`; глобальную execution policy менять не нужно.

Для проверки собственного плана без credentials используйте `-EnvFile` с отдельным фиктивным файлом и `-DryRun`. Без DryRun другой файл допустим только для осознанной подтверждённой production-операции; проверки разрешают лишь direct Neon host с TLS/channel binding. Не подставляйте реальные production credentials в тесты.

## Что проверено при подготовке

- Windows PowerShell 5.1: offline-тесты подтверждений, DryRun, точных аргументов, ошибок и отсутствия секретов в выводе прошли.
- Рабочая локальная PostgreSQL: `migrate` сообщил Nothing to migrate. Безопасная тестовая команда SQLite in-memory: 13 tests, 218 assertions, успешно.
- Актуальный production-образ собран и проверен на отдельной временной PostgreSQL 17 с TLS/SCRAM: 4 миграции применены, GameSeeder создал 40 игр; повторные migrate/seed не добавили миграций или игр.
- Neon и реальный production env-файл в проверках не использовались. Проверка PowerShell-Docker выполнена через подмену CLI: в доступной PowerShell-сессии Windows docker.exe не найден. Реальные Artisan-команды production-образа проверены отдельно через доступный Linux Docker CLI; полный запуск скрипта с нативным Windows Docker CLI и реальным Neon намеренно не выполнялся.
