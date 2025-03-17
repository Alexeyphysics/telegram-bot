<?php

require __DIR__ . '/vendor/autoload.php';

use Telegram\Bot\Api;
use Dotenv\Dotenv;

// Загружаем .env файл
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$telegram = new Api(env('TELEGRAM_BOT_TOKEN', ''));
$updateId = 0;

echo "Starting Telegram Bot...\n";

while (true) {
    $updates = $telegram->getUpdates(['offset' => $updateId + 1]);
    foreach ($updates as $update) {
        $updateId = $update->getUpdateId();

        if ($update->isType('message')) {
            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();
            $text = $message->getText();

            echo "Получено сообщение: $text (Chat ID: $chatId)\n";

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Привет! Ты написал: ' . $text
            ]);
        }
    }
    sleep(1);
}
