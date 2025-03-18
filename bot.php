<?php

require __DIR__ . '/vendor/autoload.php';

use Telegram\Bot\Api;
use Dotenv\Dotenv;
use Telegram\Bot\Keyboard\Keyboard;

// Загружаем .env файл
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$telegram = new Api(env('TELEGRAM_BOT_TOKEN', ''));
$updateId = 0;

// Создаем основную ReplyKeyboardMarkup с кнопками "Тренировки" и "Питание"
$keyboard = Keyboard::make()
    ->row([
        Keyboard::button(['text' => 'Тренировки']),
        Keyboard::button(['text' => 'Питание'])
    ])
    ->setResizeKeyboard(true)
    ->setOneTimeKeyboard(false);

// Клавиатура для раздела "Тренировки"
$keyboardTrain = Keyboard::make()
    ->row([
        Keyboard::button(['text' => 'Записать тренировку']),
    ])
    ->row([
        Keyboard::button(['text' => 'Начать тренировку']),
    ])
    ->row([
        Keyboard::button(['text' => 'Вывести последние три тренировки']),
    ])
    ->row([ // Кнопка "Назад" для раздела "Тренировки"
        Keyboard::button(['text' => 'Назад']),
    ])
    ->setResizeKeyboard(true)
    ->setOneTimeKeyboard(false);

// Клавиатура для раздела "Питание"
$keyboardNutrition = Keyboard::make()
    ->row([
        Keyboard::button(['text' => 'Записать прием пищи']),
    ])
    ->row([
        Keyboard::button(['text' => 'Посчитать калории за сегодня']),
    ])
    ->row([ // Кнопка "Назад" для раздела "Питание"
        Keyboard::button(['text' => 'Назад в Питание']),
    ])
    ->setResizeKeyboard(true)
    ->setOneTimeKeyboard(false);


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

            if ($text == '/start') {
                $responseText = "Привет! Выбери действие:";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText,
                    'reply_markup' => $keyboard
                ]);
            } elseif ($text == 'Тренировки') {
                $responseText = "Выберите действие с тренировками:";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText,
                    'reply_markup' => $keyboardTrain // Отправляем клавиатуру для тренировок
                ]);
            } elseif ($text == 'Питание') {
                $responseText = "Выберите действие по питанию:";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText,
                    'reply_markup' => $keyboardNutrition // Отправляем клавиатуру для питания
                ]);
            } elseif ($text == 'Записать тренировку') {
                $responseText = "Вы выбрали 'Записать тренировку'.";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText
                ]);
            } elseif ($text == 'Начать тренировку') {
                $responseText = "Вы выбрали 'Начать тренировку'.";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText
                ]);
            } elseif ($text == 'Вывести последние три тренировки') {
                $responseText = "Вы выбрали 'Вывести последние три тренировки'.";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText
                ]);
            } elseif ($text == 'Назад') { // Обработка кнопки "Назад" из "Тренировки"
                $responseText = "Вы вернулись в основное меню. Выберите действие:";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText,
                    'reply_markup' => $keyboard // Отправляем обратно основную клавиатуру
                ]);
            } elseif ($text == 'Записать прием пищи') {
                $responseText = "Вы выбрали 'Записать прием пищи'.";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText
                ]);
            } elseif ($text == 'Посчитать калории за сегодня') {
                $responseText = "Вы выбрали 'Посчитать калории за сегодня'.";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText
                ]);
            } elseif ($text == 'Назад в Питание') { // Обработка кнопки "Назад в Питание"
                $responseText = "Вы вернулись в основное меню. Выберите действие:";
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $responseText,
                    'reply_markup' => $keyboard // Отправляем обратно основную клавиатуру
                ]);
            }
            else {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Привет! Ты написал: ' . $text
                ]);
            }
        }
    }
    sleep(1);
}