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
// ОБЩИЕ СОСТОЯНИЯ для выбора упражнения
define('STATE_SELECTING_MUSCLE_GROUP', 11);
define('STATE_SELECTING_EXERCISE_TYPE', 12);
define('STATE_SELECTING_EXERCISE', 13);
// Состояния ТОЛЬКО для записи тренировки
define('STATE_LOGGING_TRAINING_MENU', 10); // Находимся в меню "Добавить/Завершить/Назад"
define('STATE_AWAITING_REPS', 14);
define('STATE_AWAITING_WEIGHT', 15);

// Загружаем .env файл
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$telegram = new Api(env('TELEGRAM_BOT_TOKEN', ''));
$updateId = 0;

// --- Путь к файлу для хранения данных ---
$userDataFile = __DIR__ . '/bot_users.json';

// --- Загрузка данных пользователей из файла ---
$userStates = []; // Состояния всегда сбрасываются
$userData = [];
if (file_exists($userDataFile)) {
    $jsonContent = file_get_contents($userDataFile);
    $userData = json_decode($jsonContent, true);
    if (!is_array($userData)) {
        $userData = [];
    }
    echo "User data loaded from {$userDataFile}\n";
} else {
    echo "User data file not found, starting fresh.\n";
}

// --- Временное хранилище (остальные) ---
$userSelections = []; // ['mode', 'group', 'type', 'exercise', 'reps', 'weight']
$currentTrainingLog = [];

// --- Структура упражнений (ПОЛНОСТЬЮ ЗАПОЛНЕНА) ---
$exercises = [
    'Ноги и Ягодицы' => [
        'Приседания' => [
            'Приседания со штангой на спине',
            'Приседания с гантелями',
            'Приседания в тренажере Смита',
            'Болгарские сплит-приседания',
            'Приседания на одной ноге',
            'Приседания с весом над головой',
        ],
        'Выпады' => [
            'Выпады вперед',
            'Выпады назад',
            'Выпады в сторону',
            'Выпады с гантелями/штангой',
            'Ходьба выпадами',
        ],
        'Тяги' => [ // Возможно, стоит перенести в Спину?
            'Становая тяга (классическая, сумо, румынская)',
            'Тяга штанги в наклоне (к поясу)',
        ],
        'Жим ногами' => [
            'Жим ногами в платформе (под разными углами)',
            'Жим одной ногой',
            'Жим ногами с узкой постановкой стоп',
            'Жим ногами с широкой постановкой стоп',
            'Жим ногами с носками, развернутыми наружу',
            'Жим ногами с носками, развернутыми внутрь',
            'Жим ногами с высоким положением стоп на платформе',
            'Жим ногами с низким положением стоп на платформе',
            'Жим ногами в тренажере Гаккеншмидта',
        ],
        'Разгибания/Сгибания ног' => [
            'Разгибание ног в тренажере',
            'Сгибание ног в тренажере',
        ],
        'Ягодичный мостик' => [
            'Ягодичный мостик со штангой/гантелей',
            'Ягодичный мостик на одной ноге',
        ],
        'Махи ногами' => [
            'Махи ногой назад в тренажере',
            'Махи ногой в сторону в тренажере',
        ],
        'Икры' => [
            'Подъемы на носки стоя',
            'Подъемы на носки сидя',
        ],
    ],
    'Спина' => [
        'Подтягивания' => [
            'Подтягивания широким хватом',
            'Подтягивания в гравитроне',
        ],
        'Тяги' => [
            'Тяга верхнего блока к груди',
            'Тяга нижнего блока к поясу сидя',
            'Тяга гантели в наклоне одной рукой',
            'Тяга Т-грифа',
        ],
        'Гиперэкстензия' => [
            'Гиперэкстензия с весом',
            'Гиперэкстензия под углом 45 градусов',
        ],
        'Шраги' => [
            'Шраги в тренажере Смита',
            'Шраги с гантелями сидя',
        ],
    ],
    'Грудь' => [
        'Жим лежа' => [
            'Жим штанги лежа',
            'Жим гантелей на наклонной скамье головой вниз, узким хватом', // Проверь название
            'Жим лежа узким хватом', // Повтор? На трицепс?
        ],
        'Разведения рук' => [
            'Разведение гантелей лежа',
            'Разведение рук в тренажере "бабочка"',
        ],
        'Отжимания' => [
            'Отжимания от пола',
            'Отжимания на брусьях (с акцентом на грудь)',
            'Отжимания с ногами на возвышении',
        ],
        'Кроссовер' => [
            'Сведение рук в кроссовере сверху вниз',
            'Сведение рук в кроссовере снизу вверх',
        ],
    ],
     'Плечи' => [
        'Жим' => [
            'Жим штанги стоя (армейский жим)',
            'Жим штанги сидя',
            'Жим гантелей стоя/сидя',
            'Жим Арнольда',
        ],
        'Подъемы' => [
            'Подъемы гантелей перед собой',
            'Подъемы гантелей в стороны',
            'Подъемы гантелей в стороны в наклоне',
            'Кубинский жим',
        ],
        'Тяги' => [
            'Тяга штанги к подбородку',
        ],
    ],
    'Руки (Бицепс и Трицепс)' => [
        'Бицепс' => [
            'Сгибание рук со штангой стоя',
            'Сгибание рук с гантелями стоя/сидя',
            'Молотки',
            'Сгибание рук на скамье Скотта',
            'Сгибание рук в тренажере',
            'Сгибание рук на нижнем блоке',
        ],
        'Трицепс' => [
            'Французский жим лежа/сидя (со штангой/гантелями)',
            'Разгибание рук на верхнем блоке (с канатной рукоятью, прямой рукоятью)',
            'Отжимания на брусьях (с акцентом на трицепс)',
            'Разгибание руки с гантелью из-за головы',
            'Отжимания узким хватом от пола',
            'Разгибания из-за головы на верхнем блоке',
        ],
    ],
    'Пресс' => [
        'Скручивания' => [
            'Скручивания на полу',
            'Скручивания на наклонной скамье',
            'Обратные скручивания (подъем ног)',
            'Скручивания с поворотом корпуса',
            'Скручивания на фитболе',
            'Боковые скручивания',
        ],
        'Подъемы' => [
            'Подъем ног в висе',
            'Подъем коленей к груди в висе/на тренажере',
            '"V-образные" подъемы (одновременный подъем ног и корпуса)',
            'Подъемы ног на римском стуле',
        ],
        'Планка' => [
            'Планка (классическая, боковая, на предплечьях)',
            'Планка с поднятием руки/ноги',
            'Динамическая планка (переход из планки на предплечьях в планку на прямых руках)',
        ],
    ],
];


