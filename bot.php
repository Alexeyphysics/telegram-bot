<?php

require __DIR__ . '/vendor/autoload.php';

use Telegram\Bot\Api;
use Dotenv\Dotenv;
use Telegram\Bot\Keyboard\Keyboard;

// --- Константы состояний пользователя ---
define('STATE_DEFAULT', 0);
// Регистрация
define('STATE_AWAITING_NAME', 1);
define('STATE_AWAITING_EMAIL', 2);
define('STATE_AWAITING_PASSWORD', 3);
// Запись тренировки
define('STATE_LOGGING_TRAINING_MENU', 10); // В меню "Добавить/Назад"
define('STATE_SELECTING_MUSCLE_GROUP', 11);
define('STATE_SELECTING_EXERCISE_TYPE', 12);
define('STATE_SELECTING_EXERCISE', 13);
define('STATE_AWAITING_REPS', 14);
define('STATE_AWAITING_WEIGHT', 15);


// Загружаем .env файл
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$telegram = new Api(env('TELEGRAM_BOT_TOKEN', ''));
$updateId = 0;

// --- Временное хранилище данных и состояний ---
$userStates = []; // [chatId => state]
$userData = [];   // [chatId => ['name', 'email', 'password']]
$userSelections = []; // [chatId => ['group', 'type', 'exercise']] - Временный выбор при добавлении
$currentTrainingLog = []; // [chatId => [['exercise', 'reps', 'weight'], ...]] - Текущая тренировка

// --- Структура упражнений ---
// (Нужно заполнить полностью на основе твоего списка)
$exercises = [
    'Ноги и Ягодицы' => [
        'Приседания' => ['Приседания со штангой на спине', 'Приседания с гантелями', 'Приседания в тренажере Смита', /*...*/],
        'Выпады' => ['Выпады вперед', 'Выпады назад', /*...*/],
        'Тяги' => ['Становая тяга (классическая, сумо, румынская)', 'Тяга штанги в наклоне (к поясу)'],
        'Жим ногами' => ['Жим ногами в платформе (под разными углами)', 'Жим одной ногой', /*...*/],
        'Разгибания/Сгибания ног' => ['Разгибание ног в тренажере', 'Сгибание ног в тренажере'],
        'Ягодичный мостик' => ['Ягодичный мостик со штангой/гантелей', 'Ягодичный мостик на одной ноге'],
        'Махи ногами' => ['Махи ногой назад в тренажере', 'Махи ногой в сторону в тренажере'],
        'Икры' => ['Подъемы на носки стоя', 'Подъемы на носки сидя'],
    ],
    'Спина' => [
        'Подтягивания' => ['Подтягивания широким хватом', 'Подтягивания в гравитроне'],
        'Тяги' => ['Тяга верхнего блока к груди', 'Тяга нижнего блока к поясу сидя', 'Тяга гантели в наклоне одной рукой', 'Тяга Т-грифа'],
        'Гиперэкстензия' => ['Гиперэкстензия с весом', 'Гиперэкстензия под углом 45 градусов'],
        'Шраги' => ['Шраги в тренажере Смита', 'Шраги с гантелями сидя.'], // Точка в конце? Убрать если не нужна
    ],
    'Грудь' => [
        'Жим лежа' => ['Жим штанги лежа', 'Жим гантелей на наклонной скамье головой вниз, узким хватом', 'Жим лежа узким хватом'], // Проверить название второго жима
        'Разведения рук' => ['Разведение гантелей лежа', 'Разведение рук в тренажере "бабочка"'],
        'Отжимания' => ['Отжимания от пола', 'Отжимания на брусьях (с акцентом на грудь)', 'Отжимания с ногами на возвышении'],
        'Кроссовер' => ['Сведение рук в кроссовере сверху вниз', 'Сведение рук в кроссовере снизу вверх'],
    ],
     'Плечи' => [
        'Жим' => ['Жим штанги стоя (армейский жим)', 'Жим штанги сидя', 'Жим гантелей стоя/сидя', 'Жим Арнольда'],
        'Подъемы' => ['Подъемы гантелей перед собой', 'Подъемы гантелей в стороны', 'Подъемы гантелей в стороны в наклоне', 'Кубинский жим'],
        'Тяги' => ['Тяга штанги к подбородку.'], // Точка?
    ],
    'Руки (Бицепс и Трицепс)' => [ // Можно разделить на Бицепс и Трицепс для удобства выбора? Или оставить так? Оставим пока так.
        'Бицепс' => ['Сгибание рук со штангой стоя', 'Сгибание рук с гантелями стоя/сидя.', 'Молотки.', 'Сгибание рук на скамье Скотта', 'Сгибание рук в тренажере', 'Сгибание рук на нижнем блоке'], // Точки?
        'Трицепс' => ['Французский жим лежа/сидя (со штангой/гантелями)', 'Разгибание рук на верхнем блоке (с канатной рукоятью, прямой рукоятью)', 'Отжимания на брусьях (с акцентом на трицепс)', 'Разгибание руки с гантелью из-за головы', 'Отжимания узким хватом от пола', 'Разгибания из-за головы на верхнем блоке'],
    ],
    'Пресс' => [
        'Скручивания' => ['Скручивания на полу', 'Скручивания на наклонной скамье', 'Обратные скручивания (подъем ног)', 'Скручивания с поворотом корпуса', 'Скручивания на фитболе', 'Боковые скручивания'],
        'Подъемы' => ['Подъем ног в висе', 'Подъем коленей к груди в висе/на тренажере', '"V-образные" подъемы (одновременный подъем ног и корпуса)', 'Подъемы ног на римском стуле'],
        'Планка' => ['Планка (классическая, боковая, на предплечьях)', 'Планка с поднятием руки/ноги', 'Динамическая планка (переход из планки на предплечьях в планку на прямых руках)'],
    ],
];


