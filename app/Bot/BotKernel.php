<?php

// app/Bot/BotKernel.php

//Ctrl+K Ctrl+0   Ctrl+K Ctrl+J 

namespace Bot; // Пространство имен для нашего бота

use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;
use Bot\Constants\States;         // Подключаем класс с константами
use Bot\Keyboard\KeyboardService; // Подключаем сервис клавиатур
use Bot\Service\DataStorageService;
use Illuminate\Support\Facades\Log;


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
    private array $trainingLogData = []; 
    private array $userSelections = [];
    private array $currentTrainingLog = [];

    // Структура упражнений
    private array $exercises = [];


    public function __construct(
        Api $telegram,
        DataStorageService $dataStorage,
        KeyboardService $keyboardService
    ) {
        $this->telegram = $telegram;
        $this->dataStorage = $dataStorage;
        $this->keyboardService = $keyboardService;

        // Загружаем данные из сервиса
        $this->userData = $this->dataStorage->getAllUserData();
        $this->userProducts = $this->dataStorage->getAllUserProducts();
        $this->diaryData = $this->dataStorage->getAllDiaryData();
        $this->trainingLogData = $this->dataStorage->getAllTrainingLogData(); // <-- ДОБАВИТЬ ЗАГРУЗКУ

        $this->loadExercises();
        echo "BotKernel Initialized via Laravel Container.\n";
    }

    public function run(): void
    {
        Log::info("Starting Bot Kernel run loop...");
        while (true) {
            try {
                $updates = $this->telegram->getUpdates(['offset' => $this->updateId + 1, 'timeout' => 30]);
            } catch (TelegramSDKException $e) {
                Log::error("Telegram SDK Error: " . $e->getMessage());
                sleep(5);
                continue;
            } catch (\Throwable $e) {
                Log::error("General Error getting updates: " . $e->getMessage(), ['exception' => $e]);
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
                if (!isset($this->trainingLogData[$chatId])) {
                    $this->trainingLogData[$chatId] = [];
                }

                echo "Получено сообщение: $text (Chat ID: $chatId), State: " . ($this->userStates[$chatId] ?? States::DEFAULT) . "\n";

                try {
                    // Вызываем метод обработки сообщения
                    $this->handleMessage($chatId, $text, $message); // Передаем объект message для getReplyToMessage
                } catch (\Throwable $e) {
                    Log::error("Error processing message for chat ID {$chatId}: " . $e->getMessage(), [
                        'exception' => $e,
                        'chat_id' => $chatId,
                        'text' => $text,
                    ]);
                    try {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла внутренняя ошибка. Попробуйте позже.']);
                        $this->userStates[$chatId] = States::DEFAULT; unset($this->userSelections[$chatId]);
                    } catch (\Throwable $ex) {
                        Log::error("Could not send error message to user {$chatId}.", ['exception' => $ex]);
                    }
                }
            }
            sleep(1);
        }
    }

  

     //Основной метод обработки входящих сообщений.
    private function handleMessage(int $chatId, string $text, Message $message): void
    {
        $currentState = $this->userStates[$chatId] ?? States::DEFAULT;

        // 1. Обработка кнопки "Назад" ВО ВРЕМЯ ввода данных
        // Вызываем метод, только если текст == "Назад"
        if ($text === '⬅️ Назад' && $this->handleBackDuringInput($chatId, $message, $currentState)) {
            // Если handleBackDuringInput вернул true, значит, "Назад" было обработано
            // для состояния ввода, и мы можем завершить обработку этого сообщения.
            return;
        }

        // 2. Маршрутизация по состояниям ввода данных

        // Состояния Регистрации (первого аккаунта)
        if ($currentState >= States::AWAITING_NAME && $currentState <= States::AWAITING_PASSWORD) {
            $this->handleRegistrationState($chatId, $text, $message, $currentState);
            return; // Состояние обработано
        }

        // Состояния Добавления Нового Аккаунта
        if ($currentState >= States::AWAITING_NEW_ACCOUNT_NAME && $currentState <= States::AWAITING_NEW_ACCOUNT_PASSWORD) {
            $this->handleNewAccountState($chatId, $text, $message, $currentState);
            return; // Состояние обработано
        }

        // Состояние Переключения Аккаунта
        if ($currentState === States::AWAITING_ACCOUNT_SWITCH_SELECTION) {
            $this->handleAccountSwitchState($chatId, $text, $message, $currentState);
            return; // Состояние обработано
        }

        // Состояния Управления БЖУ Продуктов
        if (($currentState >= States::AWAITING_PRODUCT_NAME_SAVE && $currentState <= States::AWAITING_SAVE_CONFIRMATION) || // Сохранение
            $currentState === States::AWAITING_PRODUCT_NUMBER_DELETE || // <-- Удаление по номеру
            $currentState === States::AWAITING_DELETE_CONFIRMATION ||   // <-- Подтверждение удаления
            $currentState === States::AWAITING_PRODUCT_NAME_SEARCH)      // <-- Поиск
        {
            $this->handleBjuStates($chatId, $text, $message, $currentState);
            return; // Состояние обработано
        }

        // Состояния Дневника Питания
        // Включаем все шаги добавления, удаления и просмотра
        if (($currentState === States::AWAITING_DATE_MANUAL_ADD || $currentState >= States::AWAITING_ADD_MEAL_OPTION && $currentState <= States::AWAITING_ADD_MEAL_CONFIRM_MANUAL && $currentState != States::DIARY_MENU) || // Добавление
            $currentState === States::AWAITING_DATE_DELETE_MEAL ||
            $currentState === States::AWAITING_DATE_SEARCH_ADD ||      // Ввод даты удаления
            $currentState === States::AWAITING_MEAL_NUMBER_DELETE ||   // <-- Ввод номера удаления
            $currentState === States::AWAITING_DELETE_MEAL_CONFIRM ||   // <-- Подтверждение удаления
            $currentState === States::AWAITING_DATE_VIEW_MEAL)           // Ввод даты просмотра
        {
            $this->handleDiaryStates($chatId, $text, $message, $currentState);
            return; // Состояние обработано
        }

        // Состояния Выбора Упражнения
        if ($currentState >= States::SELECTING_MUSCLE_GROUP && $currentState <= States::SELECTING_EXERCISE) {
            $this->handleExerciseSelectionState($chatId, $text, $message, $currentState);
            return; // Состояние обработано
        }

        // Состояния Ввода Данных Тренировки (Повторы/Вес)
        if ($currentState === States::AWAITING_REPS || $currentState === States::AWAITING_WEIGHT) {
            $this->handleTrainingLogInputState($chatId, $text, $message, $currentState);
            return; // Состояние обработано
        }

        $this->handleMenuCommands($chatId, $text, $message, $currentState);


    }

    // ---> ИЗМЕНЕНО: Добавлен параметр $activeEmail <---
    private function findOriginalProductName(int $chatId, string $productNameLower, ?string $activeEmail): string
    {
        // ---> ИЗМЕНЕНО: Ищем по activeEmail <---
        // Сначала проверяем временные данные, если они есть (при сохранении)
        $tempProductData = $this->userSelections[$chatId]['bju_product'] ?? null;
        if ($tempProductData && isset($tempProductData['name']) && mb_strtolower($tempProductData['name']) === $productNameLower) {
            return $tempProductData['name'];
        }

        // Потом ищем в сохраненных продуктах активного аккаунта
        if ($activeEmail && isset($this->userProducts[$chatId][$activeEmail])) {
            // TODO: Улучшить хранение/поиск оригинальных имен.
            // Эта реализация неточна, если ключи не хранят оригинальный регистр.
            // Пока просто возвращаем ключ в lower-case, если не нашли лучше.
                if (array_key_exists($productNameLower, $this->userProducts[$chatId][$activeEmail])) {
                    // Если бы ключ хранил оригинальное имя, вернули бы его.
                    // return $key_with_original_case;
                    return $productNameLower; // Возвращаем как есть (lower case)
                }
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

    private function handleBackDuringInput(int $chatId, Message $message, int $currentState): bool
    {
        $currentMode = $this->userSelections[$chatId]['mode'] ?? null;

        // --- Упражнения ---
        if ($currentState >= States::SELECTING_MUSCLE_GROUP && $currentState <= States::AWAITING_WEIGHT) {
            $returnState = ($currentMode === 'log') ? States::LOGGING_TRAINING_MENU : States::DEFAULT;
            $returnKeyboard = ($currentMode === 'log') ? $this->keyboardService->makeAddExerciseMenu() : $this->keyboardService->makeTrainingMenu();
            $cancelMessage = ($currentMode === 'log') ? 'Добавление упражнения отменено.' : 'Просмотр прогресса отменен.';
            
            $returnState = match ($currentMode) {
                'log' => States::LOGGING_TRAINING_MENU, // Возврат в меню добавления упражнения
                'view', 'technique' => States::DEFAULT, // Возврат в главное меню для режимов просмотра
                default => States::DEFAULT,
           };
           $returnKeyboard = match ($currentMode) {
                'log' => $this->keyboardService->makeAddExerciseMenu(),
                'view', 'technique' => $this->keyboardService->makeTrainingMenu(), // Клавиатура меню тренировок
                default => $this->keyboardService->makeTrainingMenu(),
           };
           $cancelMessage = match ($currentMode) {
               'log' => 'Добавление упражнения отменено.',
               'view' => 'Просмотр прогресса отменен.',
               'technique' => 'Просмотр техники отменен.', // <-- Добавлено сообщение
               default => 'Действие отменено.'
           };
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
        if (
            // Все шаги добавления (кроме самого меню DIARY_MENU, из которого "Назад" обрабатывается в handleMenuCommands)
            ($currentState >= States::AWAITING_ADD_MEAL_OPTION && $currentState <= States::AWAITING_ADD_MEAL_CONFIRM_MANUAL && $currentState != States::DIARY_MENU) ||
            // Добавлено новое состояние для ввода даты перед ручным вводом
            $currentState === States::AWAITING_DATE_MANUAL_ADD ||
            // Все шаги удаления (кроме самого меню DIARY_MENU)
            $currentState === States::AWAITING_DATE_DELETE_MEAL ||
            $currentState === States::AWAITING_DATE_SEARCH_ADD ||
            $currentState === States::AWAITING_MEAL_NUMBER_DELETE ||
            $currentState === States::AWAITING_DELETE_MEAL_CONFIRM ||
            // Шаг просмотра
            $currentState === States::AWAITING_DATE_VIEW_MEAL
        ) {
            $previousState = States::DEFAULT; // По умолчанию
            $previousKeyboard = $this->keyboardService->makeMainMenu(); // По умолчанию
            $messageText = 'Действие отменено.'; // По умолчанию
    
            // Определяем, куда вернуться
            if ($currentState === States::AWAITING_ADD_MEAL_OPTION) {
                $previousState = States::DIARY_MENU;
                $previousKeyboard = $this->keyboardService->makeDiaryMenu();
                $messageText = 'Возврат в меню Дневника.';
            }elseif ($currentState === States::AWAITING_DATE_SEARCH_ADD) {
                $previousState = States::AWAITING_ADD_MEAL_OPTION;
                $previousKeyboard = $this->keyboardService->makeAddMealOptionsMenu();
                $messageText = 'Запись отменена. Выберите способ добавления.';
                unset($this->userSelections[$chatId]['diary_entry']);
            // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            } elseif ($currentState === States::AWAITING_DATE_MANUAL_ADD) { // <-- НОВОЕ: Назад из ввода даты для ручного добавления
                $previousState = States::AWAITING_ADD_MEAL_OPTION;
                $previousKeyboard = $this->keyboardService->makeAddMealOptionsMenu();
                $messageText = 'Запись отменена. Выберите способ добавления.';
                unset($this->userSelections[$chatId]['diary_entry']); // Очищаем, т.к. начали ввод
            } elseif ($currentState === States::AWAITING_SEARCH_PRODUCT_NAME_ADD) {
                $previousState = States::AWAITING_DATE_SEARCH_ADD; // <-- Изменено
                $previousKeyboard = $this->keyboardService->makeBackOnly();
                $messageText = 'На какую дату записать прием пищи? (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":';
                unset($this->userSelections[$chatId]['diary_entry']['date']); // Очищаем только дату
            // ---> КОНЕЦ ИЗМЕНЕНИЯ <---
            } elseif ($currentState === States::AWAITING_GRAMS_MANUAL_ADD) { // <-- ИЗМЕНЕНО: Назад из ввода граммов теперь на ввод даты
                $previousState = States::AWAITING_DATE_MANUAL_ADD;
                $previousKeyboard = $this->keyboardService->makeBackOnly();
                $messageText = 'На какую дату записать прием пищи? (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":';
                // Очищаем только 'date', если она была сохранена на предыдущем шаге, остальное может понадобиться, если пользователь вернется сюда.
                // Но лучше очищать все, что было введено после AWAITING_DATE_MANUAL_ADD
                unset($this->userSelections[$chatId]['diary_entry']['date']); // Если 'date' было единственным, что мы сохранили до этого
            } elseif ($currentState === States::AWAITING_GRAMS_SEARCH_ADD) {
                $previousState = States::AWAITING_SEARCH_PRODUCT_NAME_ADD;
                $previousKeyboard = $this->keyboardService->makeBackOnly();
                $messageText = 'Название продукта из сохраненных:';
                unset($this->userSelections[$chatId]['diary_entry']['search_name_lower'], $this->userSelections[$chatId]['diary_entry']['search_name_original']);
            } elseif ($currentState === States::AWAITING_PRODUCT_NAME_MANUAL_ADD) { // Назад из ввода имени на ввод граммов
                $previousState = States::AWAITING_GRAMS_MANUAL_ADD;
                $previousKeyboard = $this->keyboardService->makeBackOnly();
                // Восстанавливаем дату в сообщении, если она есть
                $selectedDate = $this->userSelections[$chatId]['diary_entry']['date'] ?? date('Y-m-d'); // Берем сохраненную или текущую
                $messageText = 'Дата: ' . date('d.m.Y', strtotime($selectedDate)) . "\nМасса съеденного (г) (или \"Назад\"):";
                unset($this->userSelections[$chatId]['diary_entry']['grams']);
            } elseif ($currentState >= States::AWAITING_PROTEIN_MANUAL_ADD && $currentState <= States::AWAITING_CARBS_MANUAL_ADD) {
                // Возврат на предыдущий шаг ввода БЖУ
                $previousState = $currentState - 1; // Переход на предыдущее состояние БЖУ (P->Name, F->P, C->F)
                $promptKey = match ($previousState) {
                    States::AWAITING_PRODUCT_NAME_MANUAL_ADD => 'grams', // Если вернулись к вводу имени, предыдущим был ввод граммов
                    States::AWAITING_PROTEIN_MANUAL_ADD => 'name',
                    States::AWAITING_FAT_MANUAL_ADD => 'protein',
                    States::AWAITING_CARBS_MANUAL_ADD => 'fat',
                    default => null
                };
                $prevValue = $this->userSelections[$chatId]['diary_entry'][$promptKey] ?? '?';
                $messageText = match ($previousState) {
                    States::AWAITING_PRODUCT_NAME_MANUAL_ADD => "Граммы: {$prevValue}\nНазвание продукта:",
                    States::AWAITING_PROTEIN_MANUAL_ADD => "Название: {$prevValue}\nБелки(г) в порции:",
                    States::AWAITING_FAT_MANUAL_ADD => "Белки: {$prevValue}г\nЖиры(г) в порции:",
                    States::AWAITING_CARBS_MANUAL_ADD => "Жиры: {$prevValue}г\nУглеводы(г) в порции:",
                    default => 'Введите предыдущее значение:'
                };
                $keyToRemove = match ($currentState) { // Что удаляем из временного хранилища
                    States::AWAITING_PROTEIN_MANUAL_ADD => 'name',
                    States::AWAITING_FAT_MANUAL_ADD => 'protein',
                    States::AWAITING_CARBS_MANUAL_ADD => 'fat',
                    default => null
                };
                if ($keyToRemove && isset($this->userSelections[$chatId]['diary_entry'])) {
                    unset($this->userSelections[$chatId]['diary_entry'][$keyToRemove]);
                }
                $previousKeyboard = $this->keyboardService->makeBackOnly();
            } elseif ($currentState === States::AWAITING_ADD_MEAL_CONFIRM_SEARCH || $currentState === States::AWAITING_ADD_MEAL_CONFIRM_MANUAL) {
                // Возврат к выбору способа добавления
                $previousState = States::AWAITING_ADD_MEAL_OPTION;
                $previousKeyboard = $this->keyboardService->makeAddMealOptionsMenu();
                $messageText = 'Запись отменена. Выберите способ добавления.';
                unset($this->userSelections[$chatId]['diary_entry']); // Очищаем все временные данные для этой записи
            } elseif ($currentState === States::AWAITING_DATE_DELETE_MEAL || $currentState === States::AWAITING_DATE_VIEW_MEAL) {
                // Возврат в меню Дневника
                $previousState = States::DIARY_MENU;
                $previousKeyboard = $this->keyboardService->makeDiaryMenu();
                $messageText = 'Возврат в меню Дневника.';
                unset($this->userSelections[$chatId]['diary_delete']); // Очищаем, если что-то было для удаления
            } elseif ($currentState === States::AWAITING_MEAL_NUMBER_DELETE) {
                // Возврат к вводу даты для удаления
                $previousState = States::AWAITING_DATE_DELETE_MEAL;
                $previousKeyboard = $this->keyboardService->makeBackOnly();
                $messageText = 'Введите дату приема пищи для удаления (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":';
                unset($this->userSelections[$chatId]['diary_delete']); // Очищаем данные для удаления
            } elseif ($currentState === States::AWAITING_DELETE_MEAL_CONFIRM) {
                // Возврат в меню Дневника
                $previousState = States::DIARY_MENU;
                $previousKeyboard = $this->keyboardService->makeDiaryMenu();
                $messageText = 'Удаление отменено.';
                unset($this->userSelections[$chatId]['diary_delete']); // Очищаем данные для удаления
            }
    
            $this->userStates[$chatId] = $previousState;
            // Очистка временных данных, если это было подтверждение (остается)
            if (in_array($currentState, [
                States::AWAITING_ADD_MEAL_CONFIRM_MANUAL,
                States::AWAITING_ADD_MEAL_CONFIRM_SEARCH,
                States::AWAITING_DELETE_MEAL_CONFIRM
            ])) {
                // Дополнительно убедимся, что эти ключи удалены, если не были удалены выше
                unset($this->userSelections[$chatId]['diary_entry']);
                unset($this->userSelections[$chatId]['diary_delete']);
            }
    
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $messageText, 'reply_markup' => $previousKeyboard]);
            return true; // "Назад" обработано для Дневника
        }




        // ---> ДОБАВЛЕНО: Обработка "Назад" при Добавлении Нового Аккаунта <---
        if ($currentState >= States::AWAITING_NEW_ACCOUNT_NAME && $currentState <= States::AWAITING_NEW_ACCOUNT_PASSWORD) {
            // Отменяем добавление, возвращаемся в меню Аккаунта
            $this->userStates[$chatId] = States::DEFAULT; // Возвращаемся в состояние главного меню (откуда доступно меню Аккаунта)
            unset($this->userSelections[$chatId]['new_account_data']); // Очищаем временные данные
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Добавление нового аккаунта отменено.',
                'reply_markup' => $this->keyboardService->makeAccountMenu() // Показываем меню Аккаунта
            ]);
            return true; // "Назад" обработано
        }
        // ---> КОНЕЦ ДОБАВЛЕНИЯ <---

        // ---> ДОБАВЛЕНО: Обработка "Назад" при Переключении Аккаунта <---
        if ($currentState === States::AWAITING_ACCOUNT_SWITCH_SELECTION) {
            // Отменяем переключение, возвращаемся в меню Аккаунта
            $this->userStates[$chatId] = States::DEFAULT;
            unset($this->userSelections[$chatId]['account_switch_map']); // Очищаем временные данные
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Выбор аккаунта для переключения отменен.',
                'reply_markup' => $this->keyboardService->makeAccountMenu() // Показываем меню Аккаунта
            ]);
            return true; // "Назад" обработано
        }



        // Если ни одно из условий выше не сработало
        return false;
    }

        /**
     * Обрабатывает состояния регистрации или смены аккаунта.
     */
    private function handleRegistrationState(int $chatId, string $text, Message $message, int $currentState): void
    {

        if ($currentState === States::AWAITING_NAME) {
        if ($text === '⬅️ Назад') { // Предполагаем, что кнопка "Назад" может быть на этом этапе
            $this->userStates[$chatId] = States::DEFAULT;
            unset($this->userSelections[$chatId]['registration_data']);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Регистрация отменена.',
                'reply_markup' => $this->keyboardService->makeMainMenu() // Или removeKeyboard, если это начало /start
            ]);
            return;
        }

        $trimmedName = trim($text);
        if (empty($trimmedName)) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Имя не может быть пустым. Пожалуйста, введите ваше имя:',
                // Клавиатура здесь обычно удаляется или ставится 'Назад'
                'reply_markup' => $this->keyboardService->removeKeyboard()
            ]);
            // Состояние не меняем, ждем повторного ввода имени
            return;
        }

        // Сохраняем имя во временное хранилище
        $this->userSelections[$chatId]['registration_data'] = ['name' => $trimmedName];
        $this->userStates[$chatId] = States::AWAITING_EMAIL;
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Отлично, ' . $trimmedName . '! Теперь введите ваш Email адрес:',
            'reply_markup' => $this->keyboardService->removeKeyboard() // Убираем клавиатуру, ждем текст
        ]);
        return; // Выходим, ждем ввода email
        }

        // --- Шаг 2: Ожидание Email ---
        if ($currentState === States::AWAITING_EMAIL) {
            if ($text === '⬅️ Назад') { // Обработка "Назад" на этапе ввода email
                $this->userStates[$chatId] = States::AWAITING_NAME; // Возвращаемся к вводу имени
                // Очищаем только email из временных данных, имя оставляем
                unset($this->userSelections[$chatId]['registration_data']['email']);
                $currentName = $this->userSelections[$chatId]['registration_data']['name'] ?? '';
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Хорошо, вернемся к имени. Ваше имя: ' . $currentName . '. Если хотите изменить, введите новое, или подтвердите текущее (если была бы такая логика). Сейчас просто: Введите ваше имя:',
                    'reply_markup' => $this->keyboardService->removeKeyboard()
                ]);
                return;
            }

            $email = trim($text);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Некорректный формат email. Пожалуйста, введите правильный email адрес (например, user@example.com):',
                    'reply_markup' => $this->keyboardService->removeKeyboard()
                ]);
                // Состояние не меняем, ждем повторного ввода email
                return;
            }

            // Проверяем, есть ли уже данные регистрации (имя должно быть)
            if (!isset($this->userSelections[$chatId]['registration_data']['name'])) {
                Log::error("REGISTRATION: registration_data или имя не найдены при вводе email для chatId {$chatId}");
                $this->userStates[$chatId] = States::AWAITING_NAME; // Возвращаем на ввод имени
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка (не найдено ваше имя), пожалуйста, введите имя заново:', 'reply_markup' => $this->keyboardService->removeKeyboard()]);
                return;
            }

            $this->userSelections[$chatId]['registration_data']['email'] = $email;
            $this->userStates[$chatId] = States::AWAITING_PASSWORD;
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Email сохранен. Теперь введите пароль (мин. 8 символов, включая заглавные/строчные буквы, цифры и спецсимволы):',
                'reply_markup' => $this->keyboardService->removeKeyboard()
            ]);
            return; // Выходим, ждем ввода пароля
        }
        if ($currentState === States::AWAITING_PASSWORD) {
            $plainPassword = $text; // Пароль в текстовом виде

        // 1. Валидация пароля
        $passwordIsValid = true; $passwordErrors = [];
        // ... (полный код валидации пароля: длина, регистры, цифры, спецсимволы) ...
        if (strlen($plainPassword) < 8) { $passwordIsValid = false; $passwordErrors[] = "минимум 8 символов"; }
        if (!preg_match('/[A-Z]/', $plainPassword)) { $passwordIsValid = false; $passwordErrors[] = "заглавная буква"; }
        if (!preg_match('/[a-z]/', $plainPassword)) { $passwordIsValid = false; $passwordErrors[] = "строчная буква"; }
        if (!preg_match('/[0-9]/', $plainPassword)) { $passwordIsValid = false; $passwordErrors[] = "цифра"; }
        if (!preg_match('/[\'^£$%&*()}{@#~?><>,|=_+¬-]/', $plainPassword)) { $passwordIsValid = false; $passwordErrors[] = "спецсимвол"; }

        if (!$passwordIsValid) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Пароль не соответствует требованиям: " . implode(', ', $passwordErrors) . ".\nПожалуйста, введите пароль еще раз:",
                'reply_markup' => $this->keyboardService->removeKeyboard()
            ]);
            return;
        }

        $regData = $this->userSelections[$chatId]['registration_data'] ?? null;
        if (!$regData || !isset($regData['name']) || !isset($regData['email'])) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка регистрации: не найдены имя или email. Попробуйте /start заново.', 'reply_markup' => $this->keyboardService->removeKeyboard()]);
            $this->userStates[$chatId] = States::DEFAULT; unset($this->userSelections[$chatId]['registration_data']);
            return;
        }
        $name = $regData['name'];
        $email = $regData['email'];

        // --- Регистрация и получение токена для Nutrition Service ---
        $nutritionApiToken = $this->registerAndLoginNutritionService($chatId, $name, $email, $plainPassword, false); // false - не симулировать
        if (!$nutritionApiToken) {
            $this->userStates[$chatId] = States::DEFAULT; unset($this->userSelections[$chatId]['registration_data']);
            return; // Сообщение об ошибке уже отправлено внутри registerAndLoginNutritionService
        }

        // --- Регистрация и получение токена для Workout Service ---
        $workoutApiToken = $this->registerWorkoutService($chatId, $name, $email, $plainPassword, false); // false - не симулировать
        if (!$workoutApiToken) {
            // TODO: Подумать об "откате" регистрации в nutrition-service.
            $this->userStates[$chatId] = States::DEFAULT; unset($this->userSelections[$chatId]['registration_data']);
            return; // Сообщение об ошибке уже отправлено внутри registerWorkoutService
        }

        // --- Создание локального аккаунта в боте (только если оба токена получены) ---
        $hashedBotPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
        if ($hashedBotPassword === false) {
            Log::error("REGISTRATION: Ошибка хеширования пароля для бота (локально), chatId {$chatId}");
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Внутренняя ошибка при локальной обработке пароля. Регистрация отменена.', 'reply_markup' => $this->keyboardService->removeKeyboard()]);
            $this->userStates[$chatId] = States::DEFAULT; unset($this->userSelections[$chatId]['registration_data']);
            return;
        }

        $this->userData[$chatId] = [
            'active_account_email' => $email,
            'accounts' => [
                $email => [
                    'name' => $name, 'email' => $email, 'password' => $hashedBotPassword,
                    'nutrition_api_token' => $nutritionApiToken,
                    'workout_api_token' => $workoutApiToken
                ]
            ]
        ];

        $this->dataStorage->saveAllUserData($this->userData);
        unset($this->userSelections[$chatId]['registration_data']);
        $this->userStates[$chatId] = States::DEFAULT;

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Аккаунт '{$name}' ({$email}) успешно зарегистрирован во всех сервисах и в боте!",
            'reply_markup' => $this->keyboardService->makeMainMenu()
        ]);
        } // Конец if ($currentState === States::AWAITING_PASSWORD)
    }

    private function registerAndLoginNutritionService(int $chatId, string $name, string $email, string $plainPassword): ?string
    {
        $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
        $nutritionUserRegistered = false;

        // Этап 1: Регистрация
        try {
            $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/register';
            $payload = ['name' => $name, 'email' => $email, 'password' => $plainPassword];
            Log::info("NUTRITION REG: Requesting", ['url' => $serviceUrl, 'payload_info' => ['name' => $name, 'email' => $email]]);
            $response = $client->post($serviceUrl, ['json' => $payload, 'headers' => ['Accept' => 'application/json']]);
            $statusCode = $response->getStatusCode(); $responseBody = json_decode($response->getBody()->getContents(), true);
            Log::info("NUTRITION REG: Response", ['status' => $statusCode, 'body' => $responseBody]);

            if (!($statusCode === 201 && (isset($responseBody['email']) || isset($responseBody['id'])))) {
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Ошибка регистрации (питание): " . $this->extractErrorMessage($responseBody, 'питания'), 'reply_markup' => $this->keyboardService->removeKeyboard()]);
                return null;
            }
            Log::info("NUTRITION REG: User {$email} registered.");
            $nutritionUserRegistered = true;
        } catch (\Throwable $e) { $this->handleGuzzleError($e, $chatId, "питания (регистрация)"); return null; }

        // Этап 2: Логин для получения токена (только если регистрация прошла)
        if ($nutritionUserRegistered) {
            try {
                $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/login';
                $payload = ['email' => $email, 'password' => $plainPassword];
                Log::info("NUTRITION LOGIN: Requesting", ['url' => $serviceUrl, 'payload_info' => ['email' => $email]]);
                $response = $client->post($serviceUrl, ['json' => $payload, 'headers' => ['Accept' => 'application/json']]);
                $statusCode = $response->getStatusCode(); $responseBody = json_decode($response->getBody()->getContents(), true);
                Log::info("NUTRITION LOGIN: Response", ['status' => $statusCode, 'body' => $responseBody]);

                if ($statusCode === 200 && isset($responseBody['token'])) {
                    Log::info("NUTRITION LOGIN: Token received for {$email}.");
                    return $responseBody['token'];
                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Ошибка входа после регистрации (питание): " . $this->extractErrorMessage($responseBody, 'питания (вход)'), 'reply_markup' => $this->keyboardService->removeKeyboard()]);
                    return null;
                }
            } catch (\Throwable $e) { $this->handleGuzzleError($e, $chatId, "питания (вход)"); return null; }
        }
        return null; // Если регистрация не прошла
    }

    private function registerWorkoutService(int $chatId, string $name, string $email, string $plainPassword): ?string
    {
        $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);

        try {
            $serviceUrl = env('WORKOUT_SERVICE_BASE_URI', 'http://localhost:8001') . '/api/v1/register';
            $payload = ['name' => $name, 'email' => $email, 'password' => $plainPassword];
            Log::info("WORKOUT REG: Requesting", ['url' => $serviceUrl, 'payload_info' => ['name' => $name, 'email' => $email]]);
            $response = $client->post($serviceUrl, ['json' => $payload, 'headers' => ['Accept' => 'application/json']]);
            $statusCode = $response->getStatusCode(); $responseBody = json_decode($response->getBody()->getContents(), true);
            Log::info("WORKOUT REG: Response", ['status' => $statusCode, 'body' => $responseBody]);

            if ($statusCode === 201 && isset($responseBody['data']['access_token'])) {
                Log::info("WORKOUT REG: Token received for {$email}.");
                return $responseBody['data']['access_token'];
            } else {
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Ошибка регистрации (тренировки): " . $this->extractErrorMessage($responseBody, 'тренировок'), 'reply_markup' => $this->keyboardService->removeKeyboard()]);
                return null;
            }
        } catch (\Throwable $e) { $this->handleGuzzleError($e, $chatId, "тренировок (регистрация)"); return null; }
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
                        if ($text === '✅ Да') {
                            $activeEmail = $this->getActiveAccountEmail($chatId); // Все еще нужен для получения токена
                            if (!$activeEmail) {
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Активный аккаунт не определен.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                                $this->userStates[$chatId] = States::BJU_MENU; unset($this->userSelections[$chatId]['bju_product']);
                                break;
                            }

                            $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;
                            if (!$nutritionToken) {
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Токен для сервиса питания не найден.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                                $this->userStates[$chatId] = States::BJU_MENU; unset($this->userSelections[$chatId]['bju_product']);
                                break;
                            }

                            $productDataFromSelection = $this->userSelections[$chatId]['bju_product'] ?? null;

                            if ($productDataFromSelection && isset($productDataFromSelection['name'])) {
                                // --- НОВЫЙ PAYLOAD СОГЛАСНО API ---
                                $payload = [
                                    'food_name' => $productDataFromSelection['name'],
                                    'proteins' => (float) $productDataFromSelection['protein'],
                                    'fats' => (float) $productDataFromSelection['fat'],
                                    'carbs' => (float) $productDataFromSelection['carbs']
                                    // Калории и user_email не передаем
                                ];

                                try {
                                    $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                                    // --- НОВЫЙ URL ЭНДПОИНТА ---
                                    $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/saved-foods';

                                    Log::info("NUTRITION SAVE FOOD: Requesting", ['url' => $serviceUrl, 'payload' => $payload]);

                                    $response = $client->post($serviceUrl, [
                                        'json' => $payload,
                                        'headers' => [
                                            'Accept' => 'application/json',
                                            'Authorization' => 'Bearer ' . $nutritionToken
                                        ]
                                    ]);

                                    $statusCode = $response->getStatusCode();
                                    $responseBody = json_decode($response->getBody()->getContents(), true);

                                    Log::info("NUTRITION SAVE FOOD: Response", ['status' => $statusCode, 'body' => $responseBody]);

                                    // --- НОВАЯ ПРОВЕРКА ОТВЕТА ---
                                    if ($statusCode === 201 && isset($responseBody['message']) && $responseBody['message'] === "Food saved successfully" && isset($responseBody['data']['food_name'])) {
                                        $this->telegram->sendMessage([
                                            'chat_id' => $chatId,
                                            'text' => "Продукт '{$responseBody['data']['food_name']}' успешно сохранен на сервере!",
                                            'reply_markup' => $this->keyboardService->makeBjuMenu()
                                        ]);
                                    } else {
                                        $errorMessage = $responseBody['message'] ?? ($responseBody['error'] ?? 'Неизвестная ошибка от сервера.'); // API может вернуть 'error'
                                        if (isset($responseBody['errors'])) { // Для ошибок валидации Laravel
                                            $errorMessages = [];
                                            foreach ($responseBody['errors'] as $fieldErrors) { $errorMessages = array_merge($errorMessages, $fieldErrors); }
                                            $errorMessage = implode(' ', $errorMessages);
                                        }
                                        Log::warning("NUTRITION SAVE FOOD: Ошибка сохранения", ['status_code' => $statusCode, 'body' => $responseBody, 'sent_payload' => $payload]);
                                        $this->telegram->sendMessage([
                                            'chat_id' => $chatId,
                                            'text' => "Ошибка при сохранении продукта на сервере: {$errorMessage}",
                                            'reply_markup' => $this->keyboardService->makeBjuMenu()
                                        ]);
                                    }
                                } catch (\Throwable $e) {
                                    $this->handleGuzzleError($e, $chatId, "питания (сохранение продукта)");
                                }
                            } else {
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Не удалось получить данные продукта для сохранения.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                            }
                            $this->userStates[$chatId] = States::BJU_MENU;
                            unset($this->userSelections[$chatId]['bju_product']);

                        } elseif ($text === '❌ Нет') {
                            // ... (код отмены остается) ...
                        } else {
                            // ... (код "Нажмите Да/Нет" остается) ...
                        }
                        break;

                    
                case States::AWAITING_PRODUCT_NUMBER_DELETE:
                    $productMap = $this->userSelections[$chatId]['product_to_delete_map'] ?? null;
                    if (!$productMap) {
                        Log::error("DELETE PRODUCT: product_to_delete_map не найден для chatId {$chatId}");
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка. Попробуйте снова выбрать "Удалить продукт".', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                        $this->userStates[$chatId] = States::BJU_MENU; unset($this->userSelections[$chatId]['product_to_delete_map']);
                        break;
                    }

                    if (!ctype_digit($text) || !isset($productMap[(int)$text])) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Неверный номер. Введите номер продукта из списка или "Назад".', 'reply_markup' => $this->keyboardService->makeBackOnly()]);
                        break; // Остаемся в том же состоянии
                    }

                    $selectedNumber = (int)$text;
                    $productIdToDelete = $productMap[$selectedNumber];

                    // Найдем имя продукта для подтверждения (опционально, но улучшает UX)
                    $productNameToConfirm = "Продукт с ID: {$productIdToDelete}"; // Запасное имя
                    // Можно снова запросить все продукты и найти имя по ID, но это лишний запрос.
                    // Если список не очень длинный, можно было бы передать и имена в userSelections.
                    // Или можно было сохранить весь объект продукта в productMap.
                    // Сейчас оставим просто ID для краткости подтверждения.

                    $this->userSelections[$chatId]['product_id_to_delete'] = $productIdToDelete;
                    $this->userStates[$chatId] = States::AWAITING_DELETE_CONFIRMATION;
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Вы уверены, что хотите удалить {$productNameToConfirm}?",
                        'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                    ]);
                    // Очищаем карту номеров, она больше не нужна
                    unset($this->userSelections[$chatId]['product_to_delete_map']);
                    break;

                case States::AWAITING_DELETE_CONFIRMATION:
                    $productIdToDelete = $this->userSelections[$chatId]['product_id_to_delete'] ?? null;
                    if (!$productIdToDelete) {
                        Log::error("DELETE PRODUCT CONFIRM: product_id_to_delete не найден для chatId {$chatId}");
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка подтверждения удаления. Попробуйте снова.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                        $this->userStates[$chatId] = States::BJU_MENU; unset($this->userSelections[$chatId]['product_id_to_delete']);
                        break;
                    }

                    if ($text === '✅ Да') {
                        $activeEmail = $this->getActiveAccountEmail($chatId);
                        $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;
                        if (!$activeEmail || !$nutritionToken) { /* ... ошибка нет аккаунта/токена ... */ $this->userStates[$chatId] = States::BJU_MENU; unset($this->userSelections[$chatId]['product_id_to_delete']); break; }

                        try {
                            $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                            $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . "/api/v1/saved-foods/" . $productIdToDelete;

                            Log::info("NUTRITION DELETE PRODUCT: Requesting", ['url' => $serviceUrl, 'id' => $productIdToDelete]);

                            $response = $client->delete($serviceUrl, [ // Используем метод DELETE
                                'headers' => [
                                    'Accept' => 'application/json',
                                    'Authorization' => 'Bearer ' . $nutritionToken
                                ]
                            ]);
                            $statusCode = $response->getStatusCode();
                            $responseBody = json_decode($response->getBody()->getContents(), true);
                            Log::info("NUTRITION DELETE PRODUCT: Response", ['status' => $statusCode, 'body' => $responseBody]);

                            if ($statusCode === 200 && isset($responseBody['message']) && $responseBody['message'] === "Food deleted successfully") {
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Продукт успешно удален с сервера.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                            } else {
                                $errorMessage = $this->extractErrorMessage($responseBody, 'питания (удаление продукта)');
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Не удалось удалить продукт: {$errorMessage}", 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                            }
                        } catch (\Throwable $e) { $this->handleGuzzleError($e, $chatId, "питания (удаление продукта)"); }

                    } elseif ($text === '❌ Нет') {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Удаление отменено.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                    } else {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, нажмите "✅ Да" или "❌ Нет".', 'reply_markup' => $this->keyboardService->makeConfirmYesNo()]);
                        // Остаемся в состоянии AWAITING_DELETE_CONFIRMATION
                        break; // Выходим из switch, но не из if ($text === '✅ Да')
                    }
                    // Сброс состояния и временных данных после Да/Нет
                    $this->userStates[$chatId] = States::BJU_MENU;
                    unset($this->userSelections[$chatId]['product_id_to_delete']);
                    break;
            case States::AWAITING_PRODUCT_NAME_SEARCH:
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Активный аккаунт не определен.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                    $this->userStates[$chatId] = States::BJU_MENU;
                    break;
                }

                $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;
                if (!$nutritionToken) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Токен для сервиса питания не найден.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                    $this->userStates[$chatId] = States::BJU_MENU;
                    break;
                }

                $searchTermLower = trim(mb_strtolower($text));
                if (empty($searchTermLower)) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, введите название продукта для поиска.', 'reply_markup' => $this->keyboardService->makeBackOnly()]);
                    // Остаемся в том же состоянии
                    break;
                }

                try {
                    $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                    // Запрашиваем ВСЕ продукты пользователя, чтобы потом искать среди них
                    $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/saved-foods';
                    // Пока не используем пагинацию, предполагаем, что продуктов не слишком много для одного запроса,
                    // или что API вернет первую страницу по умолчанию. Для надежности можно запросить больше,
                    // например, 'query' => ['per_page' => 100] (если API поддерживает такой большой per_page)

                    Log::info("NUTRITION PRODUCT SEARCH (FETCH ALL): Запрос всех продуктов для поиска", ['url' => $serviceUrl, 'email' => $activeEmail, 'searchTerm' => $searchTermLower]);

                    $response = $client->get($serviceUrl, [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $nutritionToken
                        ]
                        // 'query' => ['per_page' => 100] // Опционально, запросить больше продуктов
                    ]);

                    $statusCode = $response->getStatusCode();
                    $responseBody = json_decode($response->getBody()->getContents(), true);

                    Log::info("NUTRITION PRODUCT SEARCH (FETCH ALL): Ответ от сервиса", ['status' => $statusCode, 'searchTerm' => $searchTermLower]);

                    if ($statusCode === 200 && isset($responseBody['data'])) {
                        $allProducts = $responseBody['data'];
                        $foundProduct = null;

                        if (!empty($allProducts)) {
                            foreach ($allProducts as $product) {
                                if (isset($product['food_name']) && mb_strtolower($product['food_name']) === $searchTermLower) {
                                    $foundProduct = $product;
                                    break;
                                }
                            }
                        }

                        if ($foundProduct) {
                            // Используем поля из API
                            $resultMsg = sprintf(
                                "Найден: %s (ID: %s)\nБ: %s, Ж: %s, У: %s, К: %s / 100г",
                                $foundProduct['food_name'],
                                $foundProduct['id'] ?? 'N/A',
                                $foundProduct['proteins'] ?? '0',
                                $foundProduct['fats'] ?? '0',
                                $foundProduct['carbs'] ?? '0',
                                $foundProduct['kcal'] ?? '0'
                            );
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => $resultMsg,
                                'reply_markup' => $this->keyboardService->makeBjuMenu()
                            ]);
                        } else {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Продукт '{$text}' не найден в ваших сохраненных.",
                                'reply_markup' => $this->keyboardService->makeBjuMenu()
                            ]);
                        }
                    } else {
                        $errorMessage = $this->extractErrorMessage($responseBody, 'питания (поиск продукта)');
                        Log::warning("NUTRITION PRODUCT SEARCH (FETCH ALL): Ошибка получения списка продуктов для поиска", ['status_code' => $statusCode, 'body' => $responseBody]);
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Не удалось выполнить поиск: {$errorMessage}",
                            'reply_markup' => $this->keyboardService->makeBjuMenu()
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->handleGuzzleError($e, $chatId, "питания (поиск продукта)");
                }
                // После поиска всегда возвращаемся в меню БЖУ
                $this->userStates[$chatId] = States::BJU_MENU;
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
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) { /* Ошибка */ return; }
                if ($text === '🔍 Поиск в базе') {
                    if (empty($this->userProducts[$chatId][$activeEmail])) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Сначала сохраните продукты в "БЖУ продуктов".',
                            'reply_markup' => $this->keyboardService->makeAddMealOptionsMenu()
                        ]);
                    } else {
                        // ---> ИЗМЕНЕНО: Переходим на ввод даты <---
                        $this->userStates[$chatId] = States::AWAITING_DATE_SEARCH_ADD;
                        // Очищаем предыдущие данные, если были
                        unset($this->userSelections[$chatId]['diary_entry']);
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'На какую дату записать прием пищи? (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    }
                } elseif ($text === '✍️ Записать БЖУ вручную') {
                    $this->userStates[$chatId] = States::AWAITING_DATE_MANUAL_ADD;
                    // Очищаем предыдущие данные, если были
                    unset($this->userSelections[$chatId]['diary_entry']);
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'На какую дату записать прием пищи? (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":',
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
            case States::AWAITING_DATE_SEARCH_ADD:
                $dateToLog = null;
                // ... (код парсинга даты: 'сегодня', 'вчера', ДД.ММ.ГГГГ - точно такой же, как в AWAITING_DATE_MANUAL_ADD) ...
                $normalizedText = strtolower(trim($text));
                if ($normalizedText === 'вчера') { $dateToLog = date('Y-m-d', strtotime('-1 day')); }
                elseif ($normalizedText === 'сегодня') { $dateToLog = date('Y-m-d'); }
                elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $text, $matches)) {
                    if (checkdate($matches[2], $matches[1], $matches[3])) { $dateToLog = "{$matches[3]}-{$matches[2]}-{$matches[1]}"; }
                }
    
                if (!$dateToLog) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Некорректный формат даты. Введите ДД.ММ.ГГГГ, "сегодня" или "вчера", или "Назад".',
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                } else {
                    // Сохраняем дату во временное хранилище
                    $this->userSelections[$chatId]['diary_entry'] = ['date' => $dateToLog];
                    // Переходим к вводу названия продукта
                    $this->userStates[$chatId] = States::AWAITING_SEARCH_PRODUCT_NAME_ADD;
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Дата: ' . date('d.m.Y', strtotime($dateToLog)) . "\nНазвание продукта из сохраненных (или \"Назад\"):",
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                }
                break;
            case States::AWAITING_DATE_MANUAL_ADD:
                $dateToLog = null;
                $normalizedText = strtolower(trim($text));
                if ($normalizedText === 'вчера') {
                    $dateToLog = date('Y-m-d', strtotime('-1 day'));
                } elseif ($normalizedText === 'сегодня') {
                    $dateToLog = date('Y-m-d');
                } elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $text, $matches)) {
                    if (checkdate($matches[2], $matches[1], $matches[3])) {
                        $dateToLog = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
                    }
                }
    
                if (!$dateToLog) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Некорректный формат даты. Введите ДД.ММ.ГГГГ, "сегодня" или "вчера", или "Назад".',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                        // Состояние не меняем, даем еще попытку
                } else {
                    // Сохраняем дату во временное хранилище
                    $this->userSelections[$chatId]['diary_entry'] = ['date' => $dateToLog];
                    // Переходим к вводу граммов
                    $this->userStates[$chatId] = States::AWAITING_GRAMS_MANUAL_ADD;
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Дата: ' . date('d.m.Y', strtotime($dateToLog)) . "\nМасса съеденного (г) (или \"Назад\"):",
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                }
                break;

            case States::AWAITING_SEARCH_PRODUCT_NAME_ADD:
                $activeEmail = $this->getActiveAccountEmail($chatId);
             if (!$activeEmail) { /* Ошибка */ return; }

             // Убедимся, что 'diary_entry' уже существует (должен быть создан на шаге ввода даты)
             if (!isset($this->userSelections[$chatId]['diary_entry'])) {
                 Log::error("Ошибка: diary_entry не установлен перед вводом имени продукта (поиск) для chatId {$chatId}");
                 $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка. Пожалуйста, начните заново.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                 $this->userStates[$chatId] = States::DIARY_MENU;
                 return;
             }

            $productNameLower = trim(mb_strtolower($text));
                $productNameLower = trim(mb_strtolower($text));
                if (isset($this->userProducts[$chatId][$activeEmail][$productNameLower])) {
                    $originalName = $this->findOriginalProductName($chatId, $productNameLower, $activeEmail);
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
                // ---> ПОЛУЧАЕМ АКТИВНЫЙ EMAIL <---
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) { /* Ошибка */ return; }
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
                        if (!isset($this->userProducts[$chatId][$activeEmail][$productNameLower])) {
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Данные продукта не найдены. Попробуйте снова.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                            $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']); return;
                        }
                        $baseBJU = $this->userProducts[$chatId][$activeEmail][$productNameLower];
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
                if ($text === '✅ Да') {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    if (!$activeEmail) { /* Ошибка */ return; }
                    $logData = $this->userSelections[$chatId]['diary_entry']['log'] ?? null;
                    $logDate = $this->userSelections[$chatId]['diary_entry']['date'] ?? date('Y-m-d');
                    if ($logData) {
                        if (!isset($this->diaryData[$chatId][$activeEmail])) {
                            $this->diaryData[$chatId][$activeEmail] = [];
                        }
                        if (!isset($this->diaryData[$chatId][$activeEmail][$logDate])) {
                            $this->diaryData[$chatId][$activeEmail][$logDate] = [];
                        }
                        $this->diaryData[$chatId][$activeEmail][$logDate][] = $logData;
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
                } elseif ($text === '❌ Нет') {
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
                    // Убедимся, что 'diary_entry' уже существует (должен быть создан на шаге ввода даты)
                    if (!isset($this->userSelections[$chatId]['diary_entry'])) {
                        // Этого не должно произойти, если логика верна
                        Log::error("Ошибка: diary_entry не установлен перед вводом грамм для chatId {$chatId}");
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка. Пожалуйста, начните заново.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                        $this->userStates[$chatId] = States::DIARY_MENU;
                        return;
                    }
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
                if ($text === '✅ Да') {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    if (!$activeEmail) { /* Ошибка */ return; }
                    $logData = $this->userSelections[$chatId]['diary_entry'] ?? null;
                    $logDate = $logData['date'] ?? date('Y-m-d');
                    if ($logData && isset($logData['name']) && isset($logData['grams'])) {
                        unset($logData['date']);
                        if (!isset($this->diaryData[$chatId][$activeEmail])) {
                            $this->diaryData[$chatId][$activeEmail] = [];
                        }
                        if (!isset($this->diaryData[$chatId][$activeEmail][$logDate])) {
                            $this->diaryData[$chatId][$activeEmail][$logDate] = [];
                        }
                       $this->diaryData[$chatId][$activeEmail][$logDate][] = $logData;
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
                } elseif ($text === '❌ Нет') {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Запись отменена.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_entry']);
                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Нажмите "Да" или "Нет".', 'reply_markup' => $this->keyboardService->makeConfirmYesNo() ]);
                }
                break;

            // --- Удаление приема пищи ---
            case States::AWAITING_DATE_DELETE_MEAL:
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) { /* Ошибка */ return; }
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
                if (empty($this->diaryData[$chatId][$activeEmail][$dateToDelete])) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Нет записей за {$formattedDate}. Возврат в меню Дневника.", 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    break;
                }

                // Выводим список и ждем номер
                $mealListMsg = "Приемы пищи за {$formattedDate}:\n\n";
                $i = 1;
                $mealsForSelection = [];
                foreach ($this->diaryData[$chatId][$activeEmail][$dateToDelete] as $index => $entry) {
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
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) { /* Ошибка */ return; }
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

                    if (!isset($this->diaryData[$chatId][$activeEmail][$dateToDelete][$indexToDelete])) {
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
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) { /* Ошибка */ return; }
                if ($text === '✅ Да') {
                    $dateToDelete = $this->userSelections[$chatId]['diary_delete']['date'] ?? null;
                    $indexToDelete = $this->userSelections[$chatId]['diary_delete']['index'] ?? null;
                    $entryName = $this->userSelections[$chatId]['diary_delete']['entry']['name'] ?? '???';

                    if ($dateToDelete && $indexToDelete !== null && isset($this->diaryData[$chatId][$activeEmail][$dateToDelete][$indexToDelete])) {
                        unset($this->diaryData[$chatId][$activeEmail][$dateToDelete][$indexToDelete]);
                        $this->diaryData[$chatId][$activeEmail][$dateToDelete] = array_values($this->diaryData[$chatId][$activeEmail][$dateToDelete]);
                        if (empty($this->diaryData[$chatId][$activeEmail][$dateToDelete])) { unset($this->diaryData[$chatId][$activeEmail][$dateToDelete]); }
                        if (empty($this->diaryData[$chatId][$activeEmail])) { unset($this->diaryData[$chatId][$activeEmail]); }
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
                } elseif ($text === '❌ Нет') {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Удаление отменено.', 'reply_markup' => $this->keyboardService->makeDiaryMenu() ]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_delete']);
                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Нажмите "Да" или "Нет".', 'reply_markup' => $this->keyboardService->makeConfirmYesNo() ]);
                }
                break;

            // --- Просмотр рациона ---
            case States::AWAITING_DATE_VIEW_MEAL:
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) { /* Ошибка */ return; }
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
                if (empty($this->diaryData[$chatId][$activeEmail][$dateToView])) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId, 'text' => "Нет записей за {$formattedDate}.", 'reply_markup' => $this->keyboardService->makeDiaryMenu()
                    ]);
                } else {
                    $totalP = 0; $totalF = 0; $totalC = 0; $totalKcal = 0;
                    $viewMsg = "Рацион за {$formattedDate}:\n\n";
                    $i = 1;
                    foreach ($this->diaryData[$chatId][$activeEmail][$dateToView] as $entry) {
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
                        }elseif ($mode === 'technique') { // Режим просмотра техники
                            $this->userStates[$chatId] = States::DEFAULT; // Возвращаемся в главное меню
                            unset($this->userSelections[$chatId]); // Очищаем выбор
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Упражнение: {$selectedExercise}\nТехника выполнения:\nВИДЕО", // Выводим заглушку "ВИДЕО"
                                'reply_markup' => $this->keyboardService->makeTrainingMenu() // Возвращаем меню Тренировок
                            ]);
                        
                        }else {
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
                // ---> ИЗМЕНЕНО: Логика для мультиаккаунта <---
                if (isset($this->userData[$chatId])) {
                    // Пользователь существует, приветствуем по активному аккаунту
                    $activeAccountData = $this->getActiveAccountData($chatId);
                    if ($activeAccountData) {
                        $name = $activeAccountData['name'] ?? 'пользователь';
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "С возвращением, {$name}! (Активный аккаунт: {$activeAccountData['email']})", // Добавим email для ясности
                            'reply_markup' => $this->keyboardService->makeMainMenu()
                        ]);
                    } else {
                        // Ошибка: пользователь есть, но активный аккаунт не найден
                        // Возможно, стоит предложить выбрать аккаунт или создать новый
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Ошибка: не удалось определить активный аккаунт. Попробуйте выбрать аккаунт через меню.",
                            'reply_markup' => $this->keyboardService->makeAccountMenu() // Показываем меню аккаунта
                        ]);
                    }
                    $this->userStates[$chatId] = States::DEFAULT;
                } else {
                    // Пользователя нет - начинаем регистрацию ПЕРВОГО аккаунта
                    $this->userStates[$chatId] = States::AWAITING_NAME;
                    // Не нужно инициализировать $this->userData[$chatId] здесь
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Добро пожаловать! Давайте зарегистрируем ваш первый аккаунт.\nВведите ваше имя:",
                        'reply_markup' => $this->keyboardService->removeKeyboard()
                    ]);
                }
                 // ---> КОНЕЦ ИЗМЕНЕНИЯ <---
                break; // Не забываем break
            


            case '⚙️ Аккаунт':
                 if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Настройки аккаунта:',
                        'reply_markup' => $this->keyboardService->makeAccountMenu()
                    ]);
                    // Можно установить состояние $this->userStates[$chatId] = States::ACCOUNT_MENU;, если нужно
                 } // Иначе игнорируем, если мы не в главном меню
                break;
            case 'ℹ️ Имя и почта':
                    // ---> ИЗМЕНЕНО: Используем активный аккаунт <---
                    if (in_array($currentState, [States::DEFAULT, States::BJU_MENU, States::DIARY_MENU /*, States::ACCOUNT_MENU */])) { // Добавил другие меню на всякий случай
                        $activeAccountData = $this->getActiveAccountData($chatId);
                        if ($activeAccountData) {
                            $name = $activeAccountData['name'] ?? 'Не указано';
                            $email = $activeAccountData['email'] ?? 'Не указан';
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Активный аккаунт:\nИмя: {$name}\nПочта: {$email}",
                                'reply_markup' => $this->keyboardService->makeAccountMenu()
                            ]);
                        } else {
                            // Этой ситуации не должно быть, если пользователь зарегистрирован
                             $this->telegram->sendMessage([
                                 'chat_id' => $chatId,
                                 'text' => 'Ошибка: Активный аккаунт не найден.',
                                 'reply_markup' => $this->keyboardService->makeMainMenu()
                             ]);
                              $this->userStates[$chatId] = States::DEFAULT; // Сброс на всякий случай
                        }
                    }
                     // ---> КОНЕЦ ИЗМЕНЕНИЯ <---
                break; // Не забываем break
            
            case '🤸 Посмотреть технику':
                    // Проверяем, что мы в главном меню или меню тренировок
                    if (in_array($currentState, [States::DEFAULT, States::LOGGING_TRAINING_MENU, States::DIARY_MENU, States::BJU_MENU /*, States::TRAINING_MENU */])) { // Добавил другие состояния, чтобы кнопка работала из разных мест главного уровня
                        $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP; // Начинаем выбор упражнения
                        $this->userSelections[$chatId] = ['mode' => 'technique']; // Устанавливаем режим просмотра техники
                        $groupKeys = array_keys($this->exercises);
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Для просмотра техники, выберите группу мышц:\n" . $this->generateListMessage($groupKeys),
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    }
                   break;
            case '➕ Добавить аккаунт':
                        if (in_array($currentState, [States::DEFAULT, States::BJU_MENU, States::DIARY_MENU /*, States::ACCOUNT_MENU */])) { // Проверка текущего меню
                            // Начинаем процесс добавления нового аккаунта
                            $this->userStates[$chatId] = States::AWAITING_NEW_ACCOUNT_NAME;
                            // Очищаем временные данные, если они были
                            unset($this->userSelections[$chatId]['new_account_data']);
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Добавление нового аккаунта.\nВведите имя для нового аккаунта:",
                                'reply_markup' => $this->keyboardService->makeBackOnly()
                            ]);
                        }
                break;

            case '🔄 Переключить аккаунт':
                if (in_array($currentState, [States::DEFAULT, States::BJU_MENU, States::DIARY_MENU /*, States::ACCOUNT_MENU */])) {
                    // Проверяем, есть ли вообще аккаунты у пользователя
                    if (!isset($this->userData[$chatId]['accounts']) || count($this->userData[$chatId]['accounts']) < 1) {
                            // Этой ситуации быть не должно, если пользователь зарегистрирован
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Не найдено ни одного аккаунта.', 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                            $this->userStates[$chatId] = States::DEFAULT;
                    } elseif (count($this->userData[$chatId]['accounts']) === 1) {
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'У вас только один аккаунт.', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                    } else {
                            // Формируем список аккаунтов
                            $accountListMsg = "Выберите аккаунт для переключения:\n\n";
                            $i = 1;
                            $accountsForSelection = []; // [номер => email]
                            $activeEmail = $this->getActiveAccountEmail($chatId); // Получаем текущий активный
    
                            // Сортируем по email для единообразия
                            $sortedAccounts = $this->userData[$chatId]['accounts'];
                            ksort($sortedAccounts);
    
                            foreach ($sortedAccounts as $email => $accData) {
                                $isActive = ($email === $activeEmail) ? ' (активный)' : '';
                                $accountListMsg .= sprintf("%d. %s (%s)%s\n", $i, $accData['name'], $accData['email'], $isActive);
                                $accountsForSelection[$i] = $email;
                                $i++;
                            }
                            $accountListMsg .= "\nВведите номер аккаунта:";
    
                            // Сохраняем карту выбора и переходим в состояние ожидания номера
                            $this->userSelections[$chatId]['account_switch_map'] = $accountsForSelection;
                            $this->userStates[$chatId] = States::AWAITING_ACCOUNT_SWITCH_SELECTION;
    
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => $accountListMsg,
                                'reply_markup' => $this->keyboardService->removeKeyboard() // Ждем ввода номера
                            ]);
                    }
                }
                break;

            // --- Меню Тренировки и его подпункты ---
            case '💪 Тренировки':
                if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Раздел тренировок:',
                        'reply_markup' => $this->keyboardService->makeTrainingMenu()
                    ]);
                    // Можно установить состояние $this->userStates[$chatId] = States::TRAINING_MENU;
                 }
                break;
            case '➕ Записать тренировку':
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
            case '📈 Посмотреть прогресс':
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
            case '📊 Отстающие группы':
                 if ($currentState === States::DEFAULT /* || $currentState === States::TRAINING_MENU */) {
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => "Анализ отстающих групп мышц:\n(у тебя все отстающее, иди качаться дрищ!)",
                         'reply_markup' => $this->keyboardService->makeTrainingMenu() // Остаемся в меню тренировок
                     ]);
                 }
                break;

            // --- Кнопки меню Записи Тренировки ---
            case '➕ Добавить упражнение':
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
            case '✅ Завершить запись':
                if ($currentState === States::LOGGING_TRAINING_MENU) {
                    $logCount = isset($this->currentTrainingLog[$chatId]) ? count($this->currentTrainingLog[$chatId]) : 0;
                    if ($logCount > 0) {
                        // ---> ДОБАВЛЕНА ЛОГИКА СОХРАНЕНИЯ <---
                        $activeEmail = $this->getActiveAccountEmail($chatId);
                        if (!$activeEmail) {
                                Log::error("Не удалось получить активный email для chatId {$chatId} при сохранении тренировки.");
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка сохранения тренировки: не удалось определить аккаунт.', 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                                // Сбрасываем состояние и лог, но не сохраняем
                                $this->userStates[$chatId] = States::DEFAULT; unset($this->currentTrainingLog[$chatId]); unset($this->userSelections[$chatId]);
                                break; // Выходим из case
                        }
    
                        $currentTimestamp = time(); // Получаем текущее время
                        $currentDate = date('Y-m-d', $currentTimestamp); // Дата из timestamp
                        $logToSave = $this->currentTrainingLog[$chatId]; // Упражнения из текущей сессии

                        // Создаем объект тренировки
                        $trainingSession = [
                            'date' => $currentDate,
                            'timestamp' => $currentTimestamp,
                            'log' => $logToSave
                        ];

                        // Инициализируем массив для chatId, если его нет
                        if (!isset($this->trainingLogData[$chatId])) {
                            $this->trainingLogData[$chatId] = [];
                        }
                        // Инициализируем массив для email, если его нет
                        if (!isset($this->trainingLogData[$chatId][$activeEmail])) {
                            $this->trainingLogData[$chatId][$activeEmail] = [];
                        }

                        // Добавляем новую тренировку в КОНЕЦ массива для данного аккаунта
                        $this->trainingLogData[$chatId][$activeEmail][] = $trainingSession;

                        // Сохраняем все данные логов тренировок
                        if ($this->dataStorage->saveAllTrainingLogData($this->trainingLogData)) {
                            Log::info("Тренировка сохранена для chatId {$chatId}, email {$activeEmail}, timestamp {$currentTimestamp}");
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Тренировка завершена и записана ({$logCount} подходов/упр.). Отличная работа!",
                                'reply_markup' => $this->keyboardService->makeMainMenu()
                        ]);
                        } else {
                            Log::error("Не удалось сохранить файл логов тренировок.");
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка при сохранении тренировки.', 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                        }
                        // ---> КОНЕЦ ЛОГИКИ СОХРАНЕНИЯ <---
    
                    } else { // Если logCount == 0
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Вы не добавили ни одного упражнения/подхода. Запись отменена.',
                            'reply_markup' => $this->keyboardService->makeTrainingMenu() // Возврат в меню тренировок
                        ]);
                    }
                    // Сброс состояния и временных данных в любом случае
                    $this->userStates[$chatId] = States::DEFAULT;
                    unset($this->currentTrainingLog[$chatId]); // Очищаем временный лог
                    unset($this->userSelections[$chatId]);
                }
                break; // Конец case

            

                // --- Меню Питание и его подпункты ---
            case '🍎 Питание':
                if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Раздел питания:',
                        'reply_markup' => $this->keyboardService->makeNutritionMenu()
                    ]);
                    // Можно установить $this->userStates[$chatId] = States::NUTRITION_MENU;
                 }
                break;
            case '📖 Дневник':
                if ($currentState === States::DEFAULT /* || $currentState === States::NUTRITION_MENU */) {
                     $this->userStates[$chatId] = States::DIARY_MENU; // Переходим в меню дневника
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => "Дневник питания:",
                         'reply_markup' => $this->keyboardService->makeDiaryMenu()
                     ]);
                 }
                break;
            case '🔍 БЖУ продуктов':
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
            case '➕ Записать приём пищи':
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
            case '🗑️ Удалить приём пищи':
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) { /* Ошибка */ return; }
                 if ($currentState === States::DIARY_MENU) {
                     if (!isset($this->diaryData[$chatId][$activeEmail]) || empty($this->diaryData[$chatId][$activeEmail])) {
                         $this->telegram->sendMessage([
                             'chat_id' => $chatId,
                             'text' => 'Ваш дневник питания для текущего аккаунта пока пуст. Нечего удалять.',
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
            case '🗓️ Посмотреть рацион':
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
            case '💾 Сохранить продукт':
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
            case '🗑️ Удалить продукт':
                if (in_array($currentState, [States::DEFAULT, States::BJU_MENU])) {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    if (!$activeEmail) { /* ... ошибка нет аккаунта ... */ break; }
                    $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;
                    if (!$nutritionToken) { /* ... ошибка нет токена ... */ break; }

                    try {
                        $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                        $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/saved-foods';
                        Log::info("NUTRITION DELETE (LIST): Запрос списка продуктов для удаления", ['url' => $serviceUrl, 'email' => $activeEmail]);
                        $response = $client->get($serviceUrl, [
                            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $nutritionToken]
                            // 'query' => ['per_page' => 20] // Можно запросить больше, если есть пагинация
                        ]);
                        $statusCode = $response->getStatusCode();
                        $responseBody = json_decode($response->getBody()->getContents(), true);

                        if ($statusCode === 200 && isset($responseBody['data'])) {
                            $products = $responseBody['data'];
                            if (empty($products)) {
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'У вас нет сохраненных продуктов для удаления.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                            } else {
                                $deleteListMsg = "Какой продукт удалить? (Введите номер или 'Назад')\n\n";
                                $productMap = []; // Для сохранения [номер => id_продукта]
                                $i = 1;
                                foreach ($products as $product) {
                                    $deleteListMsg .= sprintf("%d. %s (ID: %s)\n", $i, $product['food_name'] ?? 'Без имени', $product['id'] ?? 'N/A');
                                    if (isset($product['id'])) {
                                        $productMap[$i] = $product['id']; // Сохраняем ID продукта
                                    }
                                    $i++;
                                }
                                $this->userSelections[$chatId]['product_to_delete_map'] = $productMap; // Сохраняем карту для следующего шага
                                $this->userStates[$chatId] = States::AWAITING_PRODUCT_NUMBER_DELETE;
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => rtrim($deleteListMsg), 'reply_markup' => $this->keyboardService->makeBackOnly()]);
                            }
                        } else { /* ... обработка ошибки API ... */ }
                    } catch (\Throwable $e) { $this->handleGuzzleError($e, $chatId, "питания (список для удаления)"); }
                }
                break;
            case '📜 Сохранённые':
                if (in_array($currentState, [States::DEFAULT, States::BJU_MENU])) {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    if (!$activeEmail) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Активный аккаунт не определен.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                        break;
                    }

                    $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;
                    if (!$nutritionToken) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Токен для сервиса питания не найден. Попробуйте переключить/добавить аккаунт.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                        break;
                    }

                    try {
                        $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                        // Эндпоинт для получения списка продуктов
                        $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/saved-foods';

                        // Параметры пагинации (пока берем по умолчанию первую страницу)
                        $queryParams = [
                            // 'page' => 1, // Можно будет добавить позже для пагинации
                            // 'per_page' => 10,
                        ];

                        Log::info("NUTRITION GET SAVED FOODS: Requesting", ['url' => $serviceUrl, 'email' => $activeEmail, 'params' => $queryParams]);

                        $response = $client->get($serviceUrl, [
                            'headers' => [
                                'Accept' => 'application/json',
                                'Authorization' => 'Bearer ' . $nutritionToken
                            ],
                            'query' => $queryParams // Передаем параметры запроса
                        ]);

                        $statusCode = $response->getStatusCode();
                        $responseBody = json_decode($response->getBody()->getContents(), true);

                        Log::info("NUTRITION GET SAVED FOODS: Response", ['status' => $statusCode, 'body_preview' => substr(json_encode($responseBody), 0, 200)]);

                        if ($statusCode === 200 && isset($responseBody['data'])) {
                            $products = $responseBody['data'];
                            if (empty($products)) {
                                $this->telegram->sendMessage([
                                    'chat_id' => $chatId,
                                    'text' => 'У вас пока нет сохраненных продуктов для аккаунта ' . $activeEmail,
                                    'reply_markup' => $this->keyboardService->makeBjuMenu()
                                ]);
                            } else {
                                $productListMsg = "Ваши сохраненные продукты (аккаунт: {$activeEmail}):\n\n";
                                $i = 1;
                                foreach ($products as $product) {
                                    // Используем поля из новой документации API
                                    $productListMsg .= sprintf(
                                        "%d. %s (ID: %s)\n   Б: %s, Ж: %s, У: %s, К: %s / 100г\n",
                                        $i++,
                                        $product['food_name'] ?? 'Без имени',
                                        $product['id'] ?? 'N/A', // ID важен для удаления
                                        $product['proteins'] ?? '0', // API возвращает строки для БЖУ
                                        $product['fats'] ?? '0',
                                        $product['carbs'] ?? '0',
                                        $product['kcal'] ?? '0' // API возвращает kcal
                                    );
                                }
                                // Добавляем информацию о пагинации, если продуктов больше, чем на одной странице
                                if (isset($responseBody['meta']) && $responseBody['meta']['current_page'] < $responseBody['meta']['last_page']) {
                                    $productListMsg .= "\n...\nПоказаны продукты с первой страницы. Всего продуктов: " . $responseBody['meta']['total'];
                                }

                                $this->telegram->sendMessage([
                                    'chat_id' => $chatId,
                                    'text' => rtrim($productListMsg),
                                    'reply_markup' => $this->keyboardService->makeBjuMenu(),
                                ]);
                            }
                        } else {
                            $errorMessage = $this->extractErrorMessage($responseBody, 'питания (список продуктов)');
                            Log::warning("NUTRITION GET SAVED FOODS: Ошибка получения списка", ['status_code' => $statusCode, 'body' => $responseBody]);
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Не удалось загрузить список продуктов: {$errorMessage}",
                                'reply_markup' => $this->keyboardService->makeBjuMenu()
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->handleGuzzleError($e, $chatId, "питания (список продуктов)");
                    }
                }
                break;
            case '🔎 Поиск': // Это кнопка из МЕНЮ БЖУ ПРОДУКТОВ
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) {
                    // Ошибка: активный аккаунт не определен (это должно быть обработано ранее, но на всякий случай)
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Активный аккаунт не определен.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                    $this->userStates[$chatId] = States::BJU_MENU; // Возвращаем в меню БЖУ
                    break;
                }

                // Проверяем, что мы находимся в подходящем меню (например, в меню БЖУ)
                // Можно добавить States::DEFAULT, если поиск доступен и из главного меню
                if ($currentState === States::BJU_MENU || $currentState === States::DEFAULT) {
                    // Сразу переходим к запросу имени продукта для поиска через API
                    $this->userStates[$chatId] = States::AWAITING_PRODUCT_NAME_SEARCH;
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Введите название продукта для поиска в вашей базе на сервере (или "Назад"):',
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                } else {
                    // Если мы не в меню БЖУ, возможно, это другая кнопка "Поиск" или непредвиденное состояние
                    Log::warning("Кнопка '🔎 Поиск' (БЖУ) нажата в неожиданном состоянии: {$currentState} для chatId {$chatId}");
                    // Можно просто ничего не делать или вернуть в основное меню БЖУ
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Действие недоступно из текущего меню.',
                        'reply_markup' => $this->keyboardService->makeBjuMenu()
                    ]);
                    $this->userStates[$chatId] = States::BJU_MENU;
                }
                break;

            // --- Кнопка "Назад" (из ГЛАВНЫХ подменю) ---
            case '⬅️ Назад':
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

    private function getActiveAccountEmail(int $chatId): ?string
    {
        // Проверяем, есть ли вообще запись для chatId и установлен ли активный email
        if (isset($this->userData[$chatId]['active_account_email'])) {
            $activeEmail = $this->userData[$chatId]['active_account_email'];
            // Дополнительно проверяем, существует ли аккаунт с таким email
            if (isset($this->userData[$chatId]['accounts'][$activeEmail])) {
                return $activeEmail;
            } else {
                // Ситуация, когда active_account_email указывает на несуществующий аккаунт (ошибка данных)
                // Можно попытаться выбрать первый доступный аккаунт или вернуть null
                // Пока вернем null для простоты
                echo "Warning: Active account email '{$activeEmail}' not found in accounts for chat ID {$chatId}.\n";
                return null;
            }
        }
        return null; // Пользователь или активный аккаунт не найдены
    }

   
    private function getActiveAccountData(int $chatId): ?array
    {
        $activeEmail = $this->getActiveAccountEmail($chatId);
        if ($activeEmail) {
            // Мы уже проверили существование в getActiveAccountEmail
            return $this->userData[$chatId]['accounts'][$activeEmail];
        }
        return null;
    }
   
    private function handleNewAccountState(int $chatId, string $text, Message $message, int $currentState): void
    {
        // --- Шаг 1: Получение имени для нового аккаунта ---
        if ($currentState === States::AWAITING_NEW_ACCOUNT_NAME) {
            $trimmedName = trim($text);
            if (empty($trimmedName)) {
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Имя не может быть пустым. Введите имя для нового аккаунта:', 'reply_markup' => $this->keyboardService->makeBackOnly()]);
            } else {
                $this->userSelections[$chatId]['new_account_data'] = ['name' => $trimmedName];
                $this->userStates[$chatId] = States::AWAITING_NEW_ACCOUNT_EMAIL;
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Имя '{$trimmedName}' для нового аккаунта принято. Теперь введите Email:", 'reply_markup' => $this->keyboardService->makeBackOnly()]);
            }
            return;
        }

        // --- Шаг 2: Получение Email для нового аккаунта ---
        if ($currentState === States::AWAITING_NEW_ACCOUNT_EMAIL) {
            $email = trim($text);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректный формат email. Введите правильный email:', 'reply_markup' => $this->keyboardService->makeBackOnly()]);
            } elseif (isset($this->userData[$chatId]['accounts'][$email])) {
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Аккаунт с email '{$email}' уже существует у вас. Введите другой email:", 'reply_markup' => $this->keyboardService->makeBackOnly()]);
            } else {
                if (!isset($this->userSelections[$chatId]['new_account_data']['name'])) {
                    Log::error("NEW_ACCOUNT: Имя не найдено при вводе email для chatId {$chatId}");
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка. Пожалуйста, начните добавление аккаунта с ввода имени.', 'reply_markup' => $this->keyboardService->makeBackOnly()]);
                    $this->userStates[$chatId] = States::AWAITING_NEW_ACCOUNT_NAME;
                    unset($this->userSelections[$chatId]['new_account_data']);
                    return;
                }
                $this->userSelections[$chatId]['new_account_data']['email'] = $email;
                $this->userStates[$chatId] = States::AWAITING_NEW_ACCOUNT_PASSWORD;
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Email '{$email}' принят. Пароль (мин. 8 симв., заглавные/строчные буквы, цифры, спецсимволы):", 'reply_markup' => $this->keyboardService->makeBackOnly()]);
            }
            return;
        }

        // --- Шаг 3: Получение пароля и регистрация в сервисах ---
        if ($currentState === States::AWAITING_NEW_ACCOUNT_PASSWORD) {
            $plainPassword = $text;
            // Валидация пароля
            $passwordIsValid = true; $passwordErrors = [];
            // ... (полный код валидации пароля, как в handleRegistrationState) ...
            if (strlen($plainPassword) < 8) { $passwordIsValid = false; $passwordErrors[] = "минимум 8 символов"; }
            if (!preg_match('/[A-Z]/', $plainPassword)) { $passwordIsValid = false; $passwordErrors[] = "заглавная буква"; }
            if (!preg_match('/[a-z]/', $plainPassword)) { $passwordIsValid = false; $passwordErrors[] = "строчная буква"; }
            if (!preg_match('/[0-9]/', $plainPassword)) { $passwordIsValid = false; $passwordErrors[] = "цифра"; }
            if (!preg_match('/[\'^£$%&*()}{@#~?><>,|=_+¬-]/', $plainPassword)) { $passwordIsValid = false; $passwordErrors[] = "спецсимвол"; }


            if (!$passwordIsValid) {
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Пароль для нового аккаунта не соответствует требованиям: " . implode(', ', $passwordErrors) . ".\nВведите пароль еще раз:", 'reply_markup' => $this->keyboardService->makeBackOnly()]);
                return;
            }

            $newAccData = $this->userSelections[$chatId]['new_account_data'] ?? null;
            if (!$newAccData || !isset($newAccData['name']) || !isset($newAccData['email'])) {
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка добавления аккаунта: не найдены имя или email. Попробуйте заново из меню "Аккаунт".', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                $this->userStates[$chatId] = States::DEFAULT; unset($this->userSelections[$chatId]['new_account_data']);
                return;
            }

            $name = $newAccData['name'];
            $email = $newAccData['email'];

            // --- Регистрация и получение токена для Nutrition Service (реальный вызов) ---
            $nutritionApiToken = $this->registerAndLoginNutritionService($chatId, $name, $email, $plainPassword);
            if (!$nutritionApiToken) {
                $this->userStates[$chatId] = States::DEFAULT; // или States::ACCOUNT_MENU
                unset($this->userSelections[$chatId]['new_account_data']);
                // Сообщение об ошибке уже отправлено внутри registerAndLoginNutritionService
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Добавление нового аккаунта прервано из-за ошибки с сервисом питания.', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                return;
            }

            // --- Регистрация и получение токена для Workout Service (реальный вызов) ---
            $workoutApiToken = $this->registerWorkoutService($chatId, $name, $email, $plainPassword);
            if (!$workoutApiToken) {
                // TODO: Подумать об "откате" регистрации в nutrition-service.
                $this->userStates[$chatId] = States::DEFAULT; // или States::ACCOUNT_MENU
                unset($this->userSelections[$chatId]['new_account_data']);
                // Сообщение об ошибке уже отправлено внутри registerWorkoutService
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Добавление нового аккаунта прервано из-за ошибки с сервисом тренировок.', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                return;
            }

            // --- СОЗДАНИЕ ЛОКАЛЬНОГО АККАУНТА В БОТЕ (если оба токена получены) ---
            $hashedBotPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
            if ($hashedBotPassword === false) {
                Log::error("NEW_ACCOUNT: Ошибка хеширования пароля для бота (локально), chatId {$chatId}");
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Внутренняя ошибка при локальной обработке пароля. Добавление аккаунта отменено.', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                $this->userStates[$chatId] = States::DEFAULT; unset($this->userSelections[$chatId]['new_account_data']); return;
            }

            if (!isset($this->userData[$chatId]['accounts'])) {
                Log::warning("NEW_ACCOUNT: 'accounts' не существовал для chatId {$chatId}, инициализируем. Это неожиданно для добавления нового аккаунта.");
                $this->userData[$chatId]['accounts'] = [];
            }

            $this->userData[$chatId]['accounts'][$email] = [
                'name' => $name,
                'email' => $email,
                'password' => $hashedBotPassword,
                'nutrition_api_token' => $nutritionApiToken,
                'workout_api_token' => $workoutApiToken
            ];
            $this->userData[$chatId]['active_account_email'] = $email;

            $this->dataStorage->saveAllUserData($this->userData);
            unset($this->userSelections[$chatId]['new_account_data']);
            $this->userStates[$chatId] = States::DEFAULT;

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Новый аккаунт '{$name}' ({$email}) успешно добавлен, зарегистрирован в сервисах и сделан активным!",
                'reply_markup' => $this->keyboardService->makeMainMenu()
            ]);
        } // Конец if ($currentState === States::AWAITING_NEW_ACCOUNT_PASSWORD)
    }





    private function handleAccountSwitchState(int $chatId, string $text, Message $message, int $currentState): void
    {
        if ($currentState === States::AWAITING_ACCOUNT_SWITCH_SELECTION) {
            $accountMap = $this->userSelections[$chatId]['account_switch_map'] ?? null;

            if (!$accountMap) {
                Log::error("SWITCH_ACC: account_switch_map не найден для chatId {$chatId}");
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка при выборе аккаунта. Попробуйте снова.', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                $this->userStates[$chatId] = States::DEFAULT;
                return;
            }

            if (!ctype_digit($text) || !isset($accountMap[(int)$text])) {
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Неверный номер. Введите номер из списка:']);
                return; // Оставляем пользователя в состоянии выбора
            }

            $selectedNumber = (int)$text;
            $selectedEmail = $accountMap[$selectedNumber];

            if (!isset($this->userData[$chatId]['accounts'][$selectedEmail])) {
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Выбранный аккаунт не найден локально.', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                $this->userStates[$chatId] = States::DEFAULT;
                unset($this->userSelections[$chatId]['account_switch_map']);
                return;
            }

            $accountToSwitch = $this->userData[$chatId]['accounts'][$selectedEmail];
            $nutritionToken = $accountToSwitch['nutrition_api_token'] ?? null;
            $workoutToken = $accountToSwitch['workout_api_token'] ?? null;

            $client = new \GuzzleHttp\Client(['timeout' => 7, 'connect_timeout' => 4]); // Таймауты для проверки токена можно сделать короче

            $nutritionTokenValid = false;
            $workoutTokenValid = false;

            // 1. РЕАЛЬНАЯ Проверка токена для Nutrition Service
            if (!$nutritionToken) {
                Log::warning("SWITCH_ACC NUTRITION: Нет nutrition_api_token для {$selectedEmail} у chatId {$chatId}");
            } else {
                try {
                    $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/user';
                    Log::debug("SWITCH_ACC NUTRITION: Requesting user info", ['url' => $serviceUrl, 'email' => $selectedEmail]);
                    $response = $client->get($serviceUrl, [
                        'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $nutritionToken]
                    ]);
                    $statusCode = $response->getStatusCode();
                    $responseBody = json_decode($response->getBody()->getContents(), true);

                    if ($statusCode === 200 && isset($responseBody['email']) && $responseBody['email'] === $selectedEmail) {
                        $nutritionTokenValid = true;
                        Log::info("SWITCH_ACC NUTRITION: Токен для {$selectedEmail} валиден. Сервис вернул email: " . $responseBody['email']);
                    } else {
                        Log::warning("SWITCH_ACC NUTRITION: Токен для {$selectedEmail} вернул статус {$statusCode} или неверные данные.", ['response_body' => $responseBody]);
                    }
                } catch (\GuzzleHttp\Exception\ClientException $e) { // 4xx ошибки
                    Log::warning("SWITCH_ACC NUTRITION: Ошибка клиента (4xx) при проверке токена для {$selectedEmail} - Статус: " . $e->getResponse()->getStatusCode() . ", Сообщение: " . $e->getMessage());
                } catch (\Throwable $e) { // Все остальные ошибки (Connect, Server 5xx, etc.)
                    $this->handleGuzzleError($e, $chatId, "питания (проверка токена)"); // Используем общий обработчик
                }
            }

            // 2. РЕАЛЬНАЯ Проверка токена для Workout Service
            if (!$workoutToken) {
                Log::warning("SWITCH_ACC WORKOUT: Нет workout_api_token для {$selectedEmail} у chatId {$chatId}");
            } else {
                try {
                    // Убедись, что эндпоинт /api/v1/users или /api/v1/user для workout-service правильный
                    $serviceUrl = env('WORKOUT_SERVICE_BASE_URI', 'http://localhost:8001') . '/api/v1/users';
                    Log::debug("SWITCH_ACC WORKOUT: Requesting user info", ['url' => $serviceUrl, 'email' => $selectedEmail]);
                    $response = $client->get($serviceUrl, [
                        'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $workoutToken]
                    ]);
                    $statusCode = $response->getStatusCode();
                    $responseBody = json_decode($response->getBody()->getContents(), true);

                    if ($statusCode === 200 && isset($responseBody['email']) && $responseBody['email'] === $selectedEmail) {
                        $workoutTokenValid = true;
                        Log::info("SWITCH_ACC WORKOUT: Токен для {$selectedEmail} валиден. Сервис вернул email: " . $responseBody['email']);
                    } else {
                        Log::warning("SWITCH_ACC WORKOUT: Токен для {$selectedEmail} вернул статус {$statusCode} или неверные данные.", ['response_body' => $responseBody]);
                    }
                } catch (\GuzzleHttp\Exception\ClientException $e) { // 4xx ошибки
                    Log::warning("SWITCH_ACC WORKOUT: Ошибка клиента (4xx) при проверке токена для {$selectedEmail} - Статус: " . $e->getResponse()->getStatusCode() . ", Сообщение: " . $e->getMessage());
                } catch (\Throwable $e) { // Все остальные ошибки
                    $this->handleGuzzleError($e, $chatId, "тренировок (проверка токена)");
                }
            }

            // 3. Принятие решения о переключении
            if ($nutritionTokenValid && $workoutTokenValid) {
                $this->userData[$chatId]['active_account_email'] = $selectedEmail;
                $this->dataStorage->saveAllUserData($this->userData);
                $selectedName = $accountToSwitch['name'] ?? '???';
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Аккаунт '{$selectedName}' ({$selectedEmail}) успешно активирован.",
                    'reply_markup' => $this->keyboardService->makeMainMenu()
                ]);
                $this->userStates[$chatId] = States::DEFAULT;
            } else {
                $errorReport = [];
                if (!$nutritionToken) { $errorReport[] = "токен для сервиса питания отсутствует"; }
                elseif (!$nutritionTokenValid) { $errorReport[] = "сессия для сервиса питания недействительна/ошибка проверки"; }

                if (!$workoutToken) { $errorReport[] = "токен для сервиса тренировок отсутствует"; }
                elseif (!$workoutTokenValid) { $errorReport[] = "сессия для сервиса тренировок недействительна/ошибка проверки"; }

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Не удалось активировать аккаунт '{$accountToSwitch['name']}'.\nПричина: " . (!empty($errorReport) ? implode('; ', $errorReport) : "неизвестная ошибка проверки токенов") . ".\nПожалуйста, попробуйте добавить этот аккаунт заново или выберите другой.",
                    'reply_markup' => $this->keyboardService->makeAccountMenu()
                ]);
                $this->userStates[$chatId] = States::DEFAULT; // Возвращаем в главное меню, оттуда пользователь может пойти в меню аккаунта
            }
            unset($this->userSelections[$chatId]['account_switch_map']);
        } // Конец if ($currentState === States::AWAITING_ACCOUNT_SWITCH_SELECTION)
    }

    /**
 * Извлекает сообщение об ошибке из ответа API.
    */
    private function extractErrorMessage(array $responseBody, string $serviceNameForLog): string
    {
        $errorMessage = $responseBody['message'] ?? "Неизвестная ошибка от сервиса {$serviceNameForLog}.";
        if (isset($responseBody['errors'])) {
            $errorMessages = [];
            foreach ($responseBody['errors'] as $fieldErrors) {
                if (is_array($fieldErrors)) {
                    $errorMessages = array_merge($errorMessages, $fieldErrors);
                } else {
                    $errorMessages[] = (string) $fieldErrors;
                }
            }
            if (!empty($errorMessages)) {
                $errorMessage = implode('; ', $errorMessages);
            }
        }
        return $errorMessage;
    }

    /**
     * Обрабатывает ошибки Guzzle и другие Throwable при запросах к API.
     */
    private function handleGuzzleError(\Throwable $e, int $chatId, string $serviceNameForUser): void
    {
        $userMessage = "Произошла ошибка при обращении к сервису {$serviceNameForUser}. Попробуйте позже.";
        $logMessage = "Ошибка при запросе к сервису {$serviceNameForUser}: " . $e->getMessage();
        $logContext = ['exception' => $e];

        if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
            $userMessage = "Не удалось подключиться к сервису {$serviceNameForUser}. Проверьте доступность сервиса и попробуйте позже.";
            $logMessage = "Ошибка соединения с сервисом {$serviceNameForUser}: " . $e->getMessage();
        } elseif ($e instanceof \GuzzleHttp\Exception\RequestException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $errorBodyContent = $response->getBody()->getContents();
                $logContext['response_body_on_error'] = $errorBodyContent;
                $logContext['status_code'] = $statusCode;

                // Попытка извлечь сообщение из JSON ответа
                $decodedBody = json_decode($errorBodyContent, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $userMessage = $this->extractErrorMessage($decodedBody, $serviceNameForUser);
                    // Добавляем префикс, если это не общая ошибка соединения
                    if (strpos($userMessage, "Неизвестная ошибка") === false && strpos($userMessage, "The given data was invalid") === false) {
                        $userMessage = "Сервис {$serviceNameForUser} ответил: " . $userMessage;
                    } else if (strpos($userMessage, "The given data was invalid") !== false) {
                        $userMessage = "Данные для сервиса {$serviceNameForUser} неверны: " . $this->extractErrorMessage($decodedBody, $serviceNameForUser);
                    }
                } else {
                    $userMessage = "Сервис {$serviceNameForUser} вернул некорректный ответ.";
                }
            } else {
                $userMessage = "Нет ответа от сервиса {$serviceNameForUser}. Проверьте его доступность.";
            }
        }

        Log::error($logMessage, $logContext);
        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $userMessage, 'reply_markup' => $this->keyboardService->removeKeyboard()]);
        // Сбрасываем состояние, чтобы пользователь мог начать заново
        $this->userStates[$chatId] = States::DEFAULT;
        unset($this->userSelections[$chatId]['registration_data']);
    }


}