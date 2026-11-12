# Frontend checks

Обычные API/cart tests (существующие зависимости проекта):

```sh
docker compose exec -T frontend node --test tests/*.test.mjs
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run build
```

## AuthProvider: F04 browser regression

`auth-provider.browser.mjs` запускает настоящий React UI в Chromium с управляемыми ответами API. Нужен работающий frontend (по умолчанию `http://localhost:5173`) и уже установленный Playwright с Chromium. Тест не устанавливает зависимости и не меняет package.json/lock-файлы.

Из каталога frontend:

```sh
# Если Playwright доступен обычному Node module resolution:
node --test tests/auth-provider.browser.mjs

# Или указать entrypoint существующей установки инструмента:
PLAYWRIGHT_MODULE_PATH=/absolute/path/to/playwright/index.mjs \
FRONTEND_BASE_URL=http://localhost:5173 \
node --test tests/auth-provider.browser.mjs
```

При использовании Linux Chromium могут потребоваться переменные окружения библиотек уже настроенного browser toolchain. В среде выполнения задачи использовались существующие `/tmp/eneba-browser-check/node_modules/playwright/index.mjs` и библиотеки `/tmp/eneba-browser-check/libs/usr/lib/x86_64-linux-gnu`. Эти пути не являются зависимостями приложения.

Все `/api/*` перехватываются: используется только тестовый opaque token и фиктивный профиль. Backend, рабочая БД и Neon не вызываются. Cookie/Bearer-механизм приложения не подменяется: тест исполняет настоящий AuthProvider, Router, Header и формы; меняются только ответы HTTP. Каждый сценарий получает отдельный browser context. Удерживаемые ответы освобождаются управляемыми promises; проверки обоих порядков ждут завершения ответа, а не произвольной задержки. Для обнаружения автоматического цикла дополнительно проверяется число запросов после settling period.

Покрыты 9 сценариев:

- A: failed logout503, затем успешный старый me; ровно один новый me и корректное завершение загрузки.
- B: успешный logout, затем старый me; пользователь не восстанавливается.
- C: старый me завершается до failed logout; повторная проверка завершается.
- D: failed me + failed logout; доступна ручная Retry account check, токен сохраняется, автоматического цикла нет.
- E: повторный успешный logout во время recovery me; оба старых ответа игнорируются.
- F: переходы protected → public → protected во время failed logout.
- Успешный logout, когда старый me завершился раньше ответа logout.
- Сетевая ошибка logout с одной новой проверкой.
- Обычные register/login/logout, восстановление после reload и redirect на защищённый маршрут после входа.

Исходный тест A добавлен до исправления и воспроизвёл F04: после logout503 и успешного старого me защищённая страница оставалась Checking your account. После исправления все 9 сценариев проходят. Native frontend suite: 128 passed; TypeScript app/node, Oxlint и production build также прошли. Сборка проверялась во временной копии без публикации.

Исправление ограничено failed-logout веткой AuthProvider: прежняя проверка уже инвалидирована, поэтому при неизменившемся токене выполняется один revision для её замены. Ошибка снова выбрасывается для существующего сообщения Header; токен не удаляется и выход не считается успешным. Version/Abort guards и успешный logout сохранены.


## CartProvider: F02/F03 browser regression

Тот же существующий browser toolchain, работающий frontend и env-параметры, что выше:

```sh
PLAYWRIGHT_MODULE_PATH=/absolute/path/to/playwright/index.mjs \
FRONTEND_BASE_URL=http://localhost:5173 \
node --test tests/cart-sync.browser.mjs tests/auth-provider.browser.mjs
```

`cart-sync.browser.mjs` содержит 14 сценариев корзины: две реальные вкладки, запись/события localStorage, свежесть отложенных API-ответов, лимит 20, legacy 21/101, unavailable/503, повтор существующего offer, конфликты и отсутствие циклов. Все backend requests перехватываются; существующие данные и API не меняются. Вместе с 9 AuthProvider cases получается 23 browser tests. Общий native frontend suite после F02/F03 — 131 passed.

Ограничение: localStorage read-modify-write не атомарен между renderer processes. Проверяется сходимость к последнему записанному снимку и лимит, а не сохранение обеих строго одновременных операций. Очерёдные изменения разных offers читают актуальное storage и сохраняются вместе. Подробное правило описано в docs/frontend-cart.md.

## Checkout: обязательная авторизация

Используется существующая цепочка: Cart → `/checkout` → RequireAuth → `/login?redirect=%2Fcheckout`. Пока AuthProvider проверяет пользователя, RequireAuth показывает Checking your account, не перенаправляя заранее. 401 очищает токен и открывает login; временная ошибка me оставляет checkout с Retry account check без автоматических повторов.

Форма передаёт существующий query-параметр redirect между login/register. После успешного входа или регистрации используется прежний `navigate(destination, {replace: true})`; target ограничен прежним allowlist. Дополнительной redirect-системы нет.

Добавлена только необходимая навигация: Cancel and return to cart в checkout-auth flow. Отмена незавершённого login использует существующий AbortController формы: поздний ответ не авторизует пользователя и не переводит обратно на checkout.

CartProvider не перемонтируется при переходах между страницами и не очищается операциями auth. На checkout актуальное количество видно в существующем Header; Back to cart открывает те же предложения/количества. Контент checkout остаётся прежней заглушкой, без новых операций оформления/оплаты.

`checkout-auth.browser.mjs` добавляет 11 тестов: guest/login return, register return и переключение форм, авторизованный checkout с 20 позициями, неверный пароль, ошибка регистрации, expired/me401 и повторный вход, delayed me без преждевременного redirect, me503/network и ручной retry, отмена входа и отмена pending login. В каждом релевантном сценарии проверяется неизменность полного сохранённого массива offerId/quantity, а не только счётчика. Проверка redirect loops учитывает, что React StrictMode может повторно replace тот же URL; чередование checkout/login не скрывается.

```sh
PLAYWRIGHT_MODULE_PATH=/absolute/path/to/playwright/index.mjs \
FRONTEND_BASE_URL=http://localhost:5173 \
node --test tests/*.browser.mjs
```

Проверено на реальном интерфейсе `http://localhost:5173` с контролируемыми API-ответами. Итог: 34 browser tests (11 checkout + 14 cart F02/F03 + 9 auth F04), 131 native frontend tests, TypeScript app/node, Oxlint 0 warnings/errors, production build — PASS. Новые зависимости не устанавливались.

Backend: существующий AuthTest выполнен в Docker на свежей временной копии и изолированной SQLite :memory: — 11 tests / 79 assertions, PASS. В том числе реальный Sanctum endpoint `/api/me` отклоняет guest/invalid/expired token с HTTP401, blocked token с403. Это не frontend mock серверной защиты.

**Граница backend:** серверного checkout endpoint/операций пока нет. Несуществующий `/api/checkout` не выдаётся за проверенный защищённый endpoint. Создавать фиктивную операцию ради теста401 не стали; текущая проверка статуса использует реальный защищённый me. Когда серверные операции checkout появятся, они должны быть внутри существующего auth:sanctum + EnsureUserIsActive middleware group, независимо от React guard.

Изменения этой задачи: `src/pages/Auth/Auth.tsx` (одна ссылка отмены), новый `tests/checkout-auth.browser.mjs` и этот README. Backend/API, F01–F04, auth storage, БД, CSS, зависимости и checkout-заглушка не изменены. Рабочая PostgreSQL/Neon не использовались. Commit/push/deploy не выполнялись.