// --- Клавиатуры ---
$keyboard = Keyboard::make() // Основная
    ->row([ Keyboard::button(['text' => 'Тренировки']), Keyboard::button(['text' => 'Питание']), ])
    ->row([ Keyboard::button(['text' => 'Аккаунт']), ])
    ->setResizeKeyboard(true)->setOneTimeKeyboard(false);

$keyboardTrainMenu = Keyboard::make() // Новое меню Тренировки
    ->row([ Keyboard::button(['text' => 'Записать тренировку']), ])
    ->row([ Keyboard::button(['text' => 'Посмотреть прогресс в упражнениях']), ])
    ->row([ Keyboard::button(['text' => 'Вывести отстающие группы мышц']), ])
    ->row([ Keyboard::button(['text' => 'Назад']), ])
    ->setResizeKeyboard(true)->setOneTimeKeyboard(false);

$keyboardNutrition = Keyboard::make() // Питание
    ->row([ Keyboard::button(['text' => 'Записать прием пищи']), ])
    ->row([ Keyboard::button(['text' => 'Посчитать калории за сегодня']), ])
    ->row([ Keyboard::button(['text' => 'Назад']), ])
    ->setResizeKeyboard(true)->setOneTimeKeyboard(false);

$keyboardAccount = Keyboard::make() // Аккаунт
    ->row([ Keyboard::button(['text' => 'Вывести имя и почту']), ])
    ->row([ Keyboard::button(['text' => 'Сменить аккаунт']), ])
    ->row([ Keyboard::button(['text' => 'Назад']), ])
    ->setResizeKeyboard(true)->setOneTimeKeyboard(false);

$keyboardBackOnly = Keyboard::make() // Только Назад
    ->row([ Keyboard::button(['text' => 'Назад']), ])
    ->setResizeKeyboard(true)->setOneTimeKeyboard(false);

// --- НОВАЯ Клавиатура для меню добавления упражнения ---
$keyboardAddExerciseMenu = Keyboard::make()
    ->row([
        Keyboard::button(['text' => 'Добавить упражнение']),
    ])
    ->row([
        Keyboard::button(['text' => 'Завершить запись тренировки']), // Добавим кнопку завершения
    ])
     ->row([
        Keyboard::button(['text' => 'Назад']), // Назад вернет в меню Тренировки
    ])
    ->setResizeKeyboard(true)
    ->setOneTimeKeyboard(false);

// --- Функции-хелперы ---
function generateListMessage(array $items): string {
    $message = "";
    foreach ($items as $index => $item) {
        $message .= ($index + 1) . ". " . $item . "\n";
    }
    return $message;
}


echo "Starting Telegram Bot...\n";