// --- Клавиатуры ---
$keyboard = Keyboard::make()->row([ Keyboard::button(['text' => 'Тренировки']), Keyboard::button(['text' => 'Питание']), ])->row([ Keyboard::button(['text' => 'Аккаунт']), ])->setResizeKeyboard(true)->setOneTimeKeyboard(false);
$keyboardTrainMenu = Keyboard::make()->row([ Keyboard::button(['text' => 'Записать тренировку']), ])->row([ Keyboard::button(['text' => 'Посмотреть прогресс в упражнениях']), ])->row([ Keyboard::button(['text' => 'Вывести отстающие группы мышц']), ])->row([ Keyboard::button(['text' => 'Назад']), ])->setResizeKeyboard(true)->setOneTimeKeyboard(false);
$keyboardNutrition = Keyboard::make()->row([ Keyboard::button(['text' => 'Записать прием пищи']), ])->row([ Keyboard::button(['text' => 'Посчитать калории за сегодня']), ])->row([ Keyboard::button(['text' => 'Назад']), ])->setResizeKeyboard(true)->setOneTimeKeyboard(false);
$keyboardAccount = Keyboard::make()->row([ Keyboard::button(['text' => 'Вывести имя и почту']), ])->row([ Keyboard::button(['text' => 'Сменить аккаунт']), ])->row([ Keyboard::button(['text' => 'Назад']), ])->setResizeKeyboard(true)->setOneTimeKeyboard(false);
$keyboardBackOnly = Keyboard::make()->row([ Keyboard::button(['text' => 'Назад']), ])->setResizeKeyboard(true)->setOneTimeKeyboard(false);
$keyboardAddExerciseMenu = Keyboard::make()->row([ Keyboard::button(['text' => 'Добавить упражнение']), ])->row([ Keyboard::button(['text' => 'Завершить запись тренировки']), ])->row([ Keyboard::button(['text' => 'Назад']), ])->setResizeKeyboard(true)->setOneTimeKeyboard(false);

