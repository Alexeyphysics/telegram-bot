<?php

// Точка входа для запуска бота через CLI

// Подключаем автозагрузчик Composer
require __DIR__ . '/vendor/autoload.php';

// Загружаем переменные окружения из .env файла
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Используем наш основной класс бота
use Bot\BotKernel;

// Получаем токен из переменных окружения
$botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? null;
if (empty($botToken)) {
    // Если токен не найден, выводим ошибку и выходим
    echo "Error: TELEGRAM_BOT_TOKEN not set in .env file.\n";
    exit(1); // Завершаем скрипт с кодом ошибки
}

try {
    // Создаем экземпляр ядра бота, передавая токен
    $botKernel = new BotKernel($botToken);
    // Запускаем основной цикл обработки обновлений
    $botKernel->run();

} catch (\Throwable $e) {
    // Отлавливаем любые критические ошибки при инициализации или запуске
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    // Можно добавить логирование в файл здесь
    exit(1); // Завершаем скрипт с кодом ошибки
}