<?php

require __DIR__ . '/vendor/autoload.php';

use Telegram\Bot\Api;
use Dotenv\Dotenv;
use Telegram\Bot\Keyboard\Keyboard;
// Убрали use Telegram\Bot\Keyboard\ReplyKeyboardRemove;

// --- Константы состояний пользователя ---
// ... (остаются без изменений) ...
define('STATE_DEFAULT', 0);
define('STATE_AWAITING_NAME', 1);
define('STATE_AWAITING_EMAIL', 2);
define('STATE_AWAITING_PASSWORD', 3);

// Загружаем .env файл
// ... (остается без изменений) ...
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$telegram = new Api(env('TELEGRAM_BOT_TOKEN', ''));
$updateId = 0;

// --- Временное хранилище данных и состояний ---
// ... (остаются без изменений) ...
$userStates = [];
$userData = [];

// --- Клавиатуры ---
// ... (определения $keyboard, $keyboardTrain, $keyboardNutrition, $keyboardAccount остаются без изменений) ...
$keyboard = Keyboard::make()
    ->row([ Keyboard::button(['text' => 'Тренировки']), Keyboard::button(['text' => 'Питание']), ])
    ->row([ Keyboard::button(['text' => 'Аккаунт']), ])
    ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
$keyboardTrain = Keyboard::make()
    ->row([ Keyboard::button(['text' => 'Записать тренировку']), ])
    ->row([ Keyboard::button(['text' => 'Начать тренировку']), ])
    ->row([ Keyboard::button(['text' => 'Вывести последние три тренировки']), ])
    ->row([ Keyboard::button(['text' => 'Назад']), ])
    ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
$keyboardNutrition = Keyboard::make()
    ->row([ Keyboard::button(['text' => 'Записать прием пищи']), ])
    ->row([ Keyboard::button(['text' => 'Посчитать калории за сегодня']), ])
    ->row([ Keyboard::button(['text' => 'Назад']), ])
    ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
$keyboardAccount = Keyboard::make()
    ->row([ Keyboard::button(['text' => 'Вывести имя и почту']), ])
    ->row([ Keyboard::button(['text' => 'Сменить аккаунт']), ])
    ->row([ Keyboard::button(['text' => 'Назад']), ])
    ->setResizeKeyboard(true)->setOneTimeKeyboard(false);


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

            $currentState = $userStates[$chatId] ?? STATE_DEFAULT;

            // --- Обработка состояний регистрации ---
            if ($currentState !== STATE_DEFAULT) {
                if ($currentState === STATE_AWAITING_NAME) {
                    $userData[$chatId]['name'] = $text;
                    $userStates[$chatId] = STATE_AWAITING_EMAIL;
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Отлично! Теперь введите вашу почту:']);
                } elseif ($currentState === STATE_AWAITING_EMAIL) {
                    $userData[$chatId]['email'] = $text;
                    $userStates[$chatId] = STATE_AWAITING_PASSWORD;
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Почти готово! Введите ваш пароль:']);
                } elseif ($currentState === STATE_AWAITING_PASSWORD) {
                    $userData[$chatId]['password'] = $text;
                    $userStates[$chatId] = STATE_DEFAULT;
                    $name = $userData[$chatId]['name'] ?? 'Пользователь';
                    echo "Сохранены данные для $chatId: "; print_r($userData[$chatId]); echo "\n"; // Отладка
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Готово, {$name}! Вы успешно зарегистрированы/вошли.",
                        'reply_markup' => $keyboard
                    ]);
                }
                continue;
            }

            // --- Обработка команд и кнопок (только если состояние STATE_DEFAULT) ---
            switch ($text) {
                case '/start':
                    if (isset($userData[$chatId])) {
                        $name = $userData[$chatId]['name'] ?? 'Пользователь';
                        $telegram->sendMessage(['chat_id' => $chatId, 'text' => "С возвращением, {$name}! Выберите действие:", 'reply_markup' => $keyboard]);
                    } else {
                        $userStates[$chatId] = STATE_AWAITING_NAME;
                        $telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Добро пожаловать! Для начала работы, пожалуйста, введите ваше имя:",
                            'reply_markup' => Keyboard::remove() // <-- ИСПРАВЛЕНО
                        ]);
                    }
                    break;

                case 'Аккаунт':
                    if (!isset($userData[$chatId])) {
                        $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, сначала войдите в аккаунт с помощью команды /start.']);
                        break;
                    }
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Выберите действие с аккаунтом:', 'reply_markup' => $keyboardAccount]);
                    break;

                case 'Вывести имя и почту':
                     if (!isset($userData[$chatId])) {
                        $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Данные не найдены. Пожалуйста, войдите в аккаунт с помощью команды /start.']);
                        break;
                    }
                    $name = $userData[$chatId]['name'] ?? 'Не указано';
                    $email = $userData[$chatId]['email'] ?? 'Не указана';
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Ваши данные:\nИмя: {$name}\nПочта: {$email}", 'reply_markup' => $keyboardAccount]);
                    break;

                case 'Сменить аккаунт':
                    $userStates[$chatId] = STATE_AWAITING_NAME;
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Введите ваше имя для регистрации или смены данных:",
                        'reply_markup' => Keyboard::remove() // <-- ИСПРАВЛЕНО
                    ]);
                    break;

                 // ... (остальные case для кнопок Тренировки, Питание, Назад остаются без изменений) ...
                 case 'Тренировки':
                    if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, сначала войдите в аккаунт.']); break; }
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Выберите действие с тренировками:', 'reply_markup' => $keyboardTrain]);
                    break;
                case 'Питание':
                    if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, сначала войдите в аккаунт.']); break; }
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Выберите действие по питанию:', 'reply_markup' => $keyboardNutrition]);
                    break;
                case 'Записать тренировку': $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Вы выбрали 'Записать тренировку'."]); break;
                case 'Начать тренировку': $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Вы выбрали 'Начать тренировку'."]); break;
                case 'Вывести последние три тренировки': $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Вы выбрали 'Вывести последние три тренировки'."]); break;
                case 'Записать прием пищи': $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Вы выбрали 'Записать прием пищи'."]); break;
                case 'Посчитать калории за сегодня': $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Вы выбрали 'Посчитать калории за сегодня'."]); break;
                case 'Назад':
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Вы вернулись в основное меню.', 'reply_markup' => $keyboard]);
                    break;

            } // Конец switch ($text)
        } // Конец if ($update->isType('message'))
    } // Конец foreach ($updates as $update)
    sleep(1);
} // Конец while (true)