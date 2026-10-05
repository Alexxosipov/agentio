# Платформы: стартовые факты

Отправная точка для исследования, не источник истины: перед записью в скилл сверяй каждую деталь с официальной документацией (ссылки) и установленными SDK. Платформы меняют API и правила.

## Содержание
- Telegram Mini App
- Telegram-бот
- VK Mini App
- PWA
- Платёжные и прочие сервисы

## Telegram Mini App
Документация: https://core.telegram.org/bots/webapps, https://docs.telegram-mini-apps.com.

- Веб-приложение, открываемое внутри Telegram (кнопка бота, меню, inline, прямая ссылка `t.me/<bot>/<app>`). Клиент — скрипт `https://telegram.org/js/telegram-web-app.js` (`window.Telegram.WebApp`) или пакет SDK (`@telegram-apps/sdk` и обёртки) — что выбрать, решает архитектор, установка npm-пакета — решение человека.
- **Подлинность**: клиент передаёт серверу `Telegram.WebApp.initData` (строка query). Сервер проверяет подпись: `data_check_string` — все поля кроме `hash`, отсортированные по ключу, `key=value` через `\n`; `secret_key = HMAC_SHA256(key: "WebAppData", data: bot_token)`; `hash` должен совпасть с `hex(HMAC_SHA256(key: secret_key, data: data_check_string))`; сравнение — `hash_equals`; проверить `auth_date` (свежесть, например ≤ 24 ч). `initDataUnsafe` не доверять. Есть и проверка третьей стороной по полю `signature` (Ed25519, публичный ключ Telegram) — по документации, если бот-токена нет на сервере.
- PHP: `hash_hmac('sha256', $botToken, 'WebAppData', true)` → секрет; `hash_hmac('sha256', $dataCheckString, $secret)` → hex.
- Вход пользователя: по `user.id` из проверенных данных → пользователь приложения (сессия или токен Sanctum — решение архитектора); initData передавать заголовком (например `Authorization: tma <initData>`), проверка — middleware.
- Клиент: `ready()`, `expand()`, `themeParams` / CSS-переменные `--tg-theme-*`, `MainButton`, `BackButton`, `HapticFeedback`, `CloudStorage`, `viewportStableHeight`, `safeAreaInset`; закрытие `close()`. Доступно не во всех версиях клиента — проверять `isVersionAtLeast()`.
- Платежи цифровых товаров в Telegram — Telegram Stars (`XTR`, `sendInvoice`/`createInvoiceLink`), правила магазинов — проверь в документации.
- Локальная разработка: HTTPS-URL (туннель) в настройках бота у @BotFather; тестовая среда Telegram.
- Тесты: фабрика подписанной initData с тестовым токеном; подделка подписи и просроченный `auth_date` → 401.

## Telegram-бот
Документация: https://core.telegram.org/bots/api.

- HTTP API `https://api.telegram.org/bot<token>/<method>`, JSON, ответ `{"ok": true, "result": …}` или `{"ok": false, "error_code": …, "description": …, "parameters": {"retry_after": …}}` при HTTP 200/4xx. SDK на PHP есть, но решение проекта — Saloon-коннектор (скилл agentio-saloon): токен — в `resolveBaseUrl()`, `ok=false` → исключение (`hasRequestFailed`).
- Получение обновлений: вебхук (`setWebhook` с `secret_token` → заголовок `X-Telegram-Bot-Api-Secret-Token` в каждом запросе, проверять `hash_equals`; HTTPS) или `getUpdates` (long polling; одно соединение на токен — иначе 409). Обработка в джобе, идемпотентность по `update_id`; быстрый 200 на вебхук.
- Лимиты: сообщение до 4096 символов (подпись к медиа — 1024), примерно 30 сообщений в секунду всем и 1 в секунду в один чат (ориентир — проверь), 429 с `retry_after` → повтор после паузы. Форматирование — `parse_mode` HTML или MarkdownV2 (экранирование!).
- Файлы: `getFile` → `https://api.telegram.org/file/bot<token>/<file_path>` (до 20 МБ для бота).
- Тесты: Saloon `MockClient` по классам запросов; входящий update — JSON-фикстура.

## VK Mini App
Документация: https://dev.vk.com/ru/mini-apps/overview.

- Веб-приложение во ВКонтакте; связь с клиентом — VK Bridge (`@vkontakte/vk-bridge`, `bridge.send('VKWebAppInit')`), интерфейс — VKUI (`@vkontakte/vkui`); установка npm-пакетов — решение человека.
- **Подлинность**: параметры запуска (`vk_user_id`, `vk_app_id`, `vk_ts`, … и `sign`) приходят в URL. Сервер проверяет: параметры с префиксом `vk_`, отсортированные по ключу, как query-строка; `HMAC_SHA256(query, защищённый_ключ_приложения)` → base64 url-safe без `=` должно совпасть с `sign`; проверить свежесть `vk_ts`. Сверь алгоритм с документацией «Параметры запуска».
- Вызовы API VK (`https://api.vk.com/method/<метод>?v=<версия>`) — Saloon-коннектор; токены сервисный/пользователя — по задаче; ошибки в теле `{"error": {…}}` при HTTP 200 → `hasRequestFailed`.
- Тесты: фабрика подписанных параметров запуска, подделка `sign`.

## PWA
Документация: https://web.dev/learn/pwa, https://developer.mozilla.org/docs/Web/Progressive_web_apps.

- Манифест (`manifest.webmanifest`: name, icons 192/512, start_url, display, theme_color), service worker (область действия, стратегия кэша: network-first для HTML/API, cache-first для статики с версионированием), HTTPS.
- Сборка: в проектах на Vite — плагин PWA (npm-зависимость — решение человека) или свой service worker; не кэшировать ответы с персональными данными и CSRF-токены.
- Push: Web Push (VAPID) — на сервере пакет или свой Saloon-клиент; подписки хранить по пользователю, удалять при 404/410.
- Тесты: манифест и маршруты отдаются; ручная проверка — Lighthouse, офлайн-режим в DevTools.

## Платёжные и прочие сервисы
- Сначала: есть ли официальный поддерживаемый PHP SDK (решение человека об установке) — иначе Saloon.
- Обязательно в скилле: суммы в минимальных единицах, ключи идемпотентности, статусы платежа и переходы, вебхуки (подпись, дубли, порядок), сверка, возвраты — по `../../agentio-laravel-architect/references/payments.md`.