while (true) {
    $updates = $telegram->getUpdates(['offset' => $updateId + 1]);
    foreach ($updates as $update) {
        $updateId = $update->getUpdateId();

        if (!$update->isType('message')) continue; // Пропускаем не-сообщения

        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $text = $message->getText();

        // Инициализация массивов для нового пользователя, если нужно
        if (!isset($userStates[$chatId])) $userStates[$chatId] = STATE_DEFAULT;
        if (!isset($userSelections[$chatId])) $userSelections[$chatId] = [];
        if (!isset($currentTrainingLog[$chatId])) $currentTrainingLog[$chatId] = [];

        echo "Получено сообщение: $text (Chat ID: $chatId), State: {$userStates[$chatId]}\n";

        $currentState = $userStates[$chatId];

        // --- Обработка кнопки Назад ВО ВРЕМЯ выбора/ввода данных ---
        if ($text === 'Назад' && $currentState >= STATE_SELECTING_MUSCLE_GROUP && $currentState <= STATE_AWAITING_WEIGHT) {
            switch ($currentState) {
                case STATE_SELECTING_MUSCLE_GROUP: // Отмена добавления упражнения
                    $userStates[$chatId] = STATE_LOGGING_TRAINING_MENU;
                    unset($userSelections[$chatId]); // Очищаем временный выбор
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Добавление упражнения отменено.', 'reply_markup' => $keyboardAddExerciseMenu]);
                    break;
                case STATE_SELECTING_EXERCISE_TYPE:
                    $userStates[$chatId] = STATE_SELECTING_MUSCLE_GROUP;
                    unset($userSelections[$chatId]['group']); // Убираем выбор группы
                    $groupKeys = array_keys($exercises);
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Выберите группу мышц:\n" . generateListMessage($groupKeys), 'reply_markup' => $keyboardBackOnly]);
                    break;
                case STATE_SELECTING_EXERCISE:
                    $userStates[$chatId] = STATE_SELECTING_EXERCISE_TYPE;
                    $group = $userSelections[$chatId]['group'];
                    unset($userSelections[$chatId]['type']); // Убираем выбор типа
                    $typeKeys = isset($exercises[$group]) ? array_keys($exercises[$group]) : [];
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Группа: {$group}\nВыберите тип упражнения:\n" . generateListMessage($typeKeys), 'reply_markup' => $keyboardBackOnly]);
                    break;
                 case STATE_AWAITING_REPS:
                    $userStates[$chatId] = STATE_SELECTING_EXERCISE;
                     $group = $userSelections[$chatId]['group'];
                     $type = $userSelections[$chatId]['type'];
                    unset($userSelections[$chatId]['exercise']); // Убираем выбор упражнения
                    $exerciseList = isset($exercises[$group][$type]) ? $exercises[$group][$type] : [];
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Группа: {$group}\nТип: {$type}\nВыберите упражнение:\n" . generateListMessage($exerciseList), 'reply_markup' => $keyboardBackOnly]);
                    break;
                 case STATE_AWAITING_WEIGHT:
                     $userStates[$chatId] = STATE_AWAITING_REPS;
                     unset($userSelections[$chatId]['reps']); // Убираем повторы
                     $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Упражнение: {$userSelections[$chatId]['exercise']}\nВведите количество повторений:", 'reply_markup' => $keyboardBackOnly]);
                     break;
            }
            continue; // Важно! Пропускаем остальную обработку
        }

        // --- Обработка состояний регистрации ---
        if ($currentState >= STATE_AWAITING_NAME && $currentState <= STATE_AWAITING_PASSWORD) {
            // ... (логика регистрации остается как в предыдущей версии, с кнопкой Назад при смене акка) ...
                if ($currentState === STATE_AWAITING_NAME) {
                    if ($text === 'Назад') { // Назад при смене аккаунта
                        $userStates[$chatId] = STATE_DEFAULT;
                        $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Смена аккаунта отменена. Выберите действие с аккаунтом:', 'reply_markup' => $keyboardAccount]);
                    } else {
                        $userData[$chatId]['name'] = $text;
                        $userStates[$chatId] = STATE_AWAITING_EMAIL;
                        $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Отлично! Теперь введите вашу почту:', 'reply_markup' => Keyboard::remove()]);
                    }
                } elseif ($currentState === STATE_AWAITING_EMAIL) {
                    $userData[$chatId]['email'] = $text;
                    $userStates[$chatId] = STATE_AWAITING_PASSWORD;
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Почти готово! Введите ваш пароль:']);
                } elseif ($currentState === STATE_AWAITING_PASSWORD) {
                    $userData[$chatId]['password'] = $text;
                    $userStates[$chatId] = STATE_DEFAULT;
                    $name = $userData[$chatId]['name'] ?? 'Пользователь';
                    echo "Сохранены данные для $chatId: "; print_r($userData[$chatId]); echo "\n";
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Готово, {$name}! Вы успешно зарегистрированы/вошли.", 'reply_markup' => $keyboard]);
                }
            continue;
        }

        // --- Обработка состояний записи тренировки ---
        if ($currentState >= STATE_SELECTING_MUSCLE_GROUP && $currentState <= STATE_AWAITING_WEIGHT) {
             // Проверяем, является ли ввод числом, если ожидаем выбор из списка
             if ($currentState <= STATE_SELECTING_EXERCISE && !ctype_digit($text)) {
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, введите номер из списка или нажмите "Назад".', 'reply_markup' => $keyboardBackOnly]);
                 continue;
             }
             $choiceIndex = (int)$text - 1; // Индекс в массиве (начинается с 0)

             switch ($currentState) {
                 case STATE_SELECTING_MUSCLE_GROUP:
                     $groupKeys = array_keys($exercises);
                     if (isset($groupKeys[$choiceIndex])) {
                         $selectedGroup = $groupKeys[$choiceIndex];
                         $userSelections[$chatId]['group'] = $selectedGroup;
                         $userStates[$chatId] = STATE_SELECTING_EXERCISE_TYPE;
                         $typeKeys = array_keys($exercises[$selectedGroup]);
                         $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Группа: {$selectedGroup}\nВыберите тип упражнения:\n" . generateListMessage($typeKeys), 'reply_markup' => $keyboardBackOnly]);
                     } else {
                         $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Неверный номер группы. Попробуйте снова.', 'reply_markup' => $keyboardBackOnly]);
                     }
                     break;

                 case STATE_SELECTING_EXERCISE_TYPE:
                     $group = $userSelections[$chatId]['group'] ?? null;
                     if (!$group || !isset($exercises[$group])) { /* Ошибка логики или пользователь вернулся */ $userStates[$chatId] = STATE_LOGGING_TRAINING_MENU; $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка выбора группы. Начните добавление заново.', 'reply_markup' => $keyboardAddExerciseMenu]); break; }
                     $typeKeys = array_keys($exercises[$group]);
                     if (isset($typeKeys[$choiceIndex])) {
                         $selectedType = $typeKeys[$choiceIndex];
                         $userSelections[$chatId]['type'] = $selectedType;
                         $userStates[$chatId] = STATE_SELECTING_EXERCISE;
                         $exerciseList = $exercises[$group][$selectedType];
                         $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Группа: {$group}\nТип: {$selectedType}\nВыберите упражнение:\n" . generateListMessage($exerciseList), 'reply_markup' => $keyboardBackOnly]);
                     } else {
                          $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Неверный номер типа. Попробуйте снова.', 'reply_markup' => $keyboardBackOnly]);
                     }
                     break;

                 case STATE_SELECTING_EXERCISE:
                     $group = $userSelections[$chatId]['group'] ?? null;
                     $type = $userSelections[$chatId]['type'] ?? null;
                     if (!$group || !$type || !isset($exercises[$group][$type])) { $userStates[$chatId] = STATE_LOGGING_TRAINING_MENU; $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка выбора типа/группы. Начните добавление заново.', 'reply_markup' => $keyboardAddExerciseMenu]); break; }
                     $exerciseList = $exercises[$group][$type];
                     if (isset($exerciseList[$choiceIndex])) {
                         $selectedExercise = $exerciseList[$choiceIndex];
                         $userSelections[$chatId]['exercise'] = $selectedExercise;
                         $userStates[$chatId] = STATE_AWAITING_REPS;
                         $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Упражнение: {$selectedExercise}\nВведите количество повторений:", 'reply_markup' => $keyboardBackOnly]); // Оставляем Назад
                     } else {
                          $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Неверный номер упражнения. Попробуйте снова.', 'reply_markup' => $keyboardBackOnly]);
                     }
                     break;

                 case STATE_AWAITING_REPS:
                     // Пока принимаем любой текст для повторов
                     $userSelections[$chatId]['reps'] = $text;
                     $userStates[$chatId] = STATE_AWAITING_WEIGHT;
                     $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Повторения: {$text}\nВведите вес (можно 0 или текст, например 'собственный'):", 'reply_markup' => $keyboardBackOnly]); // Оставляем Назад
                     break;

                 case STATE_AWAITING_WEIGHT:
                     // Пока принимаем любой текст для веса
                     $userSelections[$chatId]['weight'] = $text;

                     // Упражнение готово, добавляем в лог текущей тренировки
                     $logEntry = [
                         'exercise' => $userSelections[$chatId]['exercise'],
                         'reps' => $userSelections[$chatId]['reps'],
                         'weight' => $userSelections[$chatId]['weight'],
                     ];
                     $currentTrainingLog[$chatId][] = $logEntry;

                     echo "Добавлено в лог для $chatId: "; print_r($logEntry); echo "\n"; // Отладка

                     // Сбрасываем временный выбор и состояние
                     unset($userSelections[$chatId]);
                     $userStates[$chatId] = STATE_LOGGING_TRAINING_MENU;

                     $telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => "Упражнение '{$logEntry['exercise']}' ({$logEntry['reps']} повт. x {$logEntry['weight']}) добавлено!\nХотите добавить еще?",
                         'reply_markup' => $keyboardAddExerciseMenu // Возвращаем меню добавления
                     ]);
                     break;
             }
             continue; // Пропускаем остальную обработку
        }


        // --- Обработка команд и кнопок (только если состояние STATE_DEFAULT или STATE_LOGGING_TRAINING_MENU) ---
        // Используем switch для команд/кнопок, не связанных с процессом добавления упражнения
        switch ($text) {
            case '/start':
                // ... (остается без изменений) ...
                if (isset($userData[$chatId])) {
                    $name = $userData[$chatId]['name'] ?? 'Пользователь';
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => "С возвращением, {$name}! Выберите действие:", 'reply_markup' => $keyboard]);
                } else {
                    $userStates[$chatId] = STATE_AWAITING_NAME;
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Добро пожаловать! Для начала работы, пожалуйста, введите ваше имя:", 'reply_markup' => Keyboard::remove()]);
                }
                break;

            case 'Аккаунт':
                 if ($currentState !== STATE_DEFAULT) break; // Игнорируем если не в дефолтном состоянии
                 if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, сначала войдите в аккаунт(/start).']); break; }
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Выберите действие с аккаунтом:', 'reply_markup' => $keyboardAccount]);
                 break;
            case 'Вывести имя и почту':
                 if ($currentState !== STATE_DEFAULT) break;
                 if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Данные не найдены.']); break; }
                 $name = $userData[$chatId]['name'] ?? 'Не указано';
                 $email = $userData[$chatId]['email'] ?? 'Не указана';
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Ваши данные:\nИмя: {$name}\nПочта: {$email}", 'reply_markup' => $keyboardAccount]);
                 break;
            case 'Сменить аккаунт':
                 if ($currentState !== STATE_DEFAULT) break;
                 $userStates[$chatId] = STATE_AWAITING_NAME;
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Введите ваше имя (или нажмите 'Назад' для отмены):", 'reply_markup' => $keyboardBackOnly]);
                 break;

            case 'Тренировки':
                 if ($currentState !== STATE_DEFAULT) break;
                 if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, сначала войдите в аккаунт(/start).']); break; }
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Выберите действие с тренировками:', 'reply_markup' => $keyboardTrainMenu]); // Показываем НОВОЕ меню
                 break;
            case 'Питание':
                 if ($currentState !== STATE_DEFAULT) break;
                 if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, сначала войдите в аккаунт(/start).']); break; }
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Выберите действие по питанию:', 'reply_markup' => $keyboardNutrition]);
                 break;

            // --- Кнопки НОВОГО меню Тренировки ---
            case 'Записать тренировку':
                 if ($currentState !== STATE_DEFAULT) break;
                 if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, сначала войдите в аккаунт(/start).']); break; }
                 $userStates[$chatId] = STATE_LOGGING_TRAINING_MENU; // Переходим в режим записи
                 $currentTrainingLog[$chatId] = []; // Очищаем/инициализируем лог для новой тренировки
                 unset($userSelections[$chatId]); // Очищаем временный выбор на всякий случай
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Начинаем запись тренировки. Добавьте первое упражнение.', 'reply_markup' => $keyboardAddExerciseMenu]);
                 break;
            case 'Посмотреть прогресс в упражнениях':
                 if ($currentState !== STATE_DEFAULT) break;
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Функция 'Посмотреть прогресс в упражнениях' еще в разработке."]);
                 break;
            case 'Вывести отстающие группы мышц':
                 if ($currentState !== STATE_DEFAULT) break;
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Функция 'Вывести отстающие группы мышц' еще в разработке."]);
                 break;

            // --- Кнопки меню Записи Тренировки ---
             case 'Добавить упражнение':
                 if ($currentState !== STATE_LOGGING_TRAINING_MENU) break; // Эта кнопка работает только из меню записи
                 $userStates[$chatId] = STATE_SELECTING_MUSCLE_GROUP;
                 unset($userSelections[$chatId]); // Очищаем предыдущий выбор
                 $groupKeys = array_keys($exercises);
                 $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Выберите группу мышц:\n" . generateListMessage($groupKeys), 'reply_markup' => $keyboardBackOnly]);
                 break;
                case 'Завершить запись тренировки':
                if ($currentState !== STATE_LOGGING_TRAINING_MENU) break; // Проверка состояния

                $logCount = isset($currentTrainingLog[$chatId]) ? count($currentTrainingLog[$chatId]) : 0;

                if ($logCount > 0) {
                    // Если есть записанные упражнения - завершаем как раньше
                    $userStates[$chatId] = STATE_DEFAULT;
                    // Здесь в будущем будет логика сохранения $currentTrainingLog[$chatId]
                    echo "Завершение тренировки для $chatId. Записано упражнений: $logCount\n"; // Отладка
                    print_r($currentTrainingLog[$chatId]); // Отладка
                    unset($currentTrainingLog[$chatId]); // Очищаем лог после завершения
                    unset($userSelections[$chatId]); // Очищаем временный выбор
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Запись тренировки завершена. Вы записали {$logCount} упр./подходов.",
                        'reply_markup' => $keyboard // Возвращаем в главное меню
                    ]);
                } else {
                    // Если упражнений не было добавлено
                    $userStates[$chatId] = STATE_DEFAULT; // Сбрасываем состояние
                    unset($currentTrainingLog[$chatId]); // Очищаем пустой лог
                    unset($userSelections[$chatId]); // Очищаем временный выбор
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Ни одного упражнения не было добавлено. Возврат в главное меню.', // Сообщение об отсутствии упражнений
                        'reply_markup' => $keyboard // Возвращаем в главное меню
                    ]);
                }
                break; // Конец case 'Завершить запись тренировки'

            // --- Кнопки подменю Питание ---
            case 'Записать прием пищи': $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Вы выбрали 'Записать прием пищи'."]); break;
            case 'Посчитать калории за сегодня': $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Вы выбрали 'Посчитать калории за сегодня'."]); break;

            // --- Кнопка "Назад" (из ГЛАВНЫХ подменю - Тренировки, Питание, Аккаунт) ---
            case 'Назад':
                 // Если мы в меню добавления упражнения, "Назад" вернет в меню Тренировки
                 if ($currentState === STATE_LOGGING_TRAINING_MENU) {
                     $userStates[$chatId] = STATE_DEFAULT; // Выходим из режима записи
                     unset($currentTrainingLog[$chatId]); // Очищаем незавершенную тренировку? Или спросить? Пока очищаем.
                     unset($userSelections[$chatId]);
                     $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Вы вернулись в меню тренировок.', 'reply_markup' => $keyboardTrainMenu]);
                 } else if ($currentState === STATE_DEFAULT) {
                     // Если мы уже в главном меню, "Назад" ничего не делает или возвращает главное меню
                     $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Вы уже в главном меню.', 'reply_markup' => $keyboard]);
                 }
                 // Обработка "Назад" во время выбора/ввода происходит ВЫШЕ
                 break;

        } // Конец switch ($text)
    } // Конец if ($update->isType('message'))
} // Конец foreach ($updates as $update)
sleep(1);
 // Конец while (true)