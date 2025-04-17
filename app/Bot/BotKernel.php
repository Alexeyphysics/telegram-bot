<?php

// app/Bot/BotKernel.php

namespace Bot; // Пространство имен для нашего бота

use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;
use Bot\Constants\States;         // Подключаем класс с константами
use Bot\Keyboard\KeyboardService; // Подключаем сервис клавиатур
use Bot\Service\DataStorageService;

#use Bot\Keyboard\KeyboardHelper; // <-- Добавь эту строку


class BotKernel
{
    private Api $telegram;
    private int $updateId = 0;
    private KeyboardService $keyboardService;
    private DataStorageService $dataStorage;
    // Хранилища данных и состояний
    private array $userStates = [];
    private array $userData = [];
    private array $userProducts = [];
    private array $diaryData = [];
    private array $userSelections = [];
    private array $currentTrainingLog = [];

    // Структура упражнений
    private array $exercises = [];

    

    public function __construct(string $token)
    {
        if (empty($token)) {
            throw new \InvalidArgumentException('Telegram Bot Token is required.');
        }
        $this->telegram = new Api($token);

        // Определяем пути к файлам относительно КОРНЯ ПРОЕКТА
        // __DIR__ указывает на текущую папку (app/Bot), поэтому поднимаемся на 2 уровня
        $basePath = dirname(__DIR__, 2); // Корень проекта
        $storagePath = $basePath . '/storage/bot'; // Путь к папке storage/bot


        // Создаем папку, если ее нет (добавим проверку)
        if (!is_dir($storagePath)) {
            if (!mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
                // Не удалось создать папку, выбрасываем исключение или пишем лог
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $storagePath));
            }
            echo "Created storage directory: {$storagePath}\n";
        }

        $this->keyboardService = new KeyboardService();

        $this->dataStorage = new DataStorageService($storagePath);
        $this->userData = $this->dataStorage->getAllUserData();
        $this->userProducts = $this->dataStorage->getAllUserProducts();
        $this->diaryData = $this->dataStorage->getAllDiaryData();

        $this->loadExercises(); // Загружаем структуру упражнений
        echo "BotKernel Initialized.\n";
    }

    public function run(): void
    {
        echo "Starting Bot Kernel run loop...\n";
        while (true) {
            try {
                $updates = $this->telegram->getUpdates(['offset' => $this->updateId + 1, 'timeout' => 30]);
            } catch (TelegramSDKException $e) {
                echo "Telegram SDK Error: " . $e->getMessage() . "\n";
                sleep(5);
                continue;
            } catch (\Throwable $e) {
                echo "General Error getting updates: " . $e->getMessage() . "\n";
                sleep(10);
                continue;
            }

            foreach ($updates as $update) {
                $this->updateId = $update->getUpdateId();

                if (!$update->isType('message') || $update->getMessage() === null || $update->getMessage()->getChat() === null) {
                    continue;
                }

                $message = $update->getMessage();
                $chatId = $message->getChat()->getId();
                $text = $message->getText() ?? '';

                // Инициализация пользователя
                if (!isset($this->userStates[$chatId])) {
                    $this->userStates[$chatId] = States::DEFAULT;
                }
                if (!isset($this->userSelections[$chatId])) {
                    $this->userSelections[$chatId] = [];
                }
                if (!isset($this->diaryData[$chatId])) {
                    $this->diaryData[$chatId] = [];
                }
                if (!isset($this->userProducts[$chatId])) {
                    $this->userProducts[$chatId] = [];
                }

                echo "Получено сообщение: $text (Chat ID: $chatId), State: " . ($this->userStates[$chatId] ?? States::DEFAULT) . "\n";

                try {
                    // Вызываем метод обработки сообщения
                    $this->handleMessage($chatId, $text, $message); // Передаем объект message для getReplyToMessage
                } catch (\Throwable $e) {
                    echo "Error processing message for chat ID {$chatId}: " . $e->getMessage() . "\n";
                    echo $e->getTraceAsString() . "\n";
                    try {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка.']);
                        $this->userStates[$chatId] = States::DEFAULT;
                        unset($this->userSelections[$chatId]);
                    } catch (\Throwable $ex) {
                        echo "Could not send error message to user {$chatId}.\n";
                    }
                }
            }
            sleep(1);
        }
    }

  
    private function handleMessage(int $chatId, string $text, Message $message): void
    {
        $currentState = $this->userStates[$chatId] ?? States::DEFAULT;
        $currentMode = $this->userSelections[$chatId]['mode'] ?? null; // Для упражнений

        // --- Обработка кнопки Назад ВО ВРЕМЯ выбора/ввода данных ---
        if ($text === 'Назад' && $this->handleBackDuringInput($chatId, $message, $currentState)) {
            return; // Если "Назад" было обработано для состояния ввода, выходим из handleMessage
        }

        // --- Обработка состояний регистрации / Смены аккаунта ---
        if ($currentState >= States::AWAITING_NAME && $currentState <= States::AWAITING_PASSWORD) {
            $this->handleRegistrationState($chatId, $text, $message, $currentState);
            return; // Выходим, т.к. состояние обработано
        }

        // --- Обработка состояний БЖУ ---
        if (($currentState >= States::AWAITING_PRODUCT_NAME_SAVE && $currentState <= States::AWAITING_DELETE_CONFIRMATION) ||
        $currentState === States::AWAITING_PRODUCT_NAME_SEARCH) {
        $this->handleBjuStates($chatId, $text, $message, $currentState);
        return; 
        }

        // --- Обработка состояний Дневника ---
        if ($currentState >= States::AWAITING_ADD_MEAL_OPTION && $currentState <= States::AWAITING_DATE_VIEW_MEAL) {
            $this->handleDiaryStates($chatId, $text, $message, $currentState);
            return; // Выходим, т.к. состояние обработано
        }

        // --- ОБЩАЯ Обработка состояний выбора упражнения ---
        if ($currentState >= States::SELECTING_MUSCLE_GROUP && $currentState <= States::SELECTING_EXERCISE) {
            $this->handleExerciseSelectionState($chatId, $text, $message, $currentState);
            return; // Выходим, т.к. состояние обработано
        }
        
        // --- Обработка состояний ТОЛЬКО для записи (повторы, вес) ---
        if ($currentState === States::AWAITING_REPS || $currentState === States::AWAITING_WEIGHT) {
            $this->handleTrainingLogInputState($chatId, $text, $message, $currentState);
            return; // Выходим, т.к. состояние обработано
        }

        // Сюда дойдет управление, только если состояние не было обработано выше
        $this->handleMenuCommands($chatId, $text, $message, $currentState); // Передаем управление последнему обработчику
    }

    private function findOriginalProductName(int $chatId, string $productNameLower): string
    {
        // Попробуем найти оригинальное имя в текущих данных BotKernel
        if (isset($this->userProducts[$chatId])) {
            // Эта реализация неэффективна и неточна.
            // Лучше хранить отображение lower_name => original_name
            // или хранить объекты с name и lower_name в userProducts.
            // Пока возвращаем просто lower-case имя.
            // TODO: Улучшить хранение и поиск оригинальных имен продуктов.
            return $productNameLower;
            /*
            foreach (array_keys($this->userProducts[$chatId]) as $key) {
                if (mb_strtolower($key) === $productNameLower) {
                     // Это все еще может вернуть не тот регистр.
                     return $key;
                }
            }
            */
        }
        // Возвращаем lower-case, если не нашли
        return $productNameLower;
    }
    
    // --- Остальные приватные методы ---
    private function loadExercises(): void
    {
        // Определяем путь к файлу относительно корня проекта
        $basePath = dirname(__DIR__, 2); // Корень проекта
        // УКАЖИ ПРАВИЛЬНЫЙ ПУТЬ К ТВОЕМУ ФАЙЛУ exercises.php!
        // Пример, если файл лежит в корне:
        $exercisesPath = $basePath . '/config/exercises.php';
        // Пример, если файл лежит в папке config:
        // $exercisesPath = $basePath . '/config/exercises.php';

        if (file_exists($exercisesPath)) {
            // Используем require для загрузки и получения возвращаемого массива
            $loadedExercises = require $exercisesPath;

            // Проверяем, что загрузился именно массив
            if (is_array($loadedExercises)) {
                $this->exercises = $loadedExercises; // Присваиваем свойству класса
                echo "Exercises structure loaded from file: {$exercisesPath}\n";
            } else {
                echo "Warning: File {$exercisesPath} did not return an array.\n";
                $this->exercises = []; // Устанавливаем пустой массив при ошибке
            }
        } else {
            echo "Warning: Exercises file not found at {$exercisesPath}\n";
            $this->exercises = []; // Устанавливаем пустой массив, если файл не найден
        }
    }

    private function generateListMessage(array $items): string
    {
        $message = "";
        foreach ($items as $index => $item) {
            $message .= ($index + 1) . ". " . $item . "\n";
        }
        return rtrim($message);
    }

    /**
     * Обрабатывает нажатие кнопки "Назад" ВО ВРЕМЯ многошагового ввода.
     * Возвращает true, если "Назад" было обработано для состояния ввода, иначе false.
     */
    private function handleBackDuringInput(int $chatId, Message $message, int $currentState): bool
    {
        $currentMode = $this->userSelections[$chatId]['mode'] ?? null;

        // --- Упражнения ---
        if ($currentState >= States::SELECTING_MUSCLE_GROUP && $currentState <= States::AWAITING_WEIGHT) {
            $returnState = ($currentMode === 'log') ? States::LOGGING_TRAINING_MENU : States::DEFAULT;
            $returnKeyboard = ($currentMode === 'log') ? $this->keyboardService->makeAddExerciseMenu() : $this->keyboardService->makeTrainingMenu();
            $cancelMessage = ($currentMode === 'log') ? 'Добавление упражнения отменено.' : 'Просмотр прогресса отменен.';

            switch ($currentState) {
                case States::SELECTING_MUSCLE_GROUP:
                    $this->userStates[$chatId] = $returnState; unset($this->userSelections[$chatId]);
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $cancelMessage, 'reply_markup' => $returnKeyboard ]);
                    break;
                case States::SELECTING_EXERCISE_TYPE:
                    $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP; unset($this->userSelections[$chatId]['group']);
                    $groupKeys = array_keys($this->exercises);
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Выберите группу:\n" . $this->generateListMessage($groupKeys), 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                    break;
                case States::SELECTING_EXERCISE:
                    $this->userStates[$chatId] = States::SELECTING_EXERCISE_TYPE; $group = $this->userSelections[$chatId]['group'] ?? '???'; unset($this->userSelections[$chatId]['type']);
                    $typeKeys = isset($this->exercises[$group]) ? array_keys($this->exercises[$group]) : [];
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Группа: {$group}\nВыберите тип:\n" . $this->generateListMessage($typeKeys), 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                    break;
                case States::AWAITING_REPS:
                    $this->userStates[$chatId] = States::SELECTING_EXERCISE; $group = $this->userSelections[$chatId]['group'] ?? '???'; $type = $this->userSelections[$chatId]['type'] ?? '???'; unset($this->userSelections[$chatId]['exercise']);
                    $exerciseList = isset($this->exercises[$group][$type]) ? $this->exercises[$group][$type] : [];
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Тип: {$type}\nВыберите упр.:\n" . $this->generateListMessage($exerciseList), 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                    break;
                case States::AWAITING_WEIGHT:
                    $this->userStates[$chatId] = States::AWAITING_REPS; unset($this->userSelections[$chatId]['reps']); $exercise = $this->userSelections[$chatId]['exercise'] ?? '???';
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Упражнение: {$exercise}\nПовторения:", 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                    break;
            }
            return true; // "Назад" обработано для упражнений
        }

        // --- БЖУ Продукты ---
        // Состояния: ввод имени/бжу, подтверждение сохранения,
        // ввод НОМЕРА удаления, подтверждение удаления, ввод имени для поиска
        if (($currentState >= States::AWAITING_PRODUCT_NAME_SAVE && $currentState <= States::AWAITING_SAVE_CONFIRMATION) ||
            $currentState === States::AWAITING_PRODUCT_NUMBER_DELETE || // <-- Учтено новое состояние
            $currentState === States::AWAITING_DELETE_CONFIRMATION ||
            $currentState === States::AWAITING_PRODUCT_NAME_SEARCH)
        {
            $this->userStates[$chatId] = States::BJU_MENU;
            unset($this->userSelections[$chatId]['bju_product']);
            unset($this->userSelections[$chatId]['bju_product_to_delete']);
            unset($this->userSelections[$chatId]['products_for_delete']); // <-- Очищаем новый ключ
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Действие отменено. Меню БЖУ:', 'reply_markup' => $this->keyboardService->makeBjuMenu() ]);
            return true; // "Назад" обработано для БЖУ
        }

        // --- Дневник ---
        // Состояния: ввод опции, поиск, ввод грамм/имени/бжу, подтверждение добавления,
        // ввод даты удаления, ввод НОМЕРА удаления, подтверждение удаления, ввод даты просмотра
        if (($currentState >= States::AWAITING_ADD_MEAL_OPTION && $currentState <= States::AWAITING_ADD_MEAL_CONFIRM_MANUAL && $currentState != States::DIARY_MENU) ||
            $currentState === States::AWAITING_DATE_DELETE_MEAL ||
            $currentState === States::AWAITING_MEAL_NUMBER_DELETE || // <-- Учтено новое состояние
            $currentState === States::AWAITING_DELETE_MEAL_CONFIRM ||
            $currentState === States::AWAITING_DATE_VIEW_MEAL)
        {
            $previousState = States::DEFAULT; $previousKeyboard = $this->keyboardService->makeMainMenu(); $messageText = 'Возврат в главное меню.';

            // Логика возврата на предыдущий шаг
            if ($currentState === States::AWAITING_ADD_MEAL_OPTION) {
                $previousState = States::DIARY_MENU; $previousKeyboard = $this->keyboardService->makeDiaryMenu(); $messageText = 'Возврат в меню Дневника.';
            } elseif ($currentState === States::AWAITING_SEARCH_PRODUCT_NAME_ADD || $currentState === States::AWAITING_GRAMS_MANUAL_ADD) {
                $previousState = States::AWAITING_ADD_MEAL_OPTION; $previousKeyboard = $this->keyboardService->makeAddMealOptionsMenu(); $messageText = 'Выберите способ добавления.';
            } elseif ($currentState === States::AWAITING_GRAMS_SEARCH_ADD) {
                $previousState = States::AWAITING_SEARCH_PRODUCT_NAME_ADD; $previousKeyboard = $this->keyboardService->makeBackOnly(); $messageText = 'Название продукта из сохраненных:';
                unset($this->userSelections[$chatId]['diary_entry']['search_name_lower'], $this->userSelections[$chatId]['diary_entry']['search_name_original']);
            } elseif ($currentState === States::AWAITING_PRODUCT_NAME_MANUAL_ADD) {
                $previousState = States::AWAITING_GRAMS_MANUAL_ADD; $previousKeyboard = $this->keyboardService->makeBackOnly(); $messageText = 'Масса съеденного (г):';
                unset($this->userSelections[$chatId]['diary_entry']['grams']);
            // Условие изменено, убрано AWAITING_KCAL_MANUAL_ADD
            } elseif ($currentState >= States::AWAITING_PROTEIN_MANUAL_ADD && $currentState <= States::AWAITING_CARBS_MANUAL_ADD) {
                $previousState = $currentState - 1;
                $promptKey = match ($previousState) { /* ... */ States::AWAITING_CARBS_MANUAL_ADD => 'fat', default => null };
                $prevValue = $this->userSelections[$chatId]['diary_entry'][$promptKey] ?? '?';
                $messageText = match ($previousState) { /* ... */ States::AWAITING_CARBS_MANUAL_ADD => "Жиры: {$prevValue}г\nУглеводы(г):", default => 'Введите пред. значение:' };
                $keyToRemove = match ($currentState) { /* ... */ States::AWAITING_CARBS_MANUAL_ADD => 'fat', default => null };
                if ($keyToRemove && isset($this->userSelections[$chatId]['diary_entry'])) { unset($this->userSelections[$chatId]['diary_entry'][$keyToRemove]); }
                $previousKeyboard = $this->keyboardService->makeBackOnly();
            } elseif ($currentState === States::AWAITING_ADD_MEAL_CONFIRM_SEARCH || $currentState === States::AWAITING_ADD_MEAL_CONFIRM_MANUAL) {
                $previousState = States::AWAITING_ADD_MEAL_OPTION; $previousKeyboard = $this->keyboardService->makeAddMealOptionsMenu(); $messageText = 'Запись отменена.';
                unset($this->userSelections[$chatId]['diary_entry']);
            } elseif ($currentState === States::AWAITING_DATE_DELETE_MEAL || $currentState === States::AWAITING_DATE_VIEW_MEAL) {
                $previousState = States::DIARY_MENU; $previousKeyboard = $this->keyboardService->makeDiaryMenu(); $messageText = 'Возврат в меню Дневника.';
            // --- ДОБАВЛЕНА ОБРАБОТКА НОВОГО СОСТОЯНИЯ ---
            } elseif ($currentState === States::AWAITING_MEAL_NUMBER_DELETE) {
                // Возвращаемся в меню Дневника при отмене выбора номера
                $previousState = States::DIARY_MENU;
                $previousKeyboard = $this->keyboardService->makeDiaryMenu();
                $messageText = 'Удаление отменено. Возврат в меню Дневника.';
                unset($this->userSelections[$chatId]['diary_delete']); // Очищаем все данные для удаления
            // --- КОНЕЦ ДОБАВЛЕНИЯ ---
            } elseif ($currentState === States::AWAITING_DELETE_MEAL_CONFIRM) {
                $previousState = States::DIARY_MENU; $previousKeyboard = $this->keyboardService->makeDiaryMenu(); $messageText = 'Удаление отменено.';
                unset($this->userSelections[$chatId]['diary_delete']);
            }

            $this->userStates[$chatId] = $previousState;
            // Очистка при отмене подтверждений (остается)
            if (in_array($currentState, [ States::AWAITING_ADD_MEAL_CONFIRM_MANUAL, States::AWAITING_ADD_MEAL_CONFIRM_SEARCH, States::AWAITING_DELETE_MEAL_CONFIRM ])) {
                unset($this->userSelections[$chatId]['diary_entry']);
                unset($this->userSelections[$chatId]['diary_delete']);
            }

            $this->telegram->sendMessage([ 'chat_id' => $chatId, 'text' => $messageText, 'reply_markup' => $previousKeyboard ]);
            return true; // "Назад" обработано для Дневника
        }

        // Если ни одно из условий выше не сработало
        return false;
    }

    /**
     * Обрабатывает состояния регистрации или смены аккаунта.
     */
    private function handleRegistrationState(int $chatId, string $text, Message $message, int $currentState): void
    {
        // --- Обработка состояний регистрации / Смены аккаунта ---
        if ($currentState >= States::AWAITING_NAME && $currentState <= States::AWAITING_PASSWORD) {
            if ($currentState === States::AWAITING_NAME) {
                if ($text === 'Назад') { // Специальная обработка "Назад" для смены аккаунта
                    $this->userStates[$chatId] = States::DEFAULT; // Возврат в главное меню (или ACCOUNT_MENU, если его добавим)
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Смена аккаунта отменена.',
                        'reply_markup' => $this->keyboardService->makeAccountMenu() // Показываем меню аккаунта
                    ]);
                }  
                else {
                    $trimmedName = trim($text);
                    if (empty($trimmedName)) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Имя не может быть пустым. Пожалуйста, введите ваше имя:',
                            // Клавиатуру не показываем, т.к. ждем ввод текста
                            'reply_markup' => $this->keyboardService->removeKeyboard() // Убираем если была
                        ]);
                        // Остаемся в том же состоянии AWAITING_NAME
                    } else {
                        // ---> КОНЕЦ ПРОВЕРКИ <---
                        if (!isset($this->userData[$chatId])) { $this->userData[$chatId] = []; }
                        $this->userData[$chatId]['name'] = $trimmedName; // Используем обрезанное имя
                        $this->userStates[$chatId] = States::AWAITING_EMAIL;
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId, 'text' => 'Почта:', 'reply_markup' => $this->keyboardService->removeKeyboard()
                        ]);
                }
            }
            } elseif ($currentState === States::AWAITING_EMAIL) {
                $this->userData[$chatId]['email'] = $text;
                $this->userStates[$chatId] = States::AWAITING_PASSWORD;
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Пароль:'
                ]);
            } elseif ($currentState === States::AWAITING_PASSWORD) {
                $hashedPassword = password_hash($text, PASSWORD_DEFAULT);
                if ($hashedPassword === false) {
                    echo "Pwd hash failed for {$chatId}!\n";
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Ошибка регистрации/смены аккаунта.',
                        'reply_markup' => $this->keyboardService->makeMainMenu()
                    ]);
                    $this->userStates[$chatId] = States::DEFAULT;
                    // Не удаляем userData, если это была смена, чтобы не потерять имя/почту
                } else {
                    $this->userData[$chatId]['password'] = $hashedPassword;
                    $this->userStates[$chatId] = States::DEFAULT;
                    $name = $this->userData[$chatId]['name'] ?? '?';
                    echo "Сохр. {$chatId}: Name={$name}, Email={$this->userData[$chatId]['email']}, PwdHash={$hashedPassword}\n";
                    $this->dataStorage->saveAllUserData($this->userData);
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Готово, {$name}!",
                        'reply_markup' => $this->keyboardService->makeMainMenu()
                    ]);
                }
            }
            return;
        }
    }

    /**
     * Обрабатывает состояния, связанные с управлением БЖУ продуктов.
     */
    private function handleBjuStates(int $chatId, string $text, Message $message, int $currentState): void
    {
        // --- Обработка состояний БЖУ ---
        if (($currentState >= States::AWAITING_PRODUCT_NAME_SAVE && $currentState <= States::AWAITING_DELETE_CONFIRMATION) ||
            $currentState === States::AWAITING_PRODUCT_NAME_SEARCH) {
            switch ($currentState) {
                case States::AWAITING_PRODUCT_NAME_SAVE:
                    // ---> ИЗМЕНЕНА ПРОВЕРКА С УЧЕТОМ trim() <---
                    $productName = trim($text); // Обрезаем пробелы сразу
                    if (empty($productName)) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Название продукта не может быть пустым. Введите снова или "Назад".',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    } else {
                    // ---> КОНЕЦ ИЗМЕНЕНИЯ <---
                        $this->userSelections[$chatId]['bju_product'] = ['name' => $productName]; // Сохраняем обрезанное имя
                        $this->userStates[$chatId] = States::AWAITING_PRODUCT_PROTEIN;
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId, 'text' => "Назв: {$productName}\nБелки(г/100г):", 'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    }
                break;
                case States::AWAITING_PRODUCT_PROTEIN:
                     if (!is_numeric($text) || $text < 0 || $text > 100) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Некорректно. Введите число от 0 до 100 (белки г/100г) или "Назад".',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                     } else {
                         $this->userSelections[$chatId]['bju_product']['protein'] = (float)$text;
                         $this->userStates[$chatId] = States::AWAITING_PRODUCT_FAT;
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => "Белки: {$text}г\nЖиры(г/100г):",
                             'reply_markup' => $this->keyboardService->makeBackOnly()
                         ]);
                     }
                     break;
                 case States::AWAITING_PRODUCT_FAT:
                    if (!is_numeric($text) || $text < 0 || $text > 100) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Некорректно. Введите число от 0 до 100 (жиры г/100г) или "Назад".',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    } else {
                        $this->userSelections[$chatId]['bju_product']['fat'] = (float)$text;
                        $this->userStates[$chatId] = States::AWAITING_PRODUCT_CARBS;
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Жиры: {$text}г\nУглеводы(г/100г):",
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    }
                    break;
                    case States::AWAITING_PRODUCT_CARBS:
                        if (!is_numeric($text) || $text < 0 || $text > 100) {
                             $this->telegram->sendMessage([
                                 'chat_id' => $chatId,
                                 'text' => 'Некорректно. Введите число от 0 до 100 (углеводы г/100г) или "Назад".',
                                 'reply_markup' => $this->keyboardService->makeBackOnly()
                             ]);
                        } else {
                            $this->userSelections[$chatId]['bju_product']['carbs'] = (float)$text;
            
                            // ---> ДОБАВЛЕНО: Расчет калорий <---
                            $p = $this->userSelections[$chatId]['bju_product']['protein'] ?? 0;
                            $f = $this->userSelections[$chatId]['bju_product']['fat'] ?? 0;
                            $c = (float)$text;
                            $kcal = round($p * 4 + $f * 9 + $c * 4);
                            $this->userSelections[$chatId]['bju_product']['kcal'] = $kcal;
                            $this->userStates[$chatId] = States::AWAITING_SAVE_CONFIRMATION;
                            $pData = $this->userSelections[$chatId]['bju_product']; // Уже содержит kcal
                            $confirmMsg = "Сохранить продукт?\nНазвание: {$pData['name']}\nНа 100г:\nБ:{$pData['protein']} Ж:{$pData['fat']} У:{$pData['carbs']} К:{$pData['kcal']} (расчет.)"; // Добавил (расчет.)
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => $confirmMsg,
                                'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                            ]);
                        }
                    break; // Не забываем break
                case States::AWAITING_SAVE_CONFIRMATION:
                    if ($text === 'Да') {
                        $pData = $this->userSelections[$chatId]['bju_product'] ?? null;
                        if ($pData && isset($pData['name'])) {
                            $productNameLower = mb_strtolower($pData['name']);
                            if (!isset($this->userProducts[$chatId])) {
                                $this->userProducts[$chatId] = [];
                            }
                            $this->userProducts[$chatId][$productNameLower] = [
                                $pData['protein'],
                                $pData['fat'],
                                $pData['carbs'],
                                $pData['kcal']
                            ];
                            $this->dataStorage->saveAllUserProducts($this->userProducts);
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Продукт '{$pData['name']}' сохранен!",
                                'reply_markup' => $this->keyboardService->makeBjuMenu()
                            ]);
                        } else {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => 'Ошибка: Не удалось получить данные продукта для сохранения.',
                                'reply_markup' => $this->keyboardService->makeBjuMenu()
                            ]);
                        }
                        $this->userStates[$chatId] = States::BJU_MENU; // Возвращаемся в меню БЖУ
                        unset($this->userSelections[$chatId]['bju_product']);
                    } elseif ($text === 'Нет') {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Сохранение отменено.',
                            'reply_markup' => $this->keyboardService->makeBjuMenu()
                        ]);
                        $this->userStates[$chatId] = States::BJU_MENU;
                        unset($this->userSelections[$chatId]['bju_product']);
                    } else {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Пожалуйста, нажмите "Да" или "Нет".',
                            'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                        ]);
                    }
                    break;
                case States::AWAITING_PRODUCT_NUMBER_DELETE:
                        $productsForSelection = $this->userSelections[$chatId]['products_for_delete'] ?? null;
            
                        // Проверяем, что временные данные существуют
                        if (!$productsForSelection) {
                             $this->telegram->sendMessage([
                                 'chat_id' => $chatId,
                                 'text' => 'Произошла ошибка при выборе продукта. Пожалуйста, вернитесь в меню БЖУ и попробуйте снова.',
                                 'reply_markup' => $this->keyboardService->makeBjuMenu()
                             ]);
                             $this->userStates[$chatId] = States::BJU_MENU;
                             unset($this->userSelections[$chatId]['products_for_delete']);
                             break; // Выход из switch
                        }
            
                        // Проверяем, что введен номер и он корректный
                        if (!ctype_digit($text) || !isset($productsForSelection[(int)$text])) {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => 'Неверный номер. Пожалуйста, введите номер из списка или "Назад".',
                                'reply_markup' => $this->keyboardService->makeBackOnly()
                            ]);
                            // Состояние не меняем, даем еще попытку
                        } else {
                            $numberToDelete = (int)$text;
                            $productNameToDeleteLower = $productsForSelection[$numberToDelete]; // Получаем ключ (имя в lower case)
            
                            // Проверяем, существует ли еще этот продукт (на всякий случай)
                            if (!isset($this->userProducts[$chatId][$productNameToDeleteLower])) {
                                 $this->telegram->sendMessage([
                                    'chat_id' => $chatId,
                                    'text' => 'Ошибка: Выбранный продукт уже удален или не найден. Попробуйте снова.',
                                    'reply_markup' => $this->keyboardService->makeBjuMenu() // Возврат в меню
                                 ]);
                                 $this->userStates[$chatId] = States::BJU_MENU;
                                 unset($this->userSelections[$chatId]['products_for_delete']);
                            } else {
                                // Получаем данные для подтверждения
                                $pData = $this->userProducts[$chatId][$productNameToDeleteLower];
                                $originalName = $this->findOriginalProductName($chatId, $productNameToDeleteLower);
            
                                // Сохраняем ключ (lower case) для подтверждения
                                $this->userSelections[$chatId]['bju_product_to_delete'] = $productNameToDeleteLower;
                                 // Очищаем список для выбора, он больше не нужен
                                unset($this->userSelections[$chatId]['products_for_delete']);
            
                                // Переходим к подтверждению
                                $this->userStates[$chatId] = States::AWAITING_DELETE_CONFIRMATION;
                                $confirmMsg = "Удалить продукт №{$numberToDelete}?\n{$originalName}\nБ: {$pData[0]} Ж: {$pData[1]} У: {$pData[2]} К: {$pData[3]}";
                                $this->telegram->sendMessage([
                                    'chat_id' => $chatId,
                                    'text' => $confirmMsg,
                                    'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                                ]);
                            }
                        }
                    break;
                case States::AWAITING_DELETE_CONFIRMATION:
                     if ($text === 'Да') {
                         $productNameToDeleteLower = $this->userSelections[$chatId]['bju_product_to_delete'] ?? null;
                         if ($productNameToDeleteLower && isset($this->userProducts[$chatId][$productNameToDeleteLower])) {
                             $originalName = $this->findOriginalProductName($chatId, $productNameToDeleteLower);
                             unset($this->userProducts[$chatId][$productNameToDeleteLower]);
                             $this->dataStorage->saveAllUserProducts($this->userProducts);
                             $this->telegram->sendMessage([
                                 'chat_id' => $chatId,
                                 'text' => "Продукт '{$originalName}' удален.",
                                 'reply_markup' => $this->keyboardService->makeBjuMenu()
                             ]);
                         } else {
                             $this->telegram->sendMessage([
                                 'chat_id' => $chatId,
                                 'text' => 'Ошибка: Не удалось найти продукт для удаления.',
                                 'reply_markup' => $this->keyboardService->makeBjuMenu()
                             ]);
                         }
                         $this->userStates[$chatId] = States::BJU_MENU;
                         unset($this->userSelections[$chatId]['bju_product_to_delete']);
                     } elseif ($text === 'Нет') {
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => 'Удаление отменено.',
                             'reply_markup' => $this->keyboardService->makeBjuMenu()
                         ]);
                         $this->userStates[$chatId] = States::BJU_MENU;
                         unset($this->userSelections[$chatId]['bju_product_to_delete']);
                     } else {
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => 'Пожалуйста, нажмите "Да" или "Нет".',
                             'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                         ]);
                     }
                    break;
                case States::AWAITING_PRODUCT_NAME_SEARCH:
                    $productNameLower = trim(mb_strtolower($text));
                    if (isset($this->userProducts[$chatId][$productNameLower])) {
                        $pData = $this->userProducts[$chatId][$productNameLower];
                        $originalName = $this->findOriginalProductName($chatId, $productNameLower);
                        $resultMsg = "Найден: {$originalName}\nБ: {$pData[0]} Ж: {$pData[1]} У: {$pData[2]} К: {$pData[3]}";
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => $resultMsg,
                            'reply_markup' => $this->keyboardService->makeBjuMenu()
                        ]);
                    } else {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Продукт '{$text}' не найден.",
                            'reply_markup' => $this->keyboardService->makeBjuMenu()
                        ]);
                    }
                    $this->userStates[$chatId] = States::BJU_MENU; // Возвращаемся в меню БЖУ
                    break;
            }
            return;
        }
    }

    /**
     * Обрабатывает состояния, связанные с ведением дневника питания.
     */
    private function handleDiaryStates(int $chatId, string $text, Message $message, int $currentState): void
    {
        switch ($currentState) {
            // --- Добавление приема пищи ---
            case States::AWAITING_ADD_MEAL_OPTION:
                if ($text === 'Поиск в базе знаний') {
                    if (empty($this->userProducts[$chatId])) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Сначала сохраните продукты в "БЖУ продуктов".',
                            'reply_markup' => $this->keyboardService->makeAddMealOptionsMenu()
                        ]);
                    } else {
                        $this->userStates[$chatId] = States::AWAITING_SEARCH_PRODUCT_NAME_ADD;
                        $this->userSelections[$chatId]['diary_entry'] = ['date' => date('Y-m-d')];
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Название продукта из сохраненных (или "Назад"):',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    }
                } elseif ($text === 'Записать БЖУ самому') {
                    $this->userStates[$chatId] = States::AWAITING_GRAMS_MANUAL_ADD;
                    $this->userSelections[$chatId]['diary_entry'] = ['date' => date('Y-m-d')];
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Масса съеденного (г) (или "Назад"):',
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Используйте кнопки.',
                        'reply_markup' => $this->keyboardService->makeAddMealOptionsMenu()
                    ]);
                }
                break;

            case States::AWAITING_SEARCH_PRODUCT_NAME_ADD:
                $productNameLower = trim(mb_strtolower($text));
                if (isset($this->userProducts[$chatId][$productNameLower])) {
                    $originalName = $this->findOriginalProductName($chatId, $productNameLower);
                    $this->userSelections[$chatId]['diary_entry']['search_name_lower'] = $productNameLower;
                    $this->userSelections[$chatId]['diary_entry']['search_name_original'] = $originalName;
                    $this->userStates[$chatId] = States::AWAITING_GRAMS_SEARCH_ADD;
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Продукт '{$originalName}' найден.\nМасса (г):",
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Продукт '{$text}' не найден в ваших сохраненных. Попробуйте снова или 'Назад'.",
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                }
                break;

            case States::AWAITING_GRAMS_SEARCH_ADD:
                if (!is_numeric($text) || $text <= 0 || $text > 5000) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Некорректно. Введите вес порции в граммах (больше 0 и не более 5000) или "Назад".',
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                } else {
                    $grams = (float)$text;
                    $productNameLower = $this->userSelections[$chatId]['diary_entry']['search_name_lower'];
                    $originalName = $this->userSelections[$chatId]['diary_entry']['search_name_original'];
                    $baseBJU = $this->userProducts[$chatId][$productNameLower];
                    $p = round($baseBJU[0] * $grams / 100, 1);
                    $f = round($baseBJU[1] * $grams / 100, 1);
                    $c = round($baseBJU[2] * $grams / 100, 1);
                    $kcal = round($baseBJU[3] * $grams / 100, 0);
                    $this->userSelections[$chatId]['diary_entry']['log'] = [
                        'name' => $originalName, 'grams' => $grams, 'p' => $p, 'f' => $f, 'c' => $c, 'kcal' => $kcal
                    ];
                    $this->userStates[$chatId] = States::AWAITING_ADD_MEAL_CONFIRM_SEARCH;
                    $confirmMsg = "Добавить в дневник?\n{$originalName} - {$grams} г\nБ: {$p} Ж: {$f} У: {$c} К: {$kcal}";
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId, 'text' => $confirmMsg, 'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                    ]);
                }
                break;

            case States::AWAITING_ADD_MEAL_CONFIRM_SEARCH:
                if ($text === 'Да') {
                    $logData = $this->userSelections[$chatId]['diary_entry']['log'] ?? null;
                    $logDate = $this->userSelections[$chatId]['diary_entry']['date'] ?? date('Y-m-d');
                    if ($logData) {
                        if (!isset($this->diaryData[$chatId])) { $this->diaryData[$chatId] = []; }
                        if (!isset($this->diaryData[$chatId][$logDate])) { $this->diaryData[$chatId][$logDate] = []; }
                        $this->diaryData[$chatId][$logDate][] = $logData;
                        $this->dataStorage->saveAllDiaryData($this->diaryData);
                        $formattedDate = date('d.m.Y', strtotime($logDate));
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "'{$logData['name']}' ({$logData['grams']}г) записано на {$formattedDate}.",
                            'reply_markup' => $this->keyboardService->makeDiaryMenu()
                        ]);
                    } else {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Не удалось получить данные для записи.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    }
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_entry']);
                } elseif ($text === 'Нет') {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Запись отменена.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_entry']);
                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Нажмите "Да" или "Нет".', 'reply_markup' => $this->keyboardService->makeConfirmYesNo() ]);
                }
                break;

            case States::AWAITING_GRAMS_MANUAL_ADD:
                if (!is_numeric($text) || $text <= 0 || $text > 5000) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректно. Введите вес порции в граммах (больше 0 и не более 5000) или "Назад".', 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                } else {
                    $this->userSelections[$chatId]['diary_entry']['grams'] = (float)$text;
                    $this->userStates[$chatId] = States::AWAITING_PRODUCT_NAME_MANUAL_ADD;
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Граммы: {$text}г\nНазвание продукта:", 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                }
                break;

            case States::AWAITING_PRODUCT_NAME_MANUAL_ADD:
                // ---> ИЗМЕНЕНА ПРОВЕРКА С УЧЕТОМ trim() <---
                $productName = trim($text); // Обрезаем пробелы сразу
                if (empty($productName)) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Название продукта не может быть пустым. Введите снова или "Назад".',
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                } else {
                // ---> КОНЕЦ ИЗМЕНЕНИЯ <---
                    $this->userSelections[$chatId]['diary_entry']['name'] = $productName; // Сохраняем обрезанное имя
                    $this->userStates[$chatId] = States::AWAITING_PROTEIN_MANUAL_ADD;
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId, 'text' => "Название: {$productName}\nБелки(г) в порции:", 'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                }
                break;
            case States::AWAITING_PROTEIN_MANUAL_ADD:
                if (!is_numeric($text) || $text < 0 || $text > 1000) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректно. Введите кол-во белков в порции (0-1000 г) или "Назад".', 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                } else {
                    $this->userSelections[$chatId]['diary_entry']['p'] = (float)$text;
                    $this->userStates[$chatId] = States::AWAITING_FAT_MANUAL_ADD;
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Белки: {$text}г\nЖиры(г) в порции:", 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                }
                break;

            case States::AWAITING_FAT_MANUAL_ADD:
                if (!is_numeric($text) || $text < 0 || $text > 1000) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректно. Введите кол-во жиров в порции (0-1000 г) или "Назад".', 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                } else {
                    $this->userSelections[$chatId]['diary_entry']['f'] = (float)$text;
                    $this->userStates[$chatId] = States::AWAITING_CARBS_MANUAL_ADD;
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Жиры: {$text}г\nУглеводы(г) в порции:", 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                }
                break;

            case States::AWAITING_CARBS_MANUAL_ADD:
                if (!is_numeric($text) || $text < 0 || $text > 1000) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректно. Введите кол-во углеводов в порции (0-1000 г) или "Назад".', 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                } else {
                    $this->userSelections[$chatId]['diary_entry']['c'] = (float)$text;
                    // Расчет калорий
                    $p = $this->userSelections[$chatId]['diary_entry']['p'] ?? 0;
                    $f = $this->userSelections[$chatId]['diary_entry']['f'] ?? 0;
                    $c = (float)$text;
                    $kcal = round($p * 4 + $f * 9 + $c * 4);
                    $this->userSelections[$chatId]['diary_entry']['kcal'] = $kcal;
                    // Переход на подтверждение
                    $this->userStates[$chatId] = States::AWAITING_ADD_MEAL_CONFIRM_MANUAL;
                    $dData = $this->userSelections[$chatId]['diary_entry'];
                    $confirmMsg = "Добавить в дневник?\n{$dData['name']} - {$dData['grams']} г\nБ: {$dData['p']} Ж: {$dData['f']} У: {$dData['c']} К: {$dData['kcal']} (расчет.)";
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $confirmMsg, 'reply_markup' => $this->keyboardService->makeConfirmYesNo() ]);
                }
                break;

            case States::AWAITING_ADD_MEAL_CONFIRM_MANUAL:
                if ($text === 'Да') {
                    $logData = $this->userSelections[$chatId]['diary_entry'] ?? null;
                    $logDate = $logData['date'] ?? date('Y-m-d');
                    if ($logData && isset($logData['name']) && isset($logData['grams'])) {
                        unset($logData['date']);
                        if (!isset($this->diaryData[$chatId])) { $this->diaryData[$chatId] = []; }
                        if (!isset($this->diaryData[$chatId][$logDate])) { $this->diaryData[$chatId][$logDate] = []; }
                        $this->diaryData[$chatId][$logDate][] = $logData;
                        $this->dataStorage->saveAllDiaryData($this->diaryData);
                        $formattedDate = date('d.m.Y', strtotime($logDate));
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "'{$logData['name']}' ({$logData['grams']}г) записано на {$formattedDate}.",
                            'reply_markup' => $this->keyboardService->makeDiaryMenu()
                        ]);
                    } else {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Не удалось получить данные для записи.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    }
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_entry']);
                } elseif ($text === 'Нет') {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Запись отменена.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_entry']);
                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Нажмите "Да" или "Нет".', 'reply_markup' => $this->keyboardService->makeConfirmYesNo() ]);
                }
                break;

            // --- Удаление приема пищи ---
            case States::AWAITING_DATE_DELETE_MEAL:
                $dateToDelete = null;
                $normalizedText = strtolower(trim($text));
                if ($normalizedText === 'вчера') { $dateToDelete = date('Y-m-d', strtotime('-1 day')); }
                elseif ($normalizedText === 'сегодня') { $dateToDelete = date('Y-m-d'); }
                elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $text, $matches)) {
                    if (checkdate($matches[2], $matches[1], $matches[3])) { $dateToDelete = "{$matches[3]}-{$matches[2]}-{$matches[1]}"; }
                }

                if (!$dateToDelete) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректный формат даты. Введите ДД.ММ.ГГГГ, "сегодня" или "вчера", или "Назад".', 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                    break;
                }

                $formattedDate = date('d.m.Y', strtotime($dateToDelete));
                if (empty($this->diaryData[$chatId][$dateToDelete])) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Нет записей за {$formattedDate}. Возврат в меню Дневника.", 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    break;
                }

                // Выводим список и ждем номер
                $mealListMsg = "Приемы пищи за {$formattedDate}:\n\n";
                $i = 1;
                $mealsForSelection = [];
                foreach ($this->diaryData[$chatId][$dateToDelete] as $index => $entry) {
                    $mealListMsg .= sprintf("%d. %s (%g г) - Б:%g Ж:%g У:%g К:%g\n",
                        $i, $entry['name'], $entry['grams'], $entry['p'], $entry['f'], $entry['c'], $entry['kcal']
                    );
                    $mealsForSelection[$i] = $index;
                    $i++;
                }
                $mealListMsg .= "\nВведите номер приема пищи для удаления (или 'Назад'):";

                $this->userSelections[$chatId]['diary_delete'] = [
                    'date' => $dateToDelete,
                    'meal_indices' => $mealsForSelection
                ];
                $this->userStates[$chatId] = States::AWAITING_MEAL_NUMBER_DELETE; // Новое состояние

                $this->telegram->sendMessage([
                    'chat_id' => $chatId, 'text' => rtrim($mealListMsg), 'reply_markup' => $this->keyboardService->makeBackOnly()
                ]);
                break;

            // case States::AWAITING_PRODUCT_NAME_DELETE_MEAL: // --- УДАЛЕНО ---
            // case States::AWAITING_GRAMS_DELETE_MEAL:       // --- УДАЛЕНО ---

            case States::AWAITING_MEAL_NUMBER_DELETE: // <-- НОВОЕ СОСТОЯНИЕ
                $deleteInfo = $this->userSelections[$chatId]['diary_delete'] ?? null;
                $mealIndices = $deleteInfo['meal_indices'] ?? null;
                $dateToDelete = $deleteInfo['date'] ?? null;

                if (!$mealIndices || !$dateToDelete) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка. Попробуйте удалить снова.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_delete']); break;
                }

                if (!ctype_digit($text) || !isset($mealIndices[(int)$text])) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Неверный номер. Введите номер из списка или "Назад".', 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                } else {
                    $numberToDelete = (int)$text;
                    $indexToDelete = $mealIndices[$numberToDelete];

                    if (!isset($this->diaryData[$chatId][$dateToDelete][$indexToDelete])) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Выбранная запись уже удалена или не найдена.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                        $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_delete']);
                    } else {
                        $entryToDelete = $this->diaryData[$chatId][$dateToDelete][$indexToDelete];
                        $this->userSelections[$chatId]['diary_delete'] = [ // Перезаписываем, сохраняя только нужное
                            'date' => $dateToDelete,
                            'index' => $indexToDelete,
                            'entry' => $entryToDelete
                        ];
                        $this->userStates[$chatId] = States::AWAITING_DELETE_MEAL_CONFIRM;
                        $formattedDate = date('d.m.Y', strtotime($dateToDelete));
                        $confirmMsg = "Удалить запись №{$numberToDelete}?\n{$formattedDate}: {$entryToDelete['name']} {$entryToDelete['grams']}г (Б:{$entryToDelete['p']} Ж:{$entryToDelete['f']} У:{$entryToDelete['c']} К:{$entryToDelete['kcal']})";
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $confirmMsg, 'reply_markup' => $this->keyboardService->makeConfirmYesNo() ]);
                    }
                }
                break;

            case States::AWAITING_DELETE_MEAL_CONFIRM:
                if ($text === 'Да') {
                    $dateToDelete = $this->userSelections[$chatId]['diary_delete']['date'] ?? null;
                    $indexToDelete = $this->userSelections[$chatId]['diary_delete']['index'] ?? null;
                    $entryName = $this->userSelections[$chatId]['diary_delete']['entry']['name'] ?? '???';

                    if ($dateToDelete && $indexToDelete !== null && isset($this->diaryData[$chatId][$dateToDelete][$indexToDelete])) {
                        unset($this->diaryData[$chatId][$dateToDelete][$indexToDelete]);
                        $this->diaryData[$chatId][$dateToDelete] = array_values($this->diaryData[$chatId][$dateToDelete]);
                        if (empty($this->diaryData[$chatId][$dateToDelete])) { unset($this->diaryData[$chatId][$dateToDelete]); }
                        if (empty($this->diaryData[$chatId])) { unset($this->diaryData[$chatId]); }
                        $this->dataStorage->saveAllDiaryData($this->diaryData);
                        $formattedDate = date('d.m.Y', strtotime($dateToDelete));
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId, 'text' => "Запись '{$entryName}' за {$formattedDate} удалена.", 'reply_markup' => $this->keyboardService->makeDiaryMenu()
                        ]);
                    } else {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Не удалось найти запись для удаления.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    }
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_delete']);
                } elseif ($text === 'Нет') {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Удаление отменено.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_delete']);
                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Нажмите "Да" или "Нет".', 'reply_markup' => $this->keyboardService->makeConfirmYesNo() ]);
                }
                break;

            // --- Просмотр рациона ---
            case States::AWAITING_DATE_VIEW_MEAL:
                $dateToView = null;
                $normalizedText = strtolower(trim($text));
                if ($normalizedText === 'вчера') { $dateToView = date('Y-m-d', strtotime('-1 day')); }
                elseif ($normalizedText === 'сегодня') { $dateToView = date('Y-m-d'); }
                elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $text, $matches)) {
                    if (checkdate($matches[2], $matches[1], $matches[3])) { $dateToView = "{$matches[3]}-{$matches[2]}-{$matches[1]}"; }
                }

                if (!$dateToView) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректный формат даты...', 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                    break;
                }

                $formattedDate = date('d.m.Y', strtotime($dateToView));
                if (empty($this->diaryData[$chatId][$dateToView])) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId, 'text' => "Нет записей за {$formattedDate}.", 'reply_markup' => $this->keyboardService->makeDiaryMenu()
                    ]);
                } else {
                    $totalP = 0; $totalF = 0; $totalC = 0; $totalKcal = 0;
                    $viewMsg = "Рацион за {$formattedDate}:\n\n";
                    $i = 1;
                    foreach ($this->diaryData[$chatId][$dateToView] as $entry) {
                        $viewMsg .= sprintf("%d. %s (%g г)\n   Б: %g Ж: %g У: %g К: %g\n",
                            $i++, $entry['name'], $entry['grams'], $entry['p'], $entry['f'], $entry['c'], $entry['kcal']
                        );
                        $totalP += $entry['p']; $totalF += $entry['f']; $totalC += $entry['c']; $totalKcal += $entry['kcal'];
                    }
                    $viewMsg .= sprintf("\nИтого: Б: %g Ж: %g У: %g К: %g",
                        round($totalP, 1), round($totalF, 1), round($totalC, 1), round($totalKcal)
                    );
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $viewMsg, 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                }
                $this->userStates[$chatId] = States::DIARY_MENU;
                break;

        } // Конец switch
    }

        /**
     * Обрабатывает состояния выбора упражнения (группа, тип, название).
     */
    private function handleExerciseSelectionState(int $chatId, string $text, Message $message, int $currentState): void
    {
        // --- ОБЩАЯ Обработка состояний выбора упражнения ---
        if ($currentState >= States::SELECTING_MUSCLE_GROUP && $currentState <= States::SELECTING_EXERCISE) {
            if (!ctype_digit($text)) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Пожалуйста, введите номер из списка или нажмите "Назад".',
                    'reply_markup' => $this->keyboardService->makeBackOnly()
                ]);
                return;
            }
            $choiceIndex = (int)$text - 1;
            switch ($currentState) {
                case States::SELECTING_MUSCLE_GROUP:
                    $groupKeys = array_keys($this->exercises);
                    if (isset($groupKeys[$choiceIndex])) {
                        $selectedGroup = $groupKeys[$choiceIndex];
                        $this->userSelections[$chatId]['group'] = $selectedGroup;
                        $this->userStates[$chatId] = States::SELECTING_EXERCISE_TYPE;
                        $typeKeys = isset($this->exercises[$selectedGroup]) ? array_keys($this->exercises[$selectedGroup]) : [];
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Группа: {$selectedGroup}\nВыберите тип:\n" . $this->generateListMessage($typeKeys),
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    } else {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Неверный номер группы.',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    }
                    break;
                case States::SELECTING_EXERCISE_TYPE:
                    $group = $this->userSelections[$chatId]['group'] ?? null;
                    if (!$group || !isset($this->exercises[$group])) { // Добавлена проверка существования группы
                        $this->userStates[$chatId] = States::DEFAULT;
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Ошибка: Группа упражнений не найдена. Пожалуйста, начните заново.',
                            'reply_markup' => $this->keyboardService->makeMainMenu()
                        ]);
                        break;
                    }
                    $typeKeys = array_keys($this->exercises[$group]);
                    if (isset($typeKeys[$choiceIndex])) {
                        $selectedType = $typeKeys[$choiceIndex];
                        $this->userSelections[$chatId]['type'] = $selectedType;
                        $this->userStates[$chatId] = States::SELECTING_EXERCISE;
                        $exerciseList = isset($this->exercises[$group][$selectedType]) ? $this->exercises[$group][$selectedType] : [];
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Тип: {$selectedType}\nВыберите упражнение:\n" . $this->generateListMessage($exerciseList),
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    } else {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Неверный номер типа.',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    }
                    break;
                case States::SELECTING_EXERCISE:
                    $group = $this->userSelections[$chatId]['group'] ?? null;
                    $type = $this->userSelections[$chatId]['type'] ?? null;
                    $mode = $this->userSelections[$chatId]['mode'] ?? null;
                    if (!$group || !$type || !$mode || !isset($this->exercises[$group][$type])) { // Добавлена проверка
                        $this->userStates[$chatId] = States::DEFAULT;
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Ошибка: Данные выбора упражнения некорректны. Пожалуйста, начните заново.',
                            'reply_markup' => $this->keyboardService->makeMainMenu()
                        ]);
                        break;
                    }
                    $exerciseList = $this->exercises[$group][$type];
                    if (isset($exerciseList[$choiceIndex])) {
                        $selectedExercise = $exerciseList[$choiceIndex];
                        if ($mode === 'log') {
                            $this->userSelections[$chatId]['exercise'] = $selectedExercise;
                            $this->userStates[$chatId] = States::AWAITING_REPS;
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Упражнение: {$selectedExercise}\nВведите количество повторений:",
                                'reply_markup' => $this->keyboardService->makeBackOnly()
                            ]);
                        } elseif ($mode === 'view') {
                            $this->userStates[$chatId] = States::DEFAULT; // Возвращаемся в главное меню
                            unset($this->userSelections[$chatId]);
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Упражнение: {$selectedExercise}\nВаши результаты:\n(Функционал просмотра прогресса пока не реализован)",
                                'reply_markup' => $this->keyboardService->makeTrainingMenu() // Возврат в меню Тренировки
                            ]);
                        } else {
                            $this->userStates[$chatId] = States::DEFAULT;
                             unset($this->userSelections[$chatId]);
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => 'Внутренняя ошибка: неизвестный режим выбора упражнения.',
                                'reply_markup' => $this->keyboardService->makeMainMenu()
                            ]);
                        }
                    } else {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Неверный номер упражнения.',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    }
                    break;
            }
            return;
        }
    }

    /**
     * Обрабатывает ввод повторений и веса при записи тренировки.
     */
    private function handleTrainingLogInputState(int $chatId, string $text, Message $message, int $currentState): void
    {
        // Валидация ввода

        // Проверка для ПОВТОРЕНИЙ (должно быть > 0)
        if ($currentState === States::AWAITING_REPS && (!is_numeric($text) || $text <= 0 || $text > 1000)) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Некорректный ввод. Введите целое положительное число повторений (не более 1000) или 'Назад'.", // Положительное (>0)
                'reply_markup' => $this->keyboardService->makeBackOnly()
            ]);
            return;
        }

        // ---> ИСПРАВЛЕНА ВАЛИДАЦИЯ И ТЕКСТ ОШИБКИ ДЛЯ ВЕСА <---
        // Проверка для ВЕСА (должно быть >= 0)
        if ($currentState === States::AWAITING_WEIGHT && (!is_numeric($text) || $text < 0 || $text > 1000)) { // Используем $text < 0, чтобы разрешить 0
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Некорректный ввод. Введите вес (число от 0 до 1000) или 'Назад'.", // Сообщение теперь корректно
                'reply_markup' => $this->keyboardService->makeBackOnly()
            ]);
            return;
        }
        // ---> КОНЕЦ ИСПРАВЛЕНИЙ <---

        // Основная логика состояний (остается без изменений)
        if ($currentState === States::AWAITING_REPS) {
            $this->userSelections[$chatId]['reps'] = $text;
            $this->userStates[$chatId] = States::AWAITING_WEIGHT;
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Повторения: {$text}\nВведите вес (можно 0):", // Подсказка остается прежней
                'reply_markup' => $this->keyboardService->makeBackOnly()
            ]);
        } elseif ($currentState === States::AWAITING_WEIGHT) {
            // Сюда мы попадем, только если валидация пройдена (включая ввод "0")
            $this->userSelections[$chatId]['weight'] = $text;
            $logEntry = [
                'exercise' => $this->userSelections[$chatId]['exercise'] ?? '???',
                'reps' => $this->userSelections[$chatId]['reps'] ?? '???',
                'weight' => $this->userSelections[$chatId]['weight'] ?? '???',
            ];
            if (!isset($this->currentTrainingLog[$chatId])) {
                $this->currentTrainingLog[$chatId] = [];
            }
            $this->currentTrainingLog[$chatId][] = $logEntry;
            echo "Добавлено в лог для $chatId: "; print_r($logEntry); echo "\n";

            $exerciseName = $logEntry['exercise'];
            unset(
                $this->userSelections[$chatId]['group'], $this->userSelections[$chatId]['type'],
                $this->userSelections[$chatId]['exercise'], $this->userSelections[$chatId]['reps'],
                $this->userSelections[$chatId]['weight']
            );
            $this->userStates[$chatId] = States::LOGGING_TRAINING_MENU;
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Подход '{$exerciseName}' ({$logEntry['reps']}x{$logEntry['weight']}) добавлен!\nДобавить еще упражнение/подход?",
                'reply_markup' => $this->keyboardService->makeAddExerciseMenu()
            ]);
        }
    }


    // Добавь этот метод в класс BotKernel, если его еще нет
    /**
     * Обрабатывает команды и кнопки в основных меню.
     */
    private function handleMenuCommands(int $chatId, string $text, Message $message, int $currentState): void
    {
        // Проверка на регистрацию для большинства команд
        $unprotectedCommands = ['/start'];
        if (!in_array($text, $unprotectedCommands) && !isset($this->userData[$chatId])) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пожалуйста, сначала зарегистрируйтесь или войдите с помощью команды /start.',
                'reply_markup' => $this->keyboardService->removeKeyboard()
            ]);
            return; // Выход, если не зарегистрирован и команда не /start
        }

        // Используем $currentState для более точной маршрутизации команд из разных меню
        switch ($text) {
            // --- Основные команды и Аккаунт ---
            case '/start':
                if (isset($this->userData[$chatId])) {
                    $name = $this->userData[$chatId]['name'] ?? 'пользователь';
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "С возвращением, {$name}!",
                        'reply_markup' => $this->keyboardService->makeMainMenu()
                    ]);
                    $this->userStates[$chatId] = States::DEFAULT; // Сброс состояния на всякий случай
                } else {
                    $this->userStates[$chatId] = States::AWAITING_NAME; // Начинаем регистрацию
                     // Инициализируем userData для этого пользователя
                    $this->userData[$chatId] = [];
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Добро пожаловать! Давайте зарегистрируемся.\nВведите ваше имя:",
                        'reply_markup' => $this->keyboardService->removeKeyboard()
                    ]);
                }
                break;
            case 'Аккаунт':
                 if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Настройки аккаунта:',
                        'reply_markup' => $this->keyboardService->makeAccountMenu()
                    ]);
                    // Можно установить состояние $this->userStates[$chatId] = States::ACCOUNT_MENU;, если нужно
                 } // Иначе игнорируем, если мы не в главном меню
                break;
            case 'Вывести имя и почту':
                 // Проверяем, что мы в меню аккаунта или главном (на случай прямого ввода текста)
                if ($currentState === States::DEFAULT /* || $currentState === States::ACCOUNT_MENU */) {
                    $name = $this->userData[$chatId]['name'] ?? 'Не указано';
                    $email = $this->userData[$chatId]['email'] ?? 'Не указана';
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Имя: {$name}\nПочта: {$email}",
                        'reply_markup' => $this->keyboardService->makeAccountMenu() // Остаемся в меню аккаунта
                    ]);
                }
                break;
            case 'Сменить аккаунт':
                 if ($currentState === States::DEFAULT /* || $currentState === States::ACCOUNT_MENU */) {
                     $this->userStates[$chatId] = States::AWAITING_NAME; // Начинаем процесс "регистрации" заново
                     // Не очищаем $this->userData[$chatId], чтобы при отмене вернуться к старым данным
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => "Введите новое имя (или 'Назад' для отмены):",
                         'reply_markup' => $this->keyboardService->makeBackOnly() // Клавиатура только с "Назад"
                     ]);
                 }
                break;

            // --- Меню Тренировки и его подпункты ---
            case 'Тренировки':
                if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Раздел тренировок:',
                        'reply_markup' => $this->keyboardService->makeTrainingMenu()
                    ]);
                    // Можно установить состояние $this->userStates[$chatId] = States::TRAINING_MENU;
                 }
                break;
            case 'Записать тренировку':
                if ($currentState === States::DEFAULT /* || $currentState === States::TRAINING_MENU */) {
                    $this->userStates[$chatId] = States::LOGGING_TRAINING_MENU; // Переходим в режим записи
                    $this->currentTrainingLog[$chatId] = []; // Очищаем лог предыдущей тренировки
                    unset($this->userSelections[$chatId]); // Очищаем предыдущий выбор упражнений
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Начало записи тренировки. Добавьте первый подход/упражнение:',
                        'reply_markup' => $this->keyboardService->makeAddExerciseMenu()
                    ]);
                 }
                break;
            case 'Посмотреть прогресс в упражнениях':
                 if ($currentState === States::DEFAULT /* || $currentState === States::TRAINING_MENU */) {
                     $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP; // Начинаем выбор упражнения
                     $this->userSelections[$chatId] = ['mode' => 'view']; // Устанавливаем режим просмотра
                     $groupKeys = array_keys($this->exercises);
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => "Для просмотра прогресса, выберите группу мышц:\n" . $this->generateListMessage($groupKeys),
                         'reply_markup' => $this->keyboardService->makeBackOnly()
                     ]);
                 }
                break;
            case 'Вывести отстающие группы мышц':
                 if ($currentState === States::DEFAULT /* || $currentState === States::TRAINING_MENU */) {
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => "Анализ отстающих групп мышц:\n(Функционал пока не реализован)",
                         'reply_markup' => $this->keyboardService->makeTrainingMenu() // Остаемся в меню тренировок
                     ]);
                 }
                break;

            // --- Кнопки меню Записи Тренировки ---
            case 'Добавить упражнение':
                if ($currentState === States::LOGGING_TRAINING_MENU) {
                    $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP; // Начинаем выбор
                    $this->userSelections[$chatId]['mode'] = 'log'; // Убедимся, что режим 'log'
                    $groupKeys = array_keys($this->exercises);
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Выберите группу мышц:\n" . $this->generateListMessage($groupKeys),
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                 }
                break;
            case 'Завершить запись тренировки':
                 if ($currentState === States::LOGGING_TRAINING_MENU) {
                     $logCount = isset($this->currentTrainingLog[$chatId]) ? count($this->currentTrainingLog[$chatId]) : 0;
                     if ($logCount > 0) {
                         // TODO: Реализовать сохранение лога тренировки в файл или БД
                         echo "Завершение тренировки для $chatId ({$logCount} подходов/упр.): ";
                         print_r($this->currentTrainingLog[$chatId]); echo "\n";
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => "Тренировка завершена и записана ({$logCount} подходов/упр.). Отличная работа!",
                             'reply_markup' => $this->keyboardService->makeMainMenu() // Возврат в главное меню
                         ]);
                     } else {
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => 'Вы не добавили ни одного упражнения/подхода. Запись отменена.',
                             'reply_markup' => $this->keyboardService->makeTrainingMenu() // Возврат в меню тренировок
                         ]);
                     }
                     // Сброс в любом случае
                     $this->userStates[$chatId] = States::DEFAULT;
                     unset($this->currentTrainingLog[$chatId]);
                     unset($this->userSelections[$chatId]);
                 }
                break;

            // --- Меню Питание и его подпункты ---
            case 'Питание':
                if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Раздел питания:',
                        'reply_markup' => $this->keyboardService->makeNutritionMenu()
                    ]);
                    // Можно установить $this->userStates[$chatId] = States::NUTRITION_MENU;
                 }
                break;
            case 'Дневник':
                if ($currentState === States::DEFAULT /* || $currentState === States::NUTRITION_MENU */) {
                     $this->userStates[$chatId] = States::DIARY_MENU; // Переходим в меню дневника
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => "Дневник питания:",
                         'reply_markup' => $this->keyboardService->makeDiaryMenu()
                     ]);
                 }
                break;
            case 'БЖУ продуктов':
                 if ($currentState === States::DEFAULT /* || $currentState === States::NUTRITION_MENU */) {
                     $this->userStates[$chatId] = States::BJU_MENU; // Переходим в меню БЖУ
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => 'Управление базой БЖУ ваших продуктов:',
                         'reply_markup' => $this->keyboardService->makeBjuMenu()
                     ]);
                 }
                break;

            // --- Кнопки меню Дневника ---
            case 'Записать приём пищи':
                 if ($currentState === States::DIARY_MENU) {
                     $this->userStates[$chatId] = States::AWAITING_ADD_MEAL_OPTION; // Ожидаем выбор способа
                     unset($this->userSelections[$chatId]['diary_entry']); // Очищаем старые данные
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => 'Как вы хотите записать прием пищи?',
                         'reply_markup' => $this->keyboardService->makeAddMealOptionsMenu()
                     ]);
                 }
                break;
            case 'Удалить приём пищи':
                 if ($currentState === States::DIARY_MENU) {
                     if (!isset($this->diaryData[$chatId]) || empty($this->diaryData[$chatId])) {
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => 'Ваш дневник питания пока пуст. Нечего удалять.',
                             'reply_markup' => $this->keyboardService->makeDiaryMenu()
                         ]);
                     } else {
                         $this->userStates[$chatId] = States::AWAITING_DATE_DELETE_MEAL; // Запрашиваем дату
                         unset($this->userSelections[$chatId]['diary_delete']); // Очищаем старые данные
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => 'Введите дату приема пищи для удаления (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":',
                             'reply_markup' => $this->keyboardService->makeBackOnly()
                         ]);
                     }
                 }
                break;
            case 'Посмотреть рацион за дату':
                 if ($currentState === States::DIARY_MENU) {
                     $this->userStates[$chatId] = States::AWAITING_DATE_VIEW_MEAL; // Запрашиваем дату
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => 'Введите дату для просмотра рациона (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":',
                         'reply_markup' => $this->keyboardService->makeBackOnly()
                     ]);
                 }
                break;

            // --- Кнопки подменю БЖУ ---
            case 'Сохранить информацию о продукте':
                 if ($currentState === States::BJU_MENU) {
                     $this->userStates[$chatId] = States::AWAITING_PRODUCT_NAME_SAVE; // Начинаем ввод данных
                     unset($this->userSelections[$chatId]['bju_product']); // Очищаем старые данные
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => 'Введите название продукта (или "Назад"):',
                         'reply_markup' => $this->keyboardService->makeBackOnly()
                     ]);
                 }
                break;
                case 'Удалить информацию о продукте':
                    // Работает ТОЛЬКО в состоянии BJU_MENU (или DEFAULT, если меню БЖУ - это DEFAULT)
                    // ---> ИЗМЕНЕННЫЙ КОД <---
                    if (in_array($currentState, [States::DEFAULT, States::BJU_MENU])) { // Проверяем, что мы в меню БЖУ или главном
                        if (!isset($this->userProducts[$chatId]) || empty($this->userProducts[$chatId])) {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => 'У вас нет сохраненных продуктов для удаления.',
                                'reply_markup' => $this->keyboardService->makeBjuMenu()
                            ]);
                        } else {
                            // Формируем нумерованный список
                            $productListMsg = "Какой продукт удалить?\n\n";
                            $i = 1;
                            // Сортируем ключи (имена в lower case) для стабильного порядка
                            $sortedKeys = array_keys($this->userProducts[$chatId]);
                            sort($sortedKeys);
                            $productsForSelection = []; // Сохраним порядок для удаления
                            foreach ($sortedKeys as $nameLower) {
                                $bju = $this->userProducts[$chatId][$nameLower];
                                // Пытаемся найти оригинальное имя (лучше хранить его при сохранении)
                                $originalName = $this->findOriginalProductName($chatId, $nameLower);
                                $productListMsg .= sprintf("%d. %s (Б: %g, Ж: %g, У: %g, К: %g / 100г)\n",
                                    $i, $originalName, $bju[0], $bju[1], $bju[2], $bju[3]);
                                // Сохраняем ключ (имя в lower case) для последующего удаления по номеру
                                $productsForSelection[$i] = $nameLower;
                                $i++;
                            }
       
                            $productListMsg .= "\nВведите номер продукта для удаления (или 'Назад'):";
       
                            // Сохраняем отсортированный список ключей во временное хранилище пользователя
                            $this->userSelections[$chatId]['products_for_delete'] = $productsForSelection;
       
                            // Переводим в новое состояние ожидания номера
                            $this->userStates[$chatId] = States::AWAITING_PRODUCT_NUMBER_DELETE;
       
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => rtrim($productListMsg),
                                'reply_markup' => $this->keyboardService->makeBackOnly() // Только кнопка Назад
                            ]);
                        }
                    }
                   break; // Не забываем break
            case 'Сохранённые продукты':
                if ($currentState === States::BJU_MENU) {
                    if (!isset($this->userProducts[$chatId]) || empty($this->userProducts[$chatId])) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'У вас нет сохраненных продуктов.',
                            'reply_markup' => $this->keyboardService->makeBjuMenu()
                        ]);
                    } else {
                        $productListMsg = "Ваши сохраненные продукты:\n";
                        $i = 1;
                        // Сортируем для единообразия вывода
                        $sortedProducts = $this->userProducts[$chatId];
                        ksort($sortedProducts);
                        foreach ($sortedProducts as $nameLower => $bju) {
                            // Отображаем оригинальное имя, если найдем его
                            $originalName = $this->findOriginalProductName($chatId, $nameLower);
                            $productListMsg .= sprintf("%d. %s (Б: %g, Ж: %g, У: %g, К: %g / 100г)\n",
                                $i++, $originalName, $bju[0], $bju[1], $bju[2], $bju[3]);
                        }
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => rtrim($productListMsg),
                            'reply_markup' => $this->keyboardService->makeBjuMenu()
                        ]);
                    }
                }
                break;
            case 'Поиск продуктов':
                 if ($currentState === States::BJU_MENU) {
                     if (!isset($this->userProducts[$chatId]) || empty($this->userProducts[$chatId])) {
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => 'У вас нет сохраненных продуктов для поиска.',
                             'reply_markup' => $this->keyboardService->makeBjuMenu()
                         ]);
                     } else {
                         $this->userStates[$chatId] = States::AWAITING_PRODUCT_NAME_SEARCH; // Запрашиваем имя
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => 'Введите название продукта для поиска (или "Назад"):',
                             'reply_markup' => $this->keyboardService->makeBackOnly()
                         ]);
                     }
                 }
                break;

            // --- Кнопка "Назад" (из ГЛАВНЫХ подменю) ---
            case 'Назад':
                // Определяем, из какого меню пришла команда "Назад"
                if ($currentState === States::LOGGING_TRAINING_MENU) { // Из меню записи тренировки
                    $this->userStates[$chatId] = States::DEFAULT; // Возврат в меню тренировок (или главное?)
                    unset($this->currentTrainingLog[$chatId]);
                    unset($this->userSelections[$chatId]);
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Запись тренировки отменена. Возврат в меню тренировок.',
                        'reply_markup' => $this->keyboardService->makeTrainingMenu()
                    ]);
                } elseif ($currentState === States::DIARY_MENU) { // Из меню дневника
                    $this->userStates[$chatId] = States::DEFAULT; // Возврат в меню Питания
                     unset($this->userSelections[$chatId]); // Очистка на всякий случай
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Возврат в меню Питания.',
                        'reply_markup' => $this->keyboardService->makeNutritionMenu()
                    ]);
                } elseif ($currentState === States::BJU_MENU) { // Из меню БЖУ
                    $this->userStates[$chatId] = States::DEFAULT; // Возврат в меню Питания
                     unset($this->userSelections[$chatId]); // Очистка на всякий случай
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Возврат в меню Питания.',
                        'reply_markup' => $this->keyboardService->makeNutritionMenu()
                    ]);
                } elseif ($currentState === States::DEFAULT) { // Если мы УЖЕ в главном меню или подменю 1-го уровня
                    // Попытка определить предыдущий контекст (менее надежно)
                    // Лучше было бы иметь явные состояния для подменю (TRAINING_MENU, NUTRITION_MENU и т.д.)
                    $replyTo = $message->getReplyToMessage();
                    $lastBotText = $replyTo ? $replyTo->getText() : '';

                    if ($lastBotText && (str_contains($lastBotText, 'Раздел питания') || str_contains($lastBotText, 'Дневник питания') || str_contains($lastBotText, 'Управление базой БЖУ'))) {
                         $this->telegram->sendMessage([ // Из Питания -> в Главное
                             'chat_id' => $chatId,
                             'text' => 'Главное меню.',
                             'reply_markup' => $this->keyboardService->makeMainMenu()
                         ]);
                    } elseif ($lastBotText && (str_contains($lastBotText, 'Раздел тренировок') || str_contains($lastBotText, 'записи тренировки'))) {
                         $this->telegram->sendMessage([ // Из Тренировок -> в Главное
                             'chat_id' => $chatId,
                             'text' => 'Главное меню.',
                             'reply_markup' => $this->keyboardService->makeMainMenu()
                         ]);
                    } elseif ($lastBotText && str_contains($lastBotText, 'Настройки аккаунта')) {
                         $this->telegram->sendMessage([ // Из Аккаунта -> в Главное
                             'chat_id' => $chatId,
                             'text' => 'Главное меню.',
                             'reply_markup' => $this->keyboardService->makeMainMenu()
                         ]);
                    } else { // Если не удалось определить или уже в главном
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Вы уже в главном меню.',
                            'reply_markup' => $this->keyboardService->makeMainMenu()
                        ]);
                    }
                    // Убедимся, что состояние сброшено в DEFAULT
                    $this->userStates[$chatId] = States::DEFAULT;
                    unset($this->userSelections[$chatId]); // Очистка временных данных
                }
                // Добавить обработку для других возможных состояний, если они появятся
                break;

            // Обработка неизвестных команд/текста в базовом состоянии
            default:
                 if ($currentState === States::DEFAULT) {
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => 'Неизвестная команда или текст. Используйте кнопки меню.',
                         'reply_markup' => $this->keyboardService->makeMainMenu()
                     ]);
                 }
                 // Если состояние не DEFAULT, то мы ожидаем ввод данных, и этот блок не должен срабатывать
                 // (так как соответствующие case для состояний должны были обработать ввод выше).
                 // Если он сработал - это может быть ошибкой в логике состояний.
                 elseif ($currentState !== States::DEFAULT) {
                    // Можно добавить логирование или сообщение об ошибке
                     echo "Warning: Unhandled text '{$text}' in state {$currentState} for chat {$chatId}\n";
                     // Можно отправить сообщение пользователю, что ожидался другой ввод
                     //$this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ожидался другой ввод. Используйте кнопки или введите данные.']);
                 }
                break;
        }

    }



}