// --- Функции-хелперы ---
function generateListMessage(array $items): string { $message = ""; foreach ($items as $index => $item) { $message .= ($index + 1) . ". " . $item . "\n"; } return rtrim($message); }
function saveUserData(array $data, string $filePath): bool { $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); if ($jsonContent === false) { echo "Error encoding user data to JSON.\n"; return false; } $result = file_put_contents($filePath, $jsonContent); if ($result === false) { echo "Error writing user data to file: {$filePath}\n"; return false; } echo "User data saved to {$filePath}\n"; return true; }

echo "Starting Telegram Bot...\n";

while (true) {
    $updates = $telegram->getUpdates(['offset' => $updateId + 1]);
    foreach ($updates as $update) {
        $updateId = $update->getUpdateId();

        if (!$update->isType('message')) continue;

        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $text = $message->getText();

        if (!isset($userStates[$chatId])) $userStates[$chatId] = STATE_DEFAULT;
        if (!isset($userSelections[$chatId])) $userSelections[$chatId] = [];
        // currentTrainingLog инициализируется при начале записи

        echo "Получено сообщение: $text (Chat ID: $chatId), State: {$userStates[$chatId]}\n";

        $currentState = $userStates[$chatId];
        $currentMode = $userSelections[$chatId]['mode'] ?? null;

        // --- Обработка кнопки Назад ВО ВРЕМЯ выбора/ввода данных ---
        if ($text === 'Назад' && $currentState >= STATE_SELECTING_MUSCLE_GROUP && $currentState <= STATE_AWAITING_WEIGHT) {
             $returnState = ($currentMode === 'log') ? STATE_LOGGING_TRAINING_MENU : STATE_DEFAULT;
             $returnKeyboard = ($currentMode === 'log') ? $keyboardAddExerciseMenu : $keyboardTrainMenu;
             $cancelMessage = ($currentMode === 'log') ? 'Добавление упражнения отменено.' : 'Просмотр прогресса отменен.';

            switch ($currentState) {
                case STATE_SELECTING_MUSCLE_GROUP: $userStates[$chatId] = $returnState; unset($userSelections[$chatId]); $telegram->sendMessage(['chat_id' => $chatId, 'text' => $cancelMessage, 'reply_markup' => $returnKeyboard]); break;
                case STATE_SELECTING_EXERCISE_TYPE: $userStates[$chatId] = STATE_SELECTING_MUSCLE_GROUP; unset($userSelections[$chatId]['group']); $groupKeys = array_keys($exercises); $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Выберите группу:\n" . generateListMessage($groupKeys), 'reply_markup' => $keyboardBackOnly]); break;
                case STATE_SELECTING_EXERCISE: $userStates[$chatId] = STATE_SELECTING_EXERCISE_TYPE; $group = $userSelections[$chatId]['group'] ?? '???'; unset($userSelections[$chatId]['type']); $typeKeys = isset($exercises[$group]) ? array_keys($exercises[$group]) : []; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Группа: {$group}\nВыберите тип:\n" . generateListMessage($typeKeys), 'reply_markup' => $keyboardBackOnly]); break;
                case STATE_AWAITING_REPS: $userStates[$chatId] = STATE_SELECTING_EXERCISE; $group = $userSelections[$chatId]['group'] ?? '???'; $type = $userSelections[$chatId]['type'] ?? '???'; unset($userSelections[$chatId]['exercise']); $exerciseList = isset($exercises[$group][$type]) ? $exercises[$group][$type] : []; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Тип: {$type}\nВыберите упр.:\n" . generateListMessage($exerciseList), 'reply_markup' => $keyboardBackOnly]); break;
                case STATE_AWAITING_WEIGHT: $userStates[$chatId] = STATE_AWAITING_REPS; unset($userSelections[$chatId]['reps']); $exercise = $userSelections[$chatId]['exercise'] ?? '???'; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Упражнение: {$exercise}\nПовторения:", 'reply_markup' => $keyboardBackOnly]); break;
            }
            continue;
        }

        // --- Обработка состояний регистрации ---
        if ($currentState >= STATE_AWAITING_NAME && $currentState <= STATE_AWAITING_PASSWORD) {
            if ($currentState === STATE_AWAITING_NAME) { if ($text === 'Назад') { $userStates[$chatId] = STATE_DEFAULT; $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Смена аккаунта отменена.', 'reply_markup' => $keyboardAccount]); } else { $userData[$chatId]['name'] = $text; $userStates[$chatId] = STATE_AWAITING_EMAIL; $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Почта:', 'reply_markup' => Keyboard::remove()]); } }
            elseif ($currentState === STATE_AWAITING_EMAIL) { $userData[$chatId]['email'] = $text; $userStates[$chatId] = STATE_AWAITING_PASSWORD; $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пароль:']); }
            elseif ($currentState === STATE_AWAITING_PASSWORD) { $hashedPassword = password_hash($text, PASSWORD_DEFAULT); if ($hashedPassword === false) { echo "Pwd hash failed for {$chatId}!\n"; $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка регистрации.', 'reply_markup' => $keyboard]); $userStates[$chatId] = STATE_DEFAULT; unset($userData[$chatId]); } else { $userData[$chatId]['password'] = $hashedPassword; $userStates[$chatId] = STATE_DEFAULT; $name = $userData[$chatId]['name'] ?? '?'; echo "Сохр. {$chatId}: Name={$name}, Email={$userData[$chatId]['email']}, PwdHash={$hashedPassword}\n"; saveUserData($userData, $userDataFile); $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Готово, {$name}!", 'reply_markup' => $keyboard]); } }
            continue;
        }

        // --- ОБЩАЯ Обработка состояний выбора упражнения ---
        if ($currentState >= STATE_SELECTING_MUSCLE_GROUP && $currentState <= STATE_SELECTING_EXERCISE) {
             if (!ctype_digit($text)) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Введите номер или "Назад".', 'reply_markup' => $keyboardBackOnly]); continue; }
             $choiceIndex = (int)$text - 1;

             switch ($currentState) {
                 case STATE_SELECTING_MUSCLE_GROUP: $groupKeys = array_keys($exercises); if (isset($groupKeys[$choiceIndex])) { $selectedGroup = $groupKeys[$choiceIndex]; $userSelections[$chatId]['group'] = $selectedGroup; $userStates[$chatId] = STATE_SELECTING_EXERCISE_TYPE; $typeKeys = isset($exercises[$selectedGroup]) ? array_keys($exercises[$selectedGroup]) : []; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Группа: {$selectedGroup}\nВыберите тип:\n" . generateListMessage($typeKeys), 'reply_markup' => $keyboardBackOnly]); } else { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Неверный номер.', 'reply_markup' => $keyboardBackOnly]); } break;
                 case STATE_SELECTING_EXERCISE_TYPE: $group = $userSelections[$chatId]['group'] ?? null; if (!$group) { $userStates[$chatId] = STATE_DEFAULT; $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка. Начните заново.', 'reply_markup' => $keyboard]); break; } $typeKeys = isset($exercises[$group]) ? array_keys($exercises[$group]) : []; if (isset($typeKeys[$choiceIndex])) { $selectedType = $typeKeys[$choiceIndex]; $userSelections[$chatId]['type'] = $selectedType; $userStates[$chatId] = STATE_SELECTING_EXERCISE; $exerciseList = isset($exercises[$group][$selectedType]) ? $exercises[$group][$selectedType] : []; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Тип: {$selectedType}\nВыберите упражнение:\n" . generateListMessage($exerciseList), 'reply_markup' => $keyboardBackOnly]); } else { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Неверный номер.', 'reply_markup' => $keyboardBackOnly]); } break;
                 case STATE_SELECTING_EXERCISE: $group = $userSelections[$chatId]['group'] ?? null; $type = $userSelections[$chatId]['type'] ?? null; $mode = $userSelections[$chatId]['mode'] ?? null; if (!$group || !$type || !$mode) { $userStates[$chatId] = STATE_DEFAULT; $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка. Начните заново.', 'reply_markup' => $keyboard]); break; } $exerciseList = isset($exercises[$group][$type]) ? $exercises[$group][$type] : []; if (isset($exerciseList[$choiceIndex])) { $selectedExercise = $exerciseList[$choiceIndex]; if ($mode === 'log') { $userSelections[$chatId]['exercise'] = $selectedExercise; $userStates[$chatId] = STATE_AWAITING_REPS; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Упражнение: {$selectedExercise}\nПовторения:", 'reply_markup' => $keyboardBackOnly]); } elseif ($mode === 'view') { $userStates[$chatId] = STATE_DEFAULT; unset($userSelections[$chatId]); $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Упражнение: {$selectedExercise}\nВаши результаты:\n(пока пусто)", 'reply_markup' => $keyboardTrainMenu]); } else { $userStates[$chatId] = STATE_DEFAULT; $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Внутренняя ошибка режима.', 'reply_markup' => $keyboard]); } } else { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Неверный номер.', 'reply_markup' => $keyboardBackOnly]); } break;
             }
             continue;
        }

         // --- Обработка состояний ТОЛЬКО для записи (повторы, вес) ---
         if ($currentState === STATE_AWAITING_REPS || $currentState === STATE_AWAITING_WEIGHT) {
              if ($currentState === STATE_AWAITING_REPS) { $userSelections[$chatId]['reps'] = $text; $userStates[$chatId] = STATE_AWAITING_WEIGHT; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Повторения: {$text}\nВес:", 'reply_markup' => $keyboardBackOnly]); }
              elseif ($currentState === STATE_AWAITING_WEIGHT) { $userSelections[$chatId]['weight'] = $text; $logEntry = ['exercise' => $userSelections[$chatId]['exercise'] ?? '???', 'reps' => $userSelections[$chatId]['reps'] ?? '???', 'weight' => $userSelections[$chatId]['weight'] ?? '???',]; if (!isset($currentTrainingLog[$chatId])) $currentTrainingLog[$chatId] = []; $currentTrainingLog[$chatId][] = $logEntry; echo "Добавлено в лог для $chatId: "; print_r($logEntry); echo "\n"; $exerciseName = $logEntry['exercise']; unset($userSelections[$chatId]); $userStates[$chatId] = STATE_LOGGING_TRAINING_MENU; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Упражнение '{$exerciseName}' добавлено!\nДобавить еще?", 'reply_markup' => $keyboardAddExerciseMenu]); }
              continue;
         }


        // --- Обработка команд и кнопок (состояние STATE_DEFAULT или STATE_LOGGING_TRAINING_MENU) ---
        switch ($text) {
            case '/start': if (isset($userData[$chatId])) { $name = $userData[$chatId]['name'] ?? '?'; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "С возвращением, {$name}!", 'reply_markup' => $keyboard]); } else { $userStates[$chatId] = STATE_AWAITING_NAME; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Введите имя:", 'reply_markup' => Keyboard::remove()]); } break;
            case 'Аккаунт': if ($currentState !== STATE_DEFAULT) break; if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Сначала войдите (/start).']); break; } $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Аккаунт:', 'reply_markup' => $keyboardAccount]); break;
            case 'Вывести имя и почту': if ($currentState !== STATE_DEFAULT) break; if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Данные не найдены.']); break; } $name = $userData[$chatId]['name'] ?? 'Не указ.'; $email = $userData[$chatId]['email'] ?? 'Не указ.'; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Имя: {$name}\nПочта: {$email}", 'reply_markup' => $keyboardAccount]); break;
            case 'Сменить аккаунт': if ($currentState !== STATE_DEFAULT) break; $userStates[$chatId] = STATE_AWAITING_NAME; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Введите имя (или 'Назад'):", 'reply_markup' => $keyboardBackOnly]); break;
            case 'Тренировки': if ($currentState !== STATE_DEFAULT) break; if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Сначала войдите (/start).']); break; } $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Тренировки:', 'reply_markup' => $keyboardTrainMenu]); break;
            case 'Питание': if ($currentState !== STATE_DEFAULT) break; if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Сначала войдите (/start).']); break; } $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Питание:', 'reply_markup' => $keyboardNutrition]); break;
            case 'Вывести отстающие группы мышц':
                if ($currentState !== STATE_DEFAULT) break; // Игнорируем, если не в основном меню
                if (!isset($userData[$chatId])) { // Проверка регистрации
                    $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Сначала войдите (/start).']);
                    break;
                }
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "У тебя всё отстающее, иди качаться дрищ!", // Новое сообщение-заглушка
                    'reply_markup' => $keyboardTrainMenu // Возвращаем меню Тренировки
                ]);
                break;
            // --- Кнопки меню Тренировки ---
            case 'Записать тренировку': if ($currentState !== STATE_DEFAULT) break; if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Сначала войдите (/start).']); break; } $userStates[$chatId] = STATE_LOGGING_TRAINING_MENU; $currentTrainingLog[$chatId] = []; unset($userSelections[$chatId]); $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Запись тренировки:', 'reply_markup' => $keyboardAddExerciseMenu]); break;
            case 'Посмотреть прогресс в упражнениях': if ($currentState !== STATE_DEFAULT) break; if (!isset($userData[$chatId])) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Сначала войдите (/start).']); break; } $userStates[$chatId] = STATE_SELECTING_MUSCLE_GROUP; $userSelections[$chatId] = ['mode' => 'view']; $groupKeys = array_keys($exercises); $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Для просмотра прогресса, выберите группу:\n" . generateListMessage($groupKeys), 'reply_markup' => $keyboardBackOnly]); break;
            case 'Вывести отстающие группы мышц': if ($currentState !== STATE_DEFAULT) break; $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Функция в разработке."]); break;

            // --- Кнопки меню Записи Тренировки ---
             case 'Добавить упражнение': if ($currentState !== STATE_LOGGING_TRAINING_MENU) break; $userStates[$chatId] = STATE_SELECTING_MUSCLE_GROUP; $userSelections[$chatId] = ['mode' => 'log']; $groupKeys = array_keys($exercises); $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Выберите группу:\n" . generateListMessage($groupKeys), 'reply_markup' => $keyboardBackOnly]); break;
             case 'Завершить запись тренировки': if ($currentState !== STATE_LOGGING_TRAINING_MENU) break; $logCount = isset($currentTrainingLog[$chatId]) ? count($currentTrainingLog[$chatId]) : 0; if ($logCount > 0) { $userStates[$chatId] = STATE_DEFAULT; echo "Завершение $chatId ($logCount упр.): "; print_r($currentTrainingLog[$chatId]); echo "\n"; /* Сохранение лога */ unset($currentTrainingLog[$chatId]); unset($userSelections[$chatId]); $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Тренировка записана ({$logCount} упр.).", 'reply_markup' => $keyboard]); } else { $userStates[$chatId] = STATE_DEFAULT; unset($currentTrainingLog[$chatId]); unset($userSelections[$chatId]); $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Не добавлено упр. Возврат в меню.', 'reply_markup' => $keyboard]); } break;

            // --- Кнопки подменю Питание ---
            case 'Записать прием пищи': $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Вы выбрали 'Записать прием пищи'."]); break;
            case 'Посчитать калории за сегодня': $telegram->sendMessage(['chat_id' => $chatId, 'text' => "Вы выбрали 'Посчитать калории за сегодня'."]); break;

            // --- Кнопка "Назад" (из ГЛАВНЫХ подменю) ---
            case 'Назад': if ($currentState === STATE_LOGGING_TRAINING_MENU) { $userStates[$chatId] = STATE_DEFAULT; unset($currentTrainingLog[$chatId]); unset($userSelections[$chatId]); $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Возврат в меню тренировок.', 'reply_markup' => $keyboardTrainMenu]); } else if ($currentState === STATE_DEFAULT) { $telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Главное меню.', 'reply_markup' => $keyboard]); } break;
        } // Конец switch
    } // Конец if message
} // Конец foreach updates
sleep(1);
 // Конец while true