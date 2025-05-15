# PIUS Telegram Bot (Персональный Помощник по Питанию и Тренировкам)

## 🤖 О боте

**PIUS Telegram Bot** — это ваш личный ассистент для отслеживания питания и тренировок. Бот интегрируется с внешними сервисами для предоставления актуальной информации и сохранения ваших данных.

**Основные возможности:**
*   **Управление аккаунтами:** Создание нескольких аккаунтов, переключение между ними.
*   **Дневник Питания:**
    *   Запись приемов пищи (вручную или из вашей базы сохраненных продуктов).
    *   Просмотр рациона за выбранную дату с подсчетом БЖУК.
    *   Удаление записей из дневника.
*   **База БЖУ Продуктов:**
    *   Сохранение собственных продуктов с их БЖУ (калории рассчитываются).
    *   Просмотр, поиск и удаление сохраненных продуктов.
*   **Тренировки:**
    *   Запись тренировок с указанием упражнений, веса и повторений.
    *   Просмотр техники выполнения упражнений (через внешние гайды).
    *   Отслеживание прогресса по конкретным упражнениям.
    *   Получение списка отстающих групп мышц.

## 🛠 Технологический стек

*   **PHP** (версия >= 8.1)
*   **Laravel Framework** (для структуры CLI-приложения, конфигурации, логирования)
*   **Telegram Bot SDK:** `irazasyed/telegram-bot-sdk`
*   **HTTP Client:** `guzzlehttp/guzzle`
*   **Зависимости:** Управляются через Composer

## ⚙️ Установка и запуск

### Требования

*   PHP >= 8.1 с необходимыми расширениями (mbstring, curl, json, xml и т.д.)
*   Composer
*   Доступ к запущенным экземплярам внешних API-сервисов:
    *   Nutrition Service (сервис питания)
    *   Workout Assistant (сервис тренировок)

### Шаги установки

1.  **Клонировать репозиторий:**
    ```bash
    git clone https://your-repository-url/pius-telegram-bot.git
    cd pius-telegram-bot
    ```

2.  **Установить зависимости:**
    ```bash
    composer install
    ```

3.  **Настроить переменные окружения:**
    Скопируйте файл `.env.example` в `.env`:
    ```bash
    cp .env.example .env
    ```
    Откройте файл `.env` и заполните следующие переменные:

    ```dotenv
    APP_NAME="PIUS Telegram Bot"
    APP_ENV=local # или production
    APP_KEY= # Сгенерируйте с помощью php artisan key:generate
    APP_DEBUG=true # или false для production
    APP_URL=http://localhost

    LOG_CHANNEL=stack
    LOG_DEPRECATIONS_CHANNEL=null
    LOG_LEVEL=debug # или info/error для production

    # Telegram Bot Token (получите у @BotFather)
    TELEGRAM_BOT_TOKEN="ВАШ_ТЕЛЕГРАМ_БОТ_ТОКЕН"

    # Базовые URI для внешних API-сервисов
    NUTRITION_SERVICE_BASE_URI="АДРЕС_ВАШЕГО_NUTRITION_SERVICE" # например, http://localhost:8080
    WORKOUT_SERVICE_BASE_URI="АДРЕС_ВАШЕГО_WORKOUT_SERVICE" # например, http://localhost:8001

    # Настройки для DataStorageService (пути к JSON файлам)
    # Эти пути используются для bot_users.json. Файлы для продуктов и дневника больше не используются.
    # Если вы перевели bot_users.json на БД, эти переменные могут быть не нужны.
    USER_DATA_PATH="storage/bot/bot_users.json"
    # PRODUCT_DATA_PATH="storage/bot/bot_products.json" # Больше не используется
    # DIARY_DATA_PATH="storage/bot/bot_diary.json"       # Больше не используется
    # TRAINING_LOG_DATA_PATH="storage/bot/bot_trainings.json" # Больше не используется
    ```

4.  **Сгенерировать ключ приложения Laravel (если еще не сделали):**
    ```bash
    php artisan key:generate
    ```

5.  **Создать необходимые директории (если их нет):**
    Убедитесь, что директория `storage/bot/` существует и доступна для записи (для `bot_users.json` и логов Laravel `storage/logs/`).
    ```bash
    mkdir -p storage/bot
    mkdir -p storage/logs
    chmod -R 775 storage bootstrap/cache # Установите правильные права
    ```

### Запуск бота

Бот запускается как Artisan-команда с использованием Long Polling:
```bash
php artisan bot:run
Для запуска в фоновом режиме на сервере рекомендуется использовать менеджер процессов, такой как Supervisor.
Пример конфигурации Supervisor:
[program:pius-telegram-bot]
process_name=%(program_name)s_%(process_num)02d
command=php /путь/к/вашему/проекту/pius-telegram-bot/artisan bot:run
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=имя_пользователя_сервера # Например, www-data или ваше имя пользователя
numprocs=1
redirect_stderr=true
stdout_logfile=/путь/к/вашему/проекту/pius-telegram-bot/storage/logs/supervisor_bot.log
stopwaitsecs=300

📁 Структура проекта (ключевые директории)
app/Bot/: Основная логика бота.
BotKernel.php: Ядро бота, обработка обновлений и состояний.
Constants/States.php: Константы состояний пользователя.
Keyboard/KeyboardService.php: Генерация Telegram-клавиатур.
Service/DataStorageService.php: Сервис для работы с хранилищем данных (сейчас в основном для bot_users.json).
app/Console/Commands/RunTelegramBot.php: Artisan-команда для запуска бота.
config/: Файлы конфигурации Laravel.
config/app.php: Общие настройки приложения.
config/telegram.php: Конфигурация Telegram Bot SDK.
config/exercises.php: Локальный каталог упражнений (используется, пока нет API для их получения).
routes/: Маршруты (не используются для CLI-бота).
storage/bot/: Место хранения JSON-файлов данных (например, bot_users.json).
storage/logs/: Логи Laravel и бота.
.env: Файл переменных окружения.
🚀 Взаимодействие с ботом
Найдите бота в Telegram по его имени пользователя (username).
Отправьте команду /start.
Следуйте инструкциям для регистрации вашего первого аккаунта.
Используйте кнопки меню для доступа к различным функциям.
🤝 Внешние API
Бот интегрируется со следующими внешними сервисами:
Nutrition Service:
URL: env('NUTRITION_SERVICE_BASE_URI')
Отвечает за хранение и управление данными о продуктах (БЖУ) и дневником питания.
Workout Assistant:
URL: env('WORKOUT_SERVICE_BASE_URI')
Отвечает за хранение и управление данными о тренировках, упражнениях, прогрессе и технике выполнения.
Аутентификация с этими сервисами происходит по Bearer токену, который бот получает при регистрации/добавлении аккаунта и сохраняет для каждого аккаунта пользователя.
📝 Планы на будущее / TODO
Загрузка каталога упражнений из API workout-assistant.
Перевод хранения данных пользователей бота (bot_users.json) на базу данных Laravel.
Реализация пагинации для длинных списков (сохраненные продукты, записи дневника).
Дальнейшее улучшение UX и обработки ошибок.
(Добавьте свои пункты)
❓ Вопросы и проблемы
Если у вас возникли вопросы или проблемы, пожалуйста, создайте Issue в этом репозитории.

