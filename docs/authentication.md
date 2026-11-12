# Авторизация Laravel Sanctum Bearer

## Механизм

Используются существующий Laravel Sanctum и personal access tokens сроком 7 дней. При register/login API возвращает `{data: user, token, token_type: "Bearer"}`. Пароли по-прежнему обрабатываются Laravel hashed cast/Hash; профиль не включает пароль или хеш. Plaintext токен возвращается только при выдаче, Sanctum хранит его хеш в существующей personal_access_tokens.

`auth:sanctum` проверяет Authorization: Bearer, `sanctum.guard=[]` исключает web sessions. HasApiTokens подключён к User, прежний callback, запрещавший token retrieval, удалён. EnsureUserIsActive продолжает проверять blocked при каждом защищённом запросе, включая ранее выданные токены. Logout удаляет только currentAccessToken. Другие выданные токены остаются действительными до отзыва/истечения.

API больше не использует statefulApi(), sessions, CSRF cookies и XSRF-заголовки. Это не отключает web CSRF: стандартная web middleware сохранена и отдельно проверена тестом. Существующий пакетный `/sanctum/csrf-cookie` остаётся стандартным web endpoint Sanctum, но приложение его не вызывает и не использует для API-авторизации.

## Frontend и хранение

AuthProvider хранит только токен в **sessionStorage**, ключ `eneba:auth-token`. Профиль загружается через `/api/me`. При reload, фокусе и перед переходом на защищённые страницы сохранённый токен проверяется сервером. 401 удаляет его и сбрасывает авторизацию; blocked 403 также сбрасывает локальное состояние. Сетевые ошибки позволяют повторить проверку без удаления токена. Logout отзывает токен на сервере, затем удаляет локальную запись; при сетевой ошибке не сообщает об успешном выходе.

Никакие токены не записываются в localStorage. Оставшийся старый ключ из localStorage удаляется без чтения; корзина и состояние каталога не затрагиваются. BroadcastChannel и синхронизация авторизации через cookies удалены: у независимых вкладок отдельный sessionStorage и отдельные токены. Новая вкладка без opener не наследует авторизацию. Если браузер скопировал sessionStorage из opener, копии одного токена будут отозваны вместе; при следующем /me или фокусе другая вкладка получит 401.

sessionStorage сохраняется при reload и восстановлении процесса существующей вкладки в той же браузерной сессии. Это не постоянный вход: новая вкладка/полное завершение сессии не гарантируют сохранения. Восстановление закрытой вкладки зависит от штатного session restore браузера; дополнительные копии токена ради восстановления не создаются.

Все fetch-запросы API используют `credentials: 'omit'`. Токен передаётся только в Authorization header для /me и /logout, не в URL, cookies или логах. Перед register/login нет CSRF bootstrap. Production-клиент до отправки запроса проверяет HTTPS API URL; HTTP разрешён только в development. Формы, Header, checkout/orders, redirect после входа, ошибки, загрузка и CSS не менялись.

## API

| Метод | URL | Результат |
| --- | --- | --- |
| POST | /api/register | first_name/last_name/email/password; 201 {data: user, token, token_type: Bearer} |
| POST | /api/login | email/password; 200 с тем же форматом |
| GET | /api/me | Authorization Bearer; 200 {data: user} |
| POST | /api/logout | Authorization Bearer; 200 {message: Logged out.} |

user: id, first_name, last_name, email, status, created_at, updated_at. Валидация, нормализация email, rate limits и бизнес-правила сохранены. Validation/credentials: 422; нет/отозван/истёк токен: 401; blocked: 403; rate limit: 429. /checkout и /orders остаются защищёнными заглушками; оформление и история заказов не реализовывались.

## CORS и production

Точные origins: localhost frontend и существующий FRONTEND_URL. Wildcard/patterns не используются. Разрешён Authorization; X-XSRF-TOKEN удалён из allowlist, supports_credentials=false. CORS обрабатывает api/* и существующий up.

Bearer-схема работает с независимыми доменами Vercel/Render: общий домен, reverse proxy и third-party cookies не нужны. Production frontend должен использовать HTTPS VITE_API_BASE_URL, backend — существующий HTTPS URL Render. Значения production env, Dockerfile, DNS, proxy и инфраструктура в этой задаче не менялись. Настройки web session cookies сохранены, но API их не использует. Доступность реального production не проверялась и deployment не выполнялся.

## Проверки

```sh
docker compose exec -T backend php artisan test
docker compose exec -T frontend node --test tests/*.test.mjs
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run build
```

- Backend: **99 passed**, 1053 assertions, SQLite :memory:. Регистрация, hash/public response, срок ровно 7 дней с точностью хранения timestamps, login/me, validation, blocked old token, current-token-only logout, invalid/revoked/expired token, отказ web session, сохранение реального web CSRF и отсутствие API Set-Cookie.
- Frontend: **128 passed**. Один POST register/login без CSRF, Authorization header для /me/logout, credentials omit, ошибки, malformed responses, HTTPS guard production с отказом до fetch при HTTP.
- TypeScript app/node, Oxlint (0 warnings/errors), Pint и production build прошли. Сборка выполнена во временной копии без публикации.
- Chromium на http://localhost:5173 с настоящим API/PostgreSQL localhost:8000: register, redirect в orders, reload, /me, sessionStorage-only credential, независимые токены вкладок, logout и replay отозванного токена401, реальное истечение через изменение expires_at временного тестового токена, автоматическая очистка sessionStorage при401, wrong password, blocked token403 и blocked login, отсутствие CSRF запросов/заголовков и cookies, отсутствие токена в localStorage, ширины 375/768/1440, отсутствие необработанных JS-ошибок.
- Перезапуск процесса существующей вкладки проверен через CDP Page.crash/Page.reload: sessionStorage сохранился, реальный интерфейс Checkout восстановился после /me. После намеренного crash Playwright помечает Page как crashed; успешное восстановление интерфейса проверено непосредственно CDP Runtime.evaluate.
- Временный локальный пользователь и его токены удалены после проверки. Neon не использовался.

## Изменённые файлы этого перехода

Backend:
- app/Http/Controllers/AuthController.php
- app/Models/User.php
- app/Providers/AppServiceProvider.php
- bootstrap/app.php
- config/sanctum.php
- config/cors.php
- tests/Feature/AuthTest.php
- tests/Feature/CorsTest.php

Frontend:
- src/api/auth.ts
- src/api/games.ts
- src/auth/AuthProvider.tsx
- src/auth/context.ts
- tests/auth.test.mjs

Документация: docs/authentication.md.

Существующие изменения сохранены. Все миграции сверены по контрольным суммам и не менялись; новых/удалённых миграций нет. Структура users и бизнес-логика не менялись. Зависимости не добавлялись; commit/push/deploy не выполнялись. Функциональных блокеров по локальным проверкам нет.
