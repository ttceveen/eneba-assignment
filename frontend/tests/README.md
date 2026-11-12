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
