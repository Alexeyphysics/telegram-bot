# PIUS Telegram Bot

**Ваш персональный помощник по питанию и тренировкам** 🤖

---

## 🚀 О проекте

**PIUS Telegram Bot** — это ваш личный ассистент для отслеживания питания и тренировок. Бот интегрируется с внешними сервисами, чтобы предоставлять актуальную информацию и сохранять ваши данные.

### ✨ Основные возможности

- **Управление аккаунтами**  
  - Создание нескольких аккаунтов и переключение между ними.  

- **Дневник питания**  
  - Запись приемов пищи (вручную или из сохраненной базы продуктов).  
  - Просмотр рациона за выбранную дату с подсчетом БЖУК (белки, жиры, углеводы, калории).  
  - Удаление записей из дневника.  

- **База продуктов (БЖУ)**  
  - Сохранение собственных продуктов с их пищевой ценностью (калории рассчитываются автоматически).  
  - Просмотр, поиск и удаление сохраненных продуктов.  

- **Тренировки**  
  - Запись тренировок с указанием упражнений, веса и повторений.  
  - Просмотр техники выполнения упражнений через внешние гайды.  
  - Отслеживание прогресса по конкретным упражнениям.  
  - Определение отстающих групп мышц.  

---

## 🛠️ Технологический стек

- **PHP** (версия ≥ 8.1)  
- **Laravel Framework** (для структуры CLI-приложения, конфигурации и логирования)  
- **Telegram Bot SDK**: `irazasyed/telegram-bot-sdk`  
- **HTTP-клиент**: `guzzlehttp/guzzle`  
- **Управление зависимостями**: Composer  

---

## ⚙️ Установка и запуск

### 📋 Требования

- **PHP** ≥ 8.1 с расширениями: `mbstring`, `curl`, `json`, `xml` и др.  
- **Composer** для управления зависимостями  
- Доступ к внешним API-сервисам:  
  - **Nutrition Service** (сервис питания)  
  - **Workout Assistant** (сервис тренировок)  

### 🛠️ Шаги установки

1. **Клонировать репозиторий**  
   ```bash
   git clone https://your-repository-url/pius-telegram-bot.git
   cd pius-telegram-bot
   ```

2. **Установить зависимости**  
   ```bash
   composer install
   ```

3. **Настроить переменные окружения**  
   - Скопировать файл `.env.example` в `.env`:  
     ```bash
     cp .env.example .env
     ```
   - Отредактировать `.env`, указав следующие значения:  
     ```dotenv
     APP_NAME="PIUS Telegram Bot"
     APP_ENV=local  # или production
     APP_KEY=       # Сгенерировать с помощью `php artisan key:generate`
     APP_DEBUG=true # или false для production
     APP_URL=http://localhost

     LOG_CHANNEL=stack
     LOG_DEPRECATIONS_CHANNEL=null
     LOG_LEVEL=debug  # или info/error для production

     # Токен Telegram-бота (получить у @BotFather)
     TELEGRAM_BOT_TOKEN="ВАШ_ТЕЛЕГРАМ_БОТ_ТОКЕН"

     # Базовые URI внешних API-сервисов
     NUTRITION_SERVICE_BASE_URI="АДРЕС_ВАШЕГО_NUTRITION_SERVICE"  # например, http://localhost:8080
     WORKOUT_SERVICE_BASE_URI="АДРЕС_ВАШЕГО_WORKOUT_SERVICE"      # например, http://localhost:8001

     # Настройки хранилища данных (для bot_users.json; остальные файлы устарели)
     USER_DATA_PATH="storage/bot/bot_users.json"
     # PRODUCT_DATA_PATH="storage/bot/bot_products.json"  # Устарело
     # DIARY_DATA_PATH="storage/bot/bot_diary.json"       # Устарело
     # TRAINING_LOG_DATA_PATH="storage/bot/bot_trainings.json"  # Устарело
     ```

4. **Сгенерировать ключ приложения**  
   ```bash
   php artisan key:generate
   ```

5. **Создать необходимые директории**  
   Убедитесь, что директории `storage/bot/` и `storage/logs/` существуют и доступны для записи:  
   ```bash
   mkdir -p storage/bot
   mkdir -p storage/logs
   chmod -R 775 storage bootstrap/cache
   ```

### 🚀 Запуск бота

- **Запуск с Long Polling**  
  ```bash
  php artisan bot:run
  ```

- **Запуск в фоновом режиме (для сервера)**  
  Рекомендуется использовать менеджер процессов, например **Supervisor**. Пример конфигурации:  
  ```ini
  [program:pius-telegram-bot]
  process_name=%(program_name)s_%(process_num)02d
  command=php /путь/к/проекту/pius-telegram-bot/artisan bot:run
  autostart=true
  autorestart=true
  stopasgroup=true
  killasgroup=true
  user=имя_пользователя_сервера  # например, www-data
  numprocs=1
  redirect_stderr=true
  stdout_logfile=/путь/к/проекту/pius-telegram-bot/storage/logs/supervisor_bot.log
  stopwaitsecs=300
  ```

---

## 📁 Структура проекта

Ключевые директории и файлы:

- **`app/Bot/`**: Основная логика бота  
  - `BotKernel.php`: Обработка обновлений и состояний пользователей  
  - `Constants/States.php`: Константы состояний  
  - `Keyboard/KeyboardService.php`: Генерация Telegram-клавиатур  
  - `Service/DataStorageService.php`: Работа с хранилищем данных (в основном для `bot_users.json`)  

- **`app/Console/Commands/RunTelegramBot.php`**: Artisan-команда для запуска бота  
- **`config/`**: Конфигурационные файлы Laravel  
  - `app.php`: Общие настройки приложения  
  - `telegram.php`: Настройки Telegram Bot SDK  
  - `exercises.php`: Локальный каталог упражнений (временный, до интеграции с API)  

- **`storage/bot/`**: Хранилище JSON-файлов (например, `bot_users.json`)  
- **`storage/logs/`**: Логи Laravel и бота  
- **`.env`**: Файл переменных окружения  

---

## 🤝 Как пользоваться ботом

1. Найдите бота в Telegram по его имени пользователя.  
2. Отправьте команду `/start`.  
3. Следуйте инструкциям для регистрации первого аккаунта.  
4. Используйте кнопки меню для доступа к функциям.  

---

## 🌐 Интеграция с внешними API

Бот работает с двумя сервисами:  

- **Nutrition Service**  
  - URL: `NUTRITION_SERVICE_BASE_URI`  
  - Хранит и управляет данными о продуктах (БЖУ) и дневником питания.  

- **Workout Assistant**  
  - URL: `WORKOUT_SERVICE_BASE_URI`  
  - Управляет данными о тренировках, упражнениях, прогрессе и технике выполнения.  

Аутентификация осуществляется через **Bearer-токены**, которые бот получает при регистрации аккаунта и сохраняет для каждого пользователя.

---

## 📝 Планы на будущее (TODO)

- Загрузка каталога упражнений из API Workout Assistant.  
- Перевод хранения данных пользователей (`bot_users.json`) на базу данных Laravel.  
- Реализация пагинации для длинных списков (продукты, дневник).  
- Улучшение интерфейса и обработки ошибок.  
- *Ваши идеи приветствуются!*  

---

## ❓ Вопросы и поддержка

Если возникли проблемы или вопросы:  
📌 Создайте **Issue** в репозитории.  

---