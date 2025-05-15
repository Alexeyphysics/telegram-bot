# PIUS Telegram Bot

**Your Personal Nutrition and Workout Assistant** 🤖

---

## 🚀 Overview

**PIUS Telegram Bot** is your ultimate companion for tracking nutrition and workouts. Seamlessly integrated with external APIs, it helps you manage your diet, log exercises, and monitor progress with ease.

### ✨ Key Features

- **Account Management**  
  - Create and switch between multiple accounts effortlessly.
  
- **Nutrition Diary**  
  - Log meals manually or from your saved product database.  
  - View daily diet with detailed macros (calories, proteins, fats, carbs).  
  - Delete diary entries as needed.

- **Product Database**  
  - Save custom products with their nutritional info (macros auto-calculated).  
  - Search, view, or delete saved products.

- **Workout Tracking**  
  - Record workouts with exercises, weights, and reps.  
  - Access exercise technique guides via external resources.  
  - Track progress for specific exercises.  
  - Identify lagging muscle groups.

---

## 🛠️ Tech Stack

- **PHP** (≥ 8.1)  
- **Laravel Framework** (for CLI app structure, configuration, and logging)  
- **Telegram Bot SDK**: `irazasyed/telegram-bot-sdk`  
- **HTTP Client**: `guzzlehttp/guzzle`  
- **Dependency Management**: Composer  

---

## ⚙️ Installation and Setup

### 📋 Prerequisites

- **PHP** ≥ 8.1 with extensions: `mbstring`, `curl`, `json`, `xml`, etc.  
- **Composer** for dependency management  
- Access to external APIs:  
  - **Nutrition Service** (for diet data)  
  - **Workout Assistant** (for workout data)  

### 🛠️ Installation Steps

1. **Clone the Repository**  
   ```bash
   git clone https://your-repository-url/pius-telegram-bot.git
   cd pius-telegram-bot
   ```

2. **Install Dependencies**  
   ```bash
   composer install
   ```

3. **Set Up Environment Variables**  
   - Copy the example `.env` file:  
     ```bash
     cp .env.example .env
     ```
   - Edit `.env` with the following:  
     ```dotenv
     APP_NAME="PIUS Telegram Bot"
     APP_ENV=local  # or production
     APP_KEY=       # Generate with `php artisan key:generate`
     APP_DEBUG=true # or false for production
     APP_URL=http://localhost

     LOG_CHANNEL=stack
     LOG_DEPRECATIONS_CHANNEL=null
     LOG_LEVEL=debug  # or info/error for production

     # Telegram Bot Token (get from @BotFather)
     TELEGRAM_BOT_TOKEN="YOUR_TELEGRAM_BOT_TOKEN"

     # External API Base URIs
     NUTRITION_SERVICE_BASE_URI="YOUR_NUTRITION_SERVICE_ADDRESS"  # e.g., http://localhost:8080
     WORKOUT_SERVICE_BASE_URI="YOUR_WORKOUT_SERVICE_ADDRESS"      # e.g., http://localhost:8001

     # Data Storage (for bot_users.json; others deprecated)
     USER_DATA_PATH="storage/bot/bot_users.json"
     # PRODUCT_DATA_PATH="storage/bot/bot_products.json"  # Deprecated
     # DIARY_DATA_PATH="storage/bot/bot_diary.json"       # Deprecated
     # TRAINING_LOG_DATA_PATH="storage/bot/bot_trainings.json"  # Deprecated
     ```

4. **Generate Application Key**  
   ```bash
   php artisan key:generate
   ```

5. **Create Storage Directories**  
   Ensure `storage/bot/` and `storage/logs/` exist and are writable:  
   ```bash
   mkdir -p storage/bot
   mkdir -p storage/logs
   chmod -R 775 storage bootstrap/cache
   ```

### 🚀 Running the Bot

- **Start the Bot (Long Polling)**  
  ```bash
  php artisan bot:run
  ```

- **Run in Background (Production)**  
  Use a process manager like **Supervisor**. Example configuration:  
  ```ini
  [program:pius-telegram-bot]
  process_name=%(program_name)s_%(process_num)02d
  command=php /path/to/pius-telegram-bot/artisan bot:run
  autostart=true
  autorestart=true
  stopasgroup=true
  killasgroup=true
  user=server_user  # e.g., www-data
  numprocs=1
  redirect_stderr=true
  stdout_logfile=/path/to/pius-telegram-bot/storage/logs/supervisor_bot.log
  stopwaitsecs=300
  ```

---

## 📁 Project Structure

Key directories and files:

- **`app/Bot/`**: Core bot logic  
  - `BotKernel.php`: Handles updates and user states  
  - `Constants/States.php`: User state constants  
  - `Keyboard/KeyboardService.php`: Telegram keyboard generation  
  - `Service/DataStorageService.php`: Manages data storage (e.g., `bot_users.json`)  

- **`app/Console/Commands/RunTelegramBot.php`**: Artisan command to run the bot  
- **`config/`**: Laravel configuration files  
  - `app.php`: General app settings  
  - `telegram.php`: Telegram Bot SDK config  
  - `exercises.php`: Local exercise catalog (temporary, pending API)  

- **`storage/bot/`**: Stores JSON data (e.g., `bot_users.json`)  
- **`storage/logs/`**: Laravel and bot logs  
- **`.env`**: Environment variables  

---

## 🤝 Interacting with the Bot

1. Find the bot in Telegram by its username.  
2. Send `/start` to begin.  
3. Follow prompts to register your first account.  
4. Use the menu buttons to navigate features.  

---

## 🌐 External APIs

The bot integrates with:  

- **Nutrition Service**  
  - URL: `NUTRITION_SERVICE_BASE_URI`  
  - Manages product macros and nutrition diary.  

- **Workout Assistant**  
  - URL: `WORKOUT_SERVICE_BASE_URI`  
  - Handles workout data, exercise progress, and technique guides.  

Authentication uses **Bearer tokens**, stored per user account upon registration.

---

## 📝 Future Plans (TODO)

- Fetch exercise catalog from Workout Assistant API.  
- Migrate `bot_users.json` to a Laravel database.  
- Implement pagination for long lists (products, diary entries).  
- Enhance UX and error handling.  
- *Add your ideas here!*  

---

## ❓ Support

Encounter issues or have questions?  
📌 Create an **Issue** in the repository.  

---

**Happy Tracking with PIUS!** 💪