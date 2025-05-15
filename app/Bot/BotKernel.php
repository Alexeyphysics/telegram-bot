<?php

namespace Bot; 

use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;
use Bot\Constants\States;         
use Bot\Keyboard\KeyboardService; 
use Bot\Service\DataStorageService;
use Illuminate\Support\Facades\Log;


class BotKernel
{
    private Api $telegram;
    private int $updateId = 0;
    private KeyboardService $keyboardService;
    private DataStorageService $dataStorage;
    private array $userStates = [];
    private array $userData = [];
    private array $userProducts = [];
    private array $diaryData = [];
    private array $trainingLogData = []; 
    private array $userSelections = [];
    private array $currentTrainingLog = [];

    private array $exercises = [];


    public function __construct(
        Api $telegram,
        DataStorageService $dataStorage,
        KeyboardService $keyboardService) {
        $this->telegram = $telegram;
        $this->dataStorage = $dataStorage;
        $this->keyboardService = $keyboardService;
        $this->userData = $this->dataStorage->getAllUserData();
        $this->loadExercises();
        echo "BotKernel Initialized via Laravel Container.\n";
    }


    public function run(): void{
        Log::info("Запуск Telegram бота..."); 
        $offset = 0; 
        while (true) {
            try {
                $updates = $this->telegram->getUpdates(['offset' => $offset, 'timeout' => 30]);
            } catch (TelegramSDKException $e) {
                Log::error("Telegram SDK Error: " . $e->getMessage());
                sleep(5); 
                continue;
            } catch (\Throwable $e) {
                Log::error("General Error getting updates: " . $e->getMessage(), ['exception' => $e]);
                sleep(10); 
                continue;}
            foreach ($updates as $update) {
                $offset = $update->getUpdateId() + 1; 
                $chatId = null; 
                try {
                    if ($update->getCallbackQuery()) {
                        $callbackQuery = $update->getCallbackQuery();
                        $chatId = $callbackQuery->getMessage()->getChat()->getId(); 
                        $callbackData = $callbackQuery->getData();
                        $messageId = $callbackQuery->getMessage()->getMessageId();
                        $this->initializeUserDataForChat($chatId);
                        try {
                            $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
                        } catch (\Throwable $e) {
                            Log::warning("Не удалось ответить на CallbackQuery: " . $e->getMessage(), ['query_id' => $callbackQuery->getId()]);
                        }
                        echo "Получен CallbackQuery: {$callbackData} (Chat ID: {$chatId}), State: " . ($this->userStates[$chatId] ?? States::DEFAULT) . "\n";
                        $this->handleCallbackQuery($chatId, $callbackData, $messageId, $callbackQuery->getMessage());
                    } elseif ($update->getMessage()) {
                        $message = $update->getMessage();
                        if ($message->getChat() === null) { 
                            Log::warning("Сообщение без информации о чате получено.", ['update_id' => $update->getUpdateId()]);
                            continue;
                        }
                        $chatId = $message->getChat()->getId();
                        $text = $message->getText() ?? '';
                        $this->initializeUserDataForChat($chatId);
                        echo "Получено сообщение: \"{$text}\" (Chat ID: {$chatId}), State: " . ($this->userStates[$chatId] ?? States::DEFAULT) . "\n";
                        $this->handleMessage($chatId, $text, $message);
                    } else {
                    }
                } catch (\Throwable $e) {
                    Log::error("Error processing update for chat ID {$chatId}: " . $e->getMessage(), [
                        'exception' => $e,
                        'update_id' => $update->getUpdateId(),
                        'chat_id' => $chatId,
                    ]);
                    if ($chatId) {
                        try {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => 'Произошла внутренняя ошибка. Пожалуйста, попробуйте позже.',
                                'reply_markup' => $this->keyboardService->makeMainMenu() 
                            ]);
                            $this->userStates[$chatId] = States::DEFAULT;
                            unset($this->userSelections[$chatId]);
                        } catch (\Throwable $ex) {
                            Log::error("Could not send error message to user {$chatId}.", ['exception' => $ex]);
                        }
                    }
                }
            } 
            if (empty($updates)) {
                sleep(1);
            }
        } 
    }

    private function initializeUserDataForChat(int $chatId): void
    {
        if (!isset($this->userStates[$chatId])) {
            $this->userStates[$chatId] = States::DEFAULT;
        }
        if (!isset($this->userSelections[$chatId])) {
            $this->userSelections[$chatId] = [];
        }
    }
  

    private function handleMessage(int $chatId, string $text, Message $message): void
    {
        $currentState = $this->userStates[$chatId] ?? States::DEFAULT;
        if ($text === '⬅️ Назад' && $this->handleBackDuringInput($chatId, $message, $currentState)) {
            return;
        }
        if ($currentState === States::SHOWING_WELCOME_MESSAGE) {
            if ($text === '🚀 Начать!') {
                $this->userStates[$chatId] = States::AWAITING_NAME; 
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Отлично! Давайте зарегистрируем ваш первый аккаунт. Введите ваше имя:',
                    'reply_markup' => $this->keyboardService->removeKeyboard() 
                ]);
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Пожалуйста, нажмите кнопку "🚀 Начать!", чтобы продолжить.',
                    'reply_markup' => $this->keyboardService->makeSingleButtonMenu('🚀 Начать!')
                ]);
            }
            return;
        }
        if ($currentState >= States::AWAITING_NAME && $currentState <= States::AWAITING_PASSWORD) {
            $this->handleRegistrationState($chatId, $text, $message, $currentState);
            return; 
        }
        if ($currentState >= States::AWAITING_NEW_ACCOUNT_NAME && $currentState <= States::AWAITING_NEW_ACCOUNT_PASSWORD) {
            $this->handleNewAccountState($chatId, $text, $message, $currentState);
            return; 
        }
        if ($currentState === States::AWAITING_ACCOUNT_SWITCH_SELECTION) {
            $this->handleAccountSwitchState($chatId, $text, $message, $currentState);
            return; 
        }
        if (($currentState >= States::AWAITING_PRODUCT_NAME_SAVE && $currentState <= States::AWAITING_SAVE_CONFIRMATION) || 
            $currentState === States::AWAITING_PRODUCT_NUMBER_DELETE || 
            $currentState === States::AWAITING_DELETE_CONFIRMATION ||   
            $currentState === States::AWAITING_PRODUCT_NAME_SEARCH)      
        {
            $this->handleBjuStates($chatId, $text, $message, $currentState);
            return; 
        }
        if (($currentState === States::AWAITING_DATE_MANUAL_ADD || $currentState >= States::AWAITING_ADD_MEAL_OPTION && $currentState <= States::AWAITING_ADD_MEAL_CONFIRM_MANUAL && $currentState != States::DIARY_MENU) || 
            $currentState === States::AWAITING_DATE_DELETE_MEAL ||
            $currentState === States::AWAITING_DATE_SEARCH_ADD ||      
            $currentState === States::AWAITING_MEAL_NUMBER_DELETE ||   
            $currentState === States::AWAITING_DELETE_MEAL_CONFIRM ||   
            $currentState === States::AWAITING_DATE_VIEW_MEAL)           
        {
            $this->handleDiaryStates($chatId, $text, $message, $currentState);
            return; 
        }
        if ($currentState >= States::SELECTING_MUSCLE_GROUP && $currentState <= States::SELECTING_EXERCISE) {
            $this->handleExerciseSelectionState($chatId, $text, $message, $currentState);
            return; 
        }
        if ($currentState === States::AWAITING_REPS || $currentState === States::AWAITING_WEIGHT) {
            $this->handleTrainingLogInputState($chatId, $text, $message, $currentState);
            return; 
        }
        $this->handleMenuCommands($chatId, $text, $message, $currentState);
    }
    private function loadExercises(): void
    {
        $basePath = dirname(__DIR__, 2); 
        $exercisesPath = $basePath . '/config/exercises.php';
        if (file_exists($exercisesPath)) {
            $loadedExercises = require $exercisesPath;
            if (is_array($loadedExercises)) {
                $this->exercises = $loadedExercises; 
                echo "Exercises structure loaded from file: {$exercisesPath}\n";
            } else {
                echo "Warning: File {$exercisesPath} did not return an array.\n";
                $this->exercises = []; 
            }
        } else {
            echo "Warning: Exercises file not found at {$exercisesPath}\n";
            $this->exercises = []; 
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
        $currentTrainingMode = $this->userSelections[$chatId]['training_mode'] ?? 'log'; 


        if ($currentState === States::SELECTING_EXERCISE_TYPE) { 
            $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP; 
            unset($this->userSelections[$chatId]['group']);

            $groupKeys = array_keys($this->exercises);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Вы вернулись назад. Выберите группу мышц:',
                'reply_markup' => $this->keyboardService->makeOptionsMenu($groupKeys, true, 2) 
            ]);
            return true;
        } elseif ($currentState === States::SELECTING_EXERCISE) { 
            $this->userStates[$chatId] = States::SELECTING_EXERCISE_TYPE; 
            $group = $this->userSelections[$chatId]['group'] ?? null;
            unset($this->userSelections[$chatId]['type']); 

            if ($group && isset($this->exercises[$group])) {
                $typeKeys = array_keys($this->exercises[$group]);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Вы вернулись назад. Группа: {$group}\nВыберите тип:",
                    'reply_markup' => $this->keyboardService->makeOptionsMenu($typeKeys, true, 2) 
                ]);
            } else { 
                $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP;
                unset($this->userSelections[$chatId]['group']);
                $groupKeys = array_keys($this->exercises);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Произошла ошибка. Выберите группу мышц:',
                    'reply_markup' => $this->keyboardService->makeOptionsMenu($groupKeys, true, 2)
                ]);
            }
            return true;
        } elseif ($currentState === States::AWAITING_REPS) { 
            $this->userStates[$chatId] = States::SELECTING_EXERCISE; 
            $group = $this->userSelections[$chatId]['group'] ?? null;
            $type = $this->userSelections[$chatId]['type'] ?? null;
            unset($this->userSelections[$chatId]['exercise']); 

            if ($group && $type && isset($this->exercises[$group][$type])) {
                $exerciseListObjects = $this->exercises[$group][$type];
                $exerciseNames = [];
                foreach ($exerciseListObjects as $ex) { $exerciseNames[] = is_array($ex) && isset($ex['name']) ? $ex['name'] : (is_string($ex) ? $ex : 'Неизв. упр.'); }
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Вы вернулись назад. Тип: {$type}\nВыберите упражнение:",
                    'reply_markup' => $this->keyboardService->makeOptionsMenu($exerciseNames, true, 1) 
                ]);
            } else { 
                $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP;
                unset($this->userSelections[$chatId]['group'], $this->userSelections[$chatId]['type']);
                $groupKeys = array_keys($this->exercises);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Произошла ошибка. Выберите группу мышц:',
                    'reply_markup' => $this->keyboardService->makeOptionsMenu($groupKeys, true, 2)
                ]);
            }
            return true;
            } elseif ($currentState === States::AWAITING_WEIGHT) { 
                $this->userStates[$chatId] = States::AWAITING_REPS; 
                $exercise = $this->userSelections[$chatId]['exercise'] ?? 'Упражнение';
                unset($this->userSelections[$chatId]['reps']); 
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Вы вернулись назад. Упражнение: {$exercise}\nВведите количество повторений:",
                    'reply_markup' => $this->keyboardService->makeBackOnly() 
                ]);
                return true;
            } elseif ($currentState === States::SELECTING_MUSCLE_GROUP) { 
                $returnState = match ($currentTrainingMode) {
                    'log' => States::LOGGING_TRAINING_MENU,
                    'technique' => States::LOGGING_TRAINING_MENU, 
                    'view_progress' => States::LOGGING_TRAINING_MENU, 
                    default => States::DEFAULT,
                };
                $returnKeyboard = match ($currentTrainingMode) {
                    'log', 'technique', 'view_progress' => $this->keyboardService->makeTrainingMenu(), 
                    default => $this->keyboardService->makeMainMenu(),
                };
                $cancelMessage = match ($currentTrainingMode) {
                    'log' => 'Добавление упражнения отменено. Возврат в меню записи.',
                    'technique' => 'Просмотр техники отменен. Возврат в меню записи.',
                    'view_progress' => 'Просмотр прогресса отменен. Возврат в меню записи.',
                    default => 'Действие отменено.'
                };
                $this->userStates[$chatId] = $returnState;
                unset(
                    $this->userSelections[$chatId]['group'],
                    $this->userSelections[$chatId]['type'],
                    $this->userSelections[$chatId]['exercise'],
                    $this->userSelections[$chatId]['training_mode']
                );
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $cancelMessage, 'reply_markup' => $returnKeyboard]);
                return true;
            }

        
        if (($currentState >= States::AWAITING_PRODUCT_NAME_SAVE && $currentState <= States::AWAITING_SAVE_CONFIRMATION) ||
            $currentState === States::AWAITING_PRODUCT_NUMBER_DELETE || 
            $currentState === States::AWAITING_DELETE_CONFIRMATION ||
            $currentState === States::AWAITING_PRODUCT_NAME_SEARCH){
            $this->userStates[$chatId] = States::BJU_MENU;
            unset($this->userSelections[$chatId]['bju_product']);
            unset($this->userSelections[$chatId]['bju_product_to_delete']);
            unset($this->userSelections[$chatId]['products_for_delete']); 
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Действие отменено. Меню БЖУ:', 'reply_markup' => $this->keyboardService->makeBjuMenu() ]);
            return true;
        }
        if (
            ($currentState >= States::AWAITING_ADD_MEAL_OPTION && $currentState <= States::AWAITING_ADD_MEAL_CONFIRM_MANUAL && $currentState != States::DIARY_MENU) ||
            $currentState === States::AWAITING_DATE_MANUAL_ADD ||
            $currentState === States::AWAITING_DATE_DELETE_MEAL ||
            $currentState === States::AWAITING_DATE_SEARCH_ADD ||
            $currentState === States::AWAITING_MEAL_NUMBER_DELETE ||
            $currentState === States::AWAITING_DELETE_MEAL_CONFIRM ||
            $currentState === States::AWAITING_DATE_VIEW_MEAL) {
            $previousState = States::DEFAULT; 
            $previousKeyboard = $this->keyboardService->makeMainMenu(); 
            $messageText = 'Действие отменено.'; 
                if ($currentState === States::AWAITING_ADD_MEAL_OPTION) {
                $previousState = States::DIARY_MENU;
                $previousKeyboard = $this->keyboardService->makeDiaryMenu();
                $messageText = 'Возврат в меню Дневника.';
            }elseif ($currentState === States::AWAITING_DATE_SEARCH_ADD) {
                $previousState = States::AWAITING_ADD_MEAL_OPTION;
                $previousKeyboard = $this->keyboardService->makeAddMealOptionsMenu();
                $messageText = 'Запись отменена. Выберите способ добавления.';
                unset($this->userSelections[$chatId]['diary_entry']);
            } elseif ($currentState === States::AWAITING_DATE_MANUAL_ADD) { 
                $previousState = States::AWAITING_ADD_MEAL_OPTION;
                $previousKeyboard = $this->keyboardService->makeAddMealOptionsMenu();
                $messageText = 'Запись отменена. Выберите способ добавления.';
                unset($this->userSelections[$chatId]['diary_entry']); 
            } elseif ($currentState === States::AWAITING_SEARCH_PRODUCT_NAME_ADD) {
                $previousState = States::AWAITING_DATE_SEARCH_ADD; 
                $previousKeyboard = $this->keyboardService->makeDateSelectionInline();
                $messageText = 'На какую дату записать прием пищи? (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":';
                unset($this->userSelections[$chatId]['diary_entry']['date']); 
            } elseif ($currentState === States::AWAITING_GRAMS_MANUAL_ADD) { 
                $previousState = States::AWAITING_DATE_MANUAL_ADD;
                $previousKeyboard = $this->keyboardService->makeDateSelectionInline();
                $messageText = 'На какую дату записать прием пищи? (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":';
                unset($this->userSelections[$chatId]['diary_entry']['date']); 
            } elseif ($currentState === States::AWAITING_GRAMS_SEARCH_ADD) {
                $previousState = States::AWAITING_SEARCH_PRODUCT_NAME_ADD;
                $previousKeyboard = $this->keyboardService->makeBackOnly();
                $messageText = 'Название продукта из сохраненных:';
                unset($this->userSelections[$chatId]['diary_entry']['search_name_lower'], $this->userSelections[$chatId]['diary_entry']['search_name_original']);
            } elseif ($currentState === States::AWAITING_PRODUCT_NAME_MANUAL_ADD) { 
                $previousState = States::AWAITING_GRAMS_MANUAL_ADD;
                $previousKeyboard = $this->keyboardService->makeBackOnly();
                $selectedDate = $this->userSelections[$chatId]['diary_entry']['date'] ?? date('Y-m-d'); 
                $messageText = 'Дата: ' . date('d.m.Y', strtotime($selectedDate)) . "\nМасса съеденного (г) (или \"Назад\"):";
                unset($this->userSelections[$chatId]['diary_entry']['grams']);
            } elseif ($currentState >= States::AWAITING_PROTEIN_MANUAL_ADD && $currentState <= States::AWAITING_CARBS_MANUAL_ADD) {
                $previousState = $currentState - 1; 
                $promptKey = match ($previousState) {
                    States::AWAITING_PRODUCT_NAME_MANUAL_ADD => 'grams',
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
                $keyToRemove = match ($currentState) { 
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
                $previousState = States::AWAITING_ADD_MEAL_OPTION;
                $previousKeyboard = $this->keyboardService->makeAddMealOptionsMenu();
                $messageText = 'Запись отменена. Выберите способ добавления.';
                unset($this->userSelections[$chatId]['diary_entry']); 
            } elseif ($currentState === States::AWAITING_DATE_DELETE_MEAL || $currentState === States::AWAITING_DATE_VIEW_MEAL) {
                $previousState = States::DIARY_MENU;
                $previousKeyboard = $this->keyboardService->makeDiaryMenu();
                $messageText = 'Возврат в меню Дневника.';
                unset($this->userSelections[$chatId]['diary_delete']); 
            } elseif ($currentState === States::AWAITING_MEAL_NUMBER_DELETE) {
                $previousState = States::AWAITING_DATE_DELETE_MEAL;
                $previousKeyboard = $this->keyboardService->makeBackOnly();
                $messageText = 'Введите дату приема пищи для удаления (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":';
                unset($this->userSelections[$chatId]['diary_delete']); 
            } elseif ($currentState === States::AWAITING_DELETE_MEAL_CONFIRM) {
                $previousState = States::DIARY_MENU;
                $previousKeyboard = $this->keyboardService->makeDiaryMenu();
                $messageText = 'Удаление отменено.';
                unset($this->userSelections[$chatId]['diary_delete']); 
            }
            $this->userStates[$chatId] = $previousState;
            if (in_array($currentState, [
                States::AWAITING_ADD_MEAL_CONFIRM_MANUAL,
                States::AWAITING_ADD_MEAL_CONFIRM_SEARCH,
                States::AWAITING_DELETE_MEAL_CONFIRM
            ])) {
                unset($this->userSelections[$chatId]['diary_entry']);
                unset($this->userSelections[$chatId]['diary_delete']);
            }
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $messageText, 'reply_markup' => $previousKeyboard]);
            return true; 
        }
        if ($currentState >= States::AWAITING_NEW_ACCOUNT_NAME && $currentState <= States::AWAITING_NEW_ACCOUNT_PASSWORD) {
            $this->userStates[$chatId] = States::DEFAULT; 
            unset($this->userSelections[$chatId]['new_account_data']); 
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Добавление нового аккаунта отменено.',
                'reply_markup' => $this->keyboardService->makeAccountMenu() 
            ]);
            return true; 
        }
        if ($currentState === States::AWAITING_ACCOUNT_SWITCH_SELECTION) {
            $this->userStates[$chatId] = States::DEFAULT;
            unset($this->userSelections[$chatId]['account_switch_map']);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Выбор аккаунта для переключения отменен.',
                'reply_markup' => $this->keyboardService->makeAccountMenu() 
            ]);
            return true; 
        }
        return false;
    }
    private function handleRegistrationState(int $chatId, string $text, Message $message, int $currentState): void
    {
        if ($currentState === States::AWAITING_NAME) {
        if ($text === '⬅️ Назад') { 
            $this->userStates[$chatId] = States::DEFAULT;
            unset($this->userSelections[$chatId]['registration_data']);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Регистрация отменена.',
                'reply_markup' => $this->keyboardService->makeMainMenu() 
            ]);
            return;
        }
        $trimmedName = trim($text);
        if (empty($trimmedName)) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Имя не может быть пустым. Пожалуйста, введите ваше имя:',
                'reply_markup' => $this->keyboardService->removeKeyboard()
            ]);
            return;
        }
        $this->userSelections[$chatId]['registration_data'] = ['name' => $trimmedName];
        $this->userStates[$chatId] = States::AWAITING_EMAIL;
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Отлично, ' . $trimmedName . '! Теперь введите ваш Email адрес:',
            'reply_markup' => $this->keyboardService->removeKeyboard() 
        ]);
        return;
        }
        if ($currentState === States::AWAITING_EMAIL) {
            if ($text === '⬅️ Назад') { 
                $this->userStates[$chatId] = States::AWAITING_NAME; 
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
                return;
            }
            if (!isset($this->userSelections[$chatId]['registration_data']['name'])) {
                Log::error("REGISTRATION: registration_data или имя не найдены при вводе email для chatId {$chatId}");
                $this->userStates[$chatId] = States::AWAITING_NAME; 
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
            return; 
        }
        if ($currentState === States::AWAITING_PASSWORD) {
            $plainPassword = $text; 
        $passwordIsValid = true; $passwordErrors = [];
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
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Пароль принят. Пожалуйста, подождите, идет регистрация вашего аккаунта в системе... Это может занять несколько секунд.',
            'reply_markup' => $this->keyboardService->removeKeyboard() 
        ]);
        $nutritionApiToken = $this->registerAndLoginNutritionService($chatId, $name, $email, $plainPassword, false); 
        if (!$nutritionApiToken) {
            $this->userStates[$chatId] = States::DEFAULT; unset($this->userSelections[$chatId]['registration_data']);
            return; 
        }
        $workoutApiToken = $this->registerWorkoutService($chatId, $name, $email, $plainPassword, false); 
        if (!$workoutApiToken) {
            $this->userStates[$chatId] = States::DEFAULT; unset($this->userSelections[$chatId]['registration_data']);
            return; 
        }
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
        } 
    }
    private function registerAndLoginNutritionService(int $chatId, string $name, string $email, string $plainPassword): ?string
    {
        $client = new \GuzzleHttp\Client(['timeout' => 20, 'connect_timeout' => 5]);
        $nutritionUserRegistered = false;
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
        return null;
    }
    private function registerWorkoutService(int $chatId, string $name, string $email, string $plainPassword): ?string
    {
        $client = new \GuzzleHttp\Client(['timeout' => 20, 'connect_timeout' => 5]);

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
    private function handleBjuStates(int $chatId, string $text, Message $message, int $currentState): void
    {
        if (($currentState >= States::AWAITING_PRODUCT_NAME_SAVE && $currentState <= States::AWAITING_DELETE_CONFIRMATION) ||
            $currentState === States::AWAITING_PRODUCT_NAME_SEARCH) {
            switch ($currentState) {
                case States::AWAITING_PRODUCT_NAME_SAVE:
                    $productName = trim($text);
                    if (empty($productName)) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Название продукта не может быть пустым. Введите снова или "Назад".',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    } else {
                        $this->userSelections[$chatId]['bju_product'] = ['name' => $productName];
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
            
                            $p = $this->userSelections[$chatId]['bju_product']['protein'] ?? 0;
                            $f = $this->userSelections[$chatId]['bju_product']['fat'] ?? 0;
                            $c = (float)$text;
                            $kcal = round($p * 4 + $f * 9 + $c * 4);
                            $this->userSelections[$chatId]['bju_product']['kcal'] = $kcal;
                            $this->userStates[$chatId] = States::AWAITING_SAVE_CONFIRMATION;
                            $pData = $this->userSelections[$chatId]['bju_product']; 
                            $confirmMsg = "Сохранить продукт?\nНазвание: {$pData['name']}\nНа 100г:\nБ:{$pData['protein']} Ж:{$pData['fat']} У:{$pData['carbs']} К:{$pData['kcal']} (расчет.)"; // Добавил (расчет.)
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => $confirmMsg,
                                'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                            ]);
                        }
                    break; 
                case States::AWAITING_SAVE_CONFIRMATION:
                        if ($text === '✅ Да') {
                            $activeEmail = $this->getActiveAccountEmail($chatId); 
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
                                $payload = [
                                    'food_name' => $productDataFromSelection['name'],
                                    'proteins' => (float) $productDataFromSelection['protein'],
                                    'fats' => (float) $productDataFromSelection['fat'],
                                    'carbs' => (float) $productDataFromSelection['carbs']
                                ];
                                try {
                                    $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
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
                                    if ($statusCode === 201 && isset($responseBody['message']) && $responseBody['message'] === "Food saved successfully" && isset($responseBody['data']['food_name'])) {
                                        $this->telegram->sendMessage([
                                            'chat_id' => $chatId,
                                            'text' => "Продукт '{$responseBody['data']['food_name']}' успешно сохранен на сервере!",
                                            'reply_markup' => $this->keyboardService->makeBjuMenu()
                                        ]);
                                    } else {
                                        $errorMessage = $responseBody['message'] ?? ($responseBody['error'] ?? 'Неизвестная ошибка от сервера.'); 
                                        if (isset($responseBody['errors'])) { 
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
                                    break;
                                }
                            } else {
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Не удалось получить данные продукта для сохранения.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                            }
                            $this->userStates[$chatId] = States::BJU_MENU;
                            unset($this->userSelections[$chatId]['bju_product']);
                        } elseif ($text === '❌ Нет') {
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'отменено.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                        } else {
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, нажмите "✅ Да" или "❌ Нет".', 'reply_markup' => $this->keyboardService->makeConfirmYesNo()]);
                            break;
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
                        break; 
                    }
                    $selectedNumber = (int)$text;
                    $productIdToDelete = $productMap[$selectedNumber];
                    $productNameToConfirm = "Продукт с ID: {$productIdToDelete}";
                    $this->userSelections[$chatId]['product_id_to_delete'] = $productIdToDelete;
                    $this->userStates[$chatId] = States::AWAITING_DELETE_CONFIRMATION;
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Вы уверены, что хотите удалить {$productNameToConfirm}?",
                        'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                    ]);
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
                        if (!$activeEmail || !$nutritionToken) { $this->userStates[$chatId] = States::BJU_MENU; unset($this->userSelections[$chatId]['product_id_to_delete']); break; }

                        try {
                            $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                            $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . "/api/v1/saved-foods/" . $productIdToDelete;

                            Log::info("NUTRITION DELETE PRODUCT: Requesting", ['url' => $serviceUrl, 'id' => $productIdToDelete]);

                            $response = $client->delete($serviceUrl, [ 
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
                        } catch (\Throwable $e) { $this->handleGuzzleError($e, $chatId, "питания (удаление продукта)");break; }

                    } elseif ($text === '❌ Нет') {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Удаление отменено.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                    } else {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, нажмите "✅ Да" или "❌ Нет".', 'reply_markup' => $this->keyboardService->makeConfirmYesNo()]);
                        break; 
                    }
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
                    break;
                }
                try {
                    $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                    $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/saved-foods';
                    Log::info("NUTRITION PRODUCT SEARCH (FETCH ALL): Запрос всех продуктов для поиска", ['url' => $serviceUrl, 'email' => $activeEmail, 'searchTerm' => $searchTermLower]);
                    $response = $client->get($serviceUrl, [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $nutritionToken
                        ]
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
                    $this->handleGuzzleError($e, $chatId, "питания (поиск продукта)");break;
                }
                $this->userStates[$chatId] = States::BJU_MENU;
                break;
                }
            return;
        }
    }
    private function handleDiaryStates(int $chatId, string $text, Message $message, int $currentState): void
    {
        switch ($currentState) {
            case States::AWAITING_ADD_MEAL_OPTION:
                if ($text === '🔍 Поиск в базе') {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    if (!$activeEmail) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Активный аккаунт не определен.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                        $this->userStates[$chatId] = States::DIARY_MENU; 
                        break;
                    }
                    $this->userStates[$chatId] = States::AWAITING_DATE_SEARCH_ADD;
                    unset($this->userSelections[$chatId]['diary_entry']);
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'На какую дату записать прием пищи? (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":',
                        'reply_markup' => $this->keyboardService->makeDateSelectionInline()
                    ]);
                } elseif ($text === '✍️ Записать БЖУ вручную') {
                    $this->userStates[$chatId] = States::AWAITING_DATE_MANUAL_ADD;
                    unset($this->userSelections[$chatId]['diary_entry']);
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'На какую дату записать прием пищи? (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":',
                        'reply_markup' => $this->keyboardService->makeDateSelectionInline()
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
                $normalizedText = strtolower(trim($text));
                if ($normalizedText === 'вчера') { $dateToLog = date('Y-m-d', strtotime('-1 day')); }
                elseif ($normalizedText === 'сегодня') { $dateToLog = date('Y-m-d'); }
                elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $text, $matches)) {
                    if (checkdate($matches[2], $matches[1], $matches[3])) { $dateToLog = "{$matches[3]}-{$matches[2]}-{$matches[1]}"; }
                       
                }elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $matches)) { 
                if (checkdate($matches[2], $matches[3], $matches[1])) { $dateToLog = $text; }
                }
                if (!$dateToLog) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Некорректный формат даты. Введите ДД.ММ.ГГГГ, "сегодня" или "вчера", или "Назад".',
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                } else {
                    $this->userSelections[$chatId]['diary_entry'] = ['date' => $dateToLog];
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
                }elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $matches)) {
                if (checkdate($matches[2], $matches[3], $matches[1])) { $dateToLog = $text; }
                }
                if (!$dateToLog) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Некорректный формат даты. Введите ДД.ММ.ГГГГ, "сегодня" или "вчера", или "Назад".',
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                } else {
                    $this->userSelections[$chatId]['diary_entry'] = ['date' => $dateToLog];
                    $this->userStates[$chatId] = States::AWAITING_GRAMS_MANUAL_ADD;
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Дата: ' . date('d.m.Y', strtotime($dateToLog)) . "\nМасса съеденного (г) (или \"Назад\"):",
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                }
                break;

            case States::AWAITING_SEARCH_PRODUCT_NAME_ADD:
                $searchTermLower = trim(mb_strtolower($text));
                if (empty($searchTermLower)) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, введите название продукта для поиска или "Назад".', 'reply_markup' => $this->keyboardService->makeBackOnly()]);
                    break;
                }

                $activeEmail = $this->getActiveAccountEmail($chatId);
                $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;

                if (!$activeEmail || !$nutritionToken) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Аккаунт или токен для сервиса питания не определен.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                    $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']);
                    break;
                }

                $eatenDate = $this->userSelections[$chatId]['diary_entry']['date'] ?? null;
                if (!$eatenDate) {
                    Log::error("DIARY SEARCH ADD: Дата (eaten_at) не найдена в userSelections для chatId {$chatId}");
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка: дата приема пищи не была установлена. Пожалуйста, начните добавление заново.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                    $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']);
                    break;
                }

                try {
                    $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                    $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/saved-foods';

                    Log::info("DIARY SEARCH ADD (FETCH ALL): Запрос всех сохраненных продуктов для поиска", ['url' => $serviceUrl, 'email' => $activeEmail, 'searchTerm' => $searchTermLower]);

                    $response = $client->get($serviceUrl, [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $nutritionToken
                        ]
                    ]);

                    $statusCode = $response->getStatusCode();
                    $responseBody = json_decode($response->getBody()->getContents(), true);
                    Log::info("DIARY SEARCH ADD (FETCH ALL): Ответ от сервиса", ['status' => $statusCode, 'searchTerm' => $searchTermLower]);

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

                        if ($foundProduct && isset($foundProduct['id']) && isset($foundProduct['food_name'])) {
                            $this->userSelections[$chatId]['diary_entry']['found_product_id'] = $foundProduct['id'];
                            $this->userSelections[$chatId]['diary_entry']['found_product_name'] = $foundProduct['food_name'];
                            $this->userSelections[$chatId]['diary_entry']['found_product_p100'] = (float)($foundProduct['proteins'] ?? 0);
                            $this->userSelections[$chatId]['diary_entry']['found_product_f100'] = (float)($foundProduct['fats'] ?? 0);
                            $this->userSelections[$chatId]['diary_entry']['found_product_c100'] = (float)($foundProduct['carbs'] ?? 0);
                            $this->userSelections[$chatId]['diary_entry']['found_product_kcal100'] = (float)($foundProduct['kcal'] ?? 0);
                            $this->userStates[$chatId] = States::AWAITING_GRAMS_SEARCH_ADD;
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Найден продукт: '{$foundProduct['food_name']}'.\nВведите массу съеденного (г) или 'Назад':",
                                'reply_markup' => $this->keyboardService->makeBackOnly()
                            ]);
                        } else {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Продукт '{$text}' не найден в вашей базе сохраненных продуктов. Попробуйте другое название или добавьте его сначала в 'БЖУ продуктов'.",
                                'reply_markup' => $this->keyboardService->makeDiaryMenu() 
                            ]);
                            $this->userStates[$chatId] = States::DIARY_MENU; 
                            unset($this->userSelections[$chatId]['diary_entry']);
                        }
                    } else {
                        $errorMessage = $this->extractErrorMessage($responseBody, 'питания (поиск для дневника)');
                        Log::warning("DIARY SEARCH ADD (FETCH ALL): Ошибка получения списка продуктов", ['status_code' => $statusCode, 'body' => $responseBody]);
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Не удалось выполнить поиск продуктов: {$errorMessage}",
                            'reply_markup' => $this->keyboardService->makeDiaryMenu()
                        ]);
                        $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']);
                    }
                } catch (\Throwable $e) {
                    $this->handleGuzzleError($e, $chatId, "питания (поиск для дневника)");
                    $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']);
                }
                break;

            case States::AWAITING_GRAMS_SEARCH_ADD:
                $diaryEntryData = $this->userSelections[$chatId]['diary_entry'] ?? null;
                if (!$diaryEntryData ||
                    !isset($diaryEntryData['date']) ||
                    !isset($diaryEntryData['found_product_id']) ||
                    !isset($diaryEntryData['found_product_name']) ||
                    !isset($diaryEntryData['found_product_p100']) ||
                    !isset($diaryEntryData['found_product_f100']) ||
                    !isset($diaryEntryData['found_product_c100']) ||
                    !isset($diaryEntryData['found_product_kcal100'])
                ) {
                    Log::error("DIARY SEARCH GRAMS: Неполные данные из шага поиска для chatId {$chatId}", ['selection' => $diaryEntryData]);
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка, данные о найденном продукте утеряны. Пожалуйста, начните добавление заново.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                    $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']);
                    break;
                }
                if (!is_numeric($text) || $text <= 0 || $text > 5000) { 
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Некорректный вес. Введите число больше 0 и не более 5000 (г) или 'Назад'.",
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                    break; 
                }
                $grams = (float)$text;
                $p_port = round(($diaryEntryData['found_product_p100'] / 100) * $grams, 2);
                $f_port = round(($diaryEntryData['found_product_f100'] / 100) * $grams, 2);
                $c_port = round(($diaryEntryData['found_product_c100'] / 100) * $grams, 2);
                $kcal_port = round(($diaryEntryData['found_product_kcal100'] / 100) * $grams, 2);
                $this->userSelections[$chatId]['diary_entry']['grams'] = $grams;
                $this->userSelections[$chatId]['diary_entry']['p_port'] = $p_port;
                $this->userSelections[$chatId]['diary_entry']['f_port'] = $f_port;
                $this->userSelections[$chatId]['diary_entry']['c_port'] = $c_port;
                $this->userSelections[$chatId]['diary_entry']['kcal_port'] = $kcal_port; 
                $productName = $diaryEntryData['found_product_name'];
                $eatenDateFormatted = date('d.m.Y', strtotime($diaryEntryData['date']));
                $confirmMsg = "Добавить в дневник на {$eatenDateFormatted}?\n";
                $confirmMsg .= "{$productName} - {$grams} г\n";
                $confirmMsg .= "Б: {$p_port}, Ж: {$f_port}, У: {$c_port}, К: {$kcal_port} (расчет.)";
                $this->userStates[$chatId] = States::AWAITING_ADD_MEAL_CONFIRM_SEARCH;
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $confirmMsg,
                    'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                ]);
                break;
            case States::AWAITING_ADD_MEAL_CONFIRM_SEARCH:
                $diaryEntryData = $this->userSelections[$chatId]['diary_entry'] ?? null;
                if (!$diaryEntryData ||
                    !isset($diaryEntryData['date']) ||        
                    !isset($diaryEntryData['found_product_id']) || 
                    !isset($diaryEntryData['grams'])            
                ) {
                    Log::error("DIARY SEARCH CONFIRM: Неполные данные для добавления в дневник (поиск) для chatId {$chatId}", ['selection' => $diaryEntryData]);
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка, данные для записи утеряны. Пожалуйста, начните добавление заново.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                    $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']);
                    break;
                }
                if ($text === '✅ Да') {
                    $activeEmail = $this->getActiveAccountEmail($chatId); 
                    $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;
                    if (!$activeEmail || !$nutritionToken) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Аккаунт или токен для сервиса питания не определен.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                        $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']);
                        break;
                    }
                    $payload = [
                        'food_id' => (int) $diaryEntryData['found_product_id'],
                        'weight' => (float) $diaryEntryData['grams'],
                        'eaten_at' => $diaryEntryData['date']
                    ];
                    try {
                        $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                        $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/eaten-foods';
                        Log::info("DIARY ADD SEARCH: Requesting", ['url' => $serviceUrl, 'payload' => $payload, 'email' => $activeEmail]);
                        $response = $client->post($serviceUrl, [
                            'json' => $payload,
                            'headers' => [
                                'Accept' => 'application/json',
                                'Authorization' => 'Bearer ' . $nutritionToken
                            ]
                        ]);
                        $statusCode = $response->getStatusCode();
                        $responseBody = json_decode($response->getBody()->getContents(), true);
                        Log::info("DIARY ADD SEARCH: Response", ['status' => $statusCode, 'body' => $responseBody]);
                        if ($statusCode === 201 && isset($responseBody['message']) && $responseBody['message'] === "Food saved successfully" && isset($responseBody['data'])) {
                            $savedFoodName = $responseBody['data']['food_name'] ?? ($diaryEntryData['found_product_name'] ?? 'Прием пищи');
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Прием пищи '{$savedFoodName}' успешно записан в дневник на сервере!",
                                'reply_markup' => $this->keyboardService->makeDiaryMenu()
                            ]);
                        } else {
                            $errorMessage = $this->extractErrorMessage($responseBody, 'питания (дневник - поиск)');
                            Log::warning("DIARY ADD SEARCH: Ошибка записи в дневник", ['status_code' => $statusCode, 'body' => $responseBody, 'sent_payload' => $payload]);
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Ошибка записи в дневник: {$errorMessage}",
                                'reply_markup' => $this->keyboardService->makeDiaryMenu()
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->handleGuzzleError($e, $chatId, "питания (запись в дневник - поиск)");break;
                    }

                } elseif ($text === '❌ Нет') {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Запись в дневник отменена.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                } else {
                    $productName = $diaryEntryData['found_product_name'] ?? 'Продукт';
                    $grams = $diaryEntryData['grams'] ?? 0;
                    $p_port = $diaryEntryData['p_port'] ?? 0;
                    $f_port = $diaryEntryData['f_port'] ?? 0;
                    $c_port = $diaryEntryData['c_port'] ?? 0;
                    $kcal_port = $diaryEntryData['kcal_port'] ?? 0;
                    $eatenDateFormatted = date('d.m.Y', strtotime($diaryEntryData['date'] ?? time()));
                    $confirmMsg = "Добавить в дневник на {$eatenDateFormatted}?\n";
                    $confirmMsg .= "{$productName} - {$grams} г\n";
                    $confirmMsg .= "Б: {$p_port}, Ж: {$f_port}, У: {$c_port}, К: {$kcal_port} (расчет.)";
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Пожалуйста, нажмите \"✅ Да\" или \"❌ Нет\".\n\n" . $confirmMsg,
                        'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                    ]);
                    break; 
                }
                $this->userStates[$chatId] = States::DIARY_MENU;
                unset($this->userSelections[$chatId]['diary_entry']);
                break;

            
                case States::AWAITING_GRAMS_MANUAL_ADD:
                if (!is_numeric($text) || $text <= 0 || $text > 5000) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректно. Введите вес порции в граммах (больше 0 и не более 5000) или "Назад".', 'reply_markup' => $this->keyboardService->makeBackOnly() ]);
                } else {
                    if (!isset($this->userSelections[$chatId]['diary_entry'])) {
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
                $productName = trim($text);
                if (empty($productName)) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Название продукта не может быть пустым. Введите снова или "Назад".',
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                } else {
                    $this->userSelections[$chatId]['diary_entry']['name'] = $productName; 
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
                    $p = $this->userSelections[$chatId]['diary_entry']['p'] ?? 0;
                    $f = $this->userSelections[$chatId]['diary_entry']['f'] ?? 0;
                    $c = (float)$text;
                    $kcal = round($p * 4 + $f * 9 + $c * 4);
                    $this->userSelections[$chatId]['diary_entry']['kcal'] = $kcal;
                    $this->userStates[$chatId] = States::AWAITING_ADD_MEAL_CONFIRM_MANUAL;
                    $dData = $this->userSelections[$chatId]['diary_entry'];
                    $confirmMsg = "Добавить в дневник?\n{$dData['name']} - {$dData['grams']} г\nБ: {$dData['p']} Ж: {$dData['f']} У: {$dData['c']} К: {$dData['kcal']} (расчет.)";
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $confirmMsg, 'reply_markup' => $this->keyboardService->makeConfirmYesNo() ]);
                }
                break;

            case States::AWAITING_ADD_MEAL_CONFIRM_MANUAL:
                $diaryEntryData = $this->userSelections[$chatId]['diary_entry'] ?? null;
                if (!$diaryEntryData || !isset($diaryEntryData['date'], $diaryEntryData['grams'], $diaryEntryData['name'], $diaryEntryData['p'], $diaryEntryData['f'], $diaryEntryData['c'])) {
                    Log::error("DIARY MANUAL CONFIRM: Неполные данные для добавления в дневник для chatId {$chatId}", ['selection' => $diaryEntryData]);
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка, не все данные были собраны. Попробуйте добавить заново.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                    $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']);
                    break;
                }
                if ($text === '✅ Да') {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;

                    if (!$activeEmail || !$nutritionToken) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Аккаунт или токен для сервиса питания не определен.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                        $this->userStates[$chatId] = States::DIARY_MENU; unset($this->userSelections[$chatId]['diary_entry']);
                        break;
                    }
                    $payload = [
                        'food_name' => $diaryEntryData['name'],
                        'proteins' => (float) $diaryEntryData['p'],
                        'fats' => (float) $diaryEntryData['f'],     
                        'carbs' => (float) $diaryEntryData['c'],    
                        'weight' => (float) $diaryEntryData['grams'],
                        'eaten_at' => $diaryEntryData['date']     
                    ];
                    try {
                        $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                        $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/eaten-foods';
                        Log::info("DIARY ADD MANUAL: Requesting", ['url' => $serviceUrl, 'payload' => $payload, 'email' => $activeEmail]);
                        $response = $client->post($serviceUrl, [
                            'json' => $payload,
                            'headers' => [
                                'Accept' => 'application/json',
                                'Authorization' => 'Bearer ' . $nutritionToken
                            ]
                        ]);
                        $statusCode = $response->getStatusCode();
                        $responseBody = json_decode($response->getBody()->getContents(), true);
                        Log::info("DIARY ADD MANUAL: Response", ['status' => $statusCode, 'body' => $responseBody]);
                        if ($statusCode === 201 && isset($responseBody['message']) && $responseBody['message'] === "Food saved successfully" && isset($responseBody['data']['food_name'])) {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Прием пищи '{$responseBody['data']['food_name']}' успешно записан в дневник на сервере!",
                                'reply_markup' => $this->keyboardService->makeDiaryMenu()
                            ]);
                        } else {
                            $errorMessage = $this->extractErrorMessage($responseBody, 'питания (дневник)');
                            Log::warning("DIARY ADD MANUAL: Ошибка записи в дневник", ['status_code' => $statusCode, 'body' => $responseBody, 'sent_payload' => $payload]);
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Ошибка записи в дневник: {$errorMessage}",
                                'reply_markup' => $this->keyboardService->makeDiaryMenu()
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->handleGuzzleError($e, $chatId, "питания (запись в дневник)");break;
                    }
                } elseif ($text === '❌ Нет') {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Запись в дневник отменена.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                } else {
                    $confirmMsg = "Добавить в дневник?\n{$diaryEntryData['name']} - {$diaryEntryData['grams']} г\n";
                    $confirmMsg .= "Б: {$diaryEntryData['p']} Ж: {$diaryEntryData['f']} У: {$diaryEntryData['c']} К: {$diaryEntryData['kcal']} (расчет.)";
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Пожалуйста, нажмите \"✅ Да\" или \"❌ Нет\".\n\n" . $confirmMsg,
                        'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                    ]);
                    break;
                }
                $this->userStates[$chatId] = States::DIARY_MENU;
                unset($this->userSelections[$chatId]['diary_entry']);
                break;
            case States::AWAITING_DATE_DELETE_MEAL:
                $dateToDelete = null;
                $normalizedText = strtolower(trim($text));
                if ($normalizedText === 'вчера') { $dateToDelete = date('Y-m-d', strtotime('-1 day')); }
                elseif ($normalizedText === 'сегодня') { $dateToDelete = date('Y-m-d'); }
                elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $text, $matches)) {
                    if (checkdate($matches[2], $matches[1], $matches[3])) { $dateToDelete = "{$matches[3]}-{$matches[2]}-{$matches[1]}"; }
                }
                elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $matches)) { 
                if (checkdate($matches[2], $matches[3], $matches[1])) { $dateToLog = $text; }
                }
                if (!$dateToDelete) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректный формат даты...', 'reply_markup' => $this->keyboardService->makeBackOnly()]);
                    break;
                }
                $activeEmail = $this->getActiveAccountEmail($chatId);
                $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;
                if (!$activeEmail || !$nutritionToken) { $this->userStates[$chatId] = States::DIARY_MENU; break; }
                try {
                    $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                    $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/eaten-foods/show-by-date';
                    $queryParams = ['date' => $dateToDelete];
                    Log::info("DIARY DELETE (LIST): Запрос списка приемов пищи для удаления", ['url' => $serviceUrl, 'params' => $queryParams, 'email' => $activeEmail]);
                    $response = $client->get($serviceUrl, ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $nutritionToken], 'query' => $queryParams]);
                    $statusCode = $response->getStatusCode();
                    $responseBody = json_decode($response->getBody()->getContents(), true);
                    Log::info("DIARY DELETE (LIST): Ответ от сервиса", ['status' => $statusCode, 'body_preview' => substr(json_encode($responseBody), 0, 300)]);
                    if ($statusCode === 200 && isset($responseBody['data']['items'])) {
                        $eatenItems = $responseBody['data']['items'];
                        if (empty($eatenItems)) {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Нет записей за " . date('d.m.Y', strtotime($dateToDelete)) . ". Возврат в меню Дневника.",
                                'reply_markup' => $this->keyboardService->makeDiaryMenu()
                            ]);
                            $this->userStates[$chatId] = States::DIARY_MENU;
                        } else {
                            $deleteListMsg = "Какой прием пищи удалить за " . date('d.m.Y', strtotime($dateToDelete)) . "? (Введите номер или 'Назад')\n\n";
                            $mealMap = []; 
                            $i = 1;
                            foreach ($eatenItems as $item) {
                                $deleteListMsg .= sprintf(
                                    "%d. %s (%s г) - Б:%s Ж:%s У:%s К:%s\n", 
                                    $i,
                                    $item['food_name'] ?? 'Без имени',
                                    $item['weight'] ?? '0',
                                    $item['proteins'] ?? '0', $item['fats'] ?? '0', $item['carbs'] ?? '0', $item['kcal'] ?? '0'
                                );
                                if (isset($item['id'])) {
                                    $mealMap[$i] = $item['id']; 
                                }
                                $i++;
                            }
                            $this->userSelections[$chatId]['diary_delete_map'] = $mealMap;
                            $this->userStates[$chatId] = States::AWAITING_MEAL_NUMBER_DELETE;
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => rtrim($deleteListMsg), 'reply_markup' => $this->keyboardService->makeBackOnly()]);
                        }
                    } else {$this->userStates[$chatId] = States::DIARY_MENU; }
                } catch (\Throwable $e) { $this->handleGuzzleError($e, $chatId, "питания (список для удаления)"); $this->userStates[$chatId] = States::DIARY_MENU; }
                break;

            case States::AWAITING_MEAL_NUMBER_DELETE:
                $mealMap = $this->userSelections[$chatId]['diary_delete_map'] ?? null;

                if (!$mealMap) {
                    Log::error("DIARY DELETE NUMBER: diary_delete_map не найден для chatId {$chatId}");
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Произошла ошибка (данные для удаления не найдены). Пожалуйста, начните удаление заново из меню Дневника.',
                        'reply_markup' => $this->keyboardService->makeDiaryMenu()
                    ]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_delete_map']);
                    unset($this->userSelections[$chatId]['diary_entry_id_to_delete']);
                    break;
                }

                if (!ctype_digit($text) || !isset($mealMap[(int)$text])) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Неверный номер. Введите номер приема пищи из списка или "Назад".',
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                    break;
                }

                $selectedNumber = (int)$text;
                $mealEntryIdToDelete = $mealMap[$selectedNumber];
                $this->userSelections[$chatId]['diary_entry_id_to_delete'] = $mealEntryIdToDelete;
                $mealNameToConfirm = "запись (ID: {$mealEntryIdToDelete})";
                $confirmText = "Вы уверены, что хотите удалить прием пищи {$mealNameToConfirm}?";

                $this->userStates[$chatId] = States::AWAITING_DELETE_MEAL_CONFIRM;
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $confirmText,
                    'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                ]);
                break;
            case States::AWAITING_DELETE_MEAL_CONFIRM:
                $mealEntryIdToDelete = $this->userSelections[$chatId]['diary_entry_id_to_delete'] ?? null;

                if (!$mealEntryIdToDelete) {
                    Log::error("DIARY DELETE CONFIRM: diary_entry_id_to_delete не найден для chatId {$chatId}");
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка подтверждения удаления. Попробуйте снова.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    unset($this->userSelections[$chatId]['diary_delete_map']);
                    unset($this->userSelections[$chatId]['diary_entry_id_to_delete']);
                    break;
                }

                if ($text === '✅ Да') {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;

                    if (!$activeEmail || !$nutritionToken) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Аккаунт или токен для сервиса питания не определен.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                        $this->userStates[$chatId] = States::DIARY_MENU;
                        unset($this->userSelections[$chatId]['diary_delete_map'], $this->userSelections[$chatId]['diary_entry_id_to_delete']);
                        break;
                    }
                    try {
                        $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                        $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . "/api/v1/eaten-foods/" . $mealEntryIdToDelete;
                        Log::info("DIARY DELETE ENTRY: Requesting", ['url' => $serviceUrl, 'id_to_delete' => $mealEntryIdToDelete, 'email' => $activeEmail]);
                        $response = $client->delete($serviceUrl, [
                            'headers' => [
                                'Accept' => 'application/json',
                                'Authorization' => 'Bearer ' . $nutritionToken
                            ]
                        ]);
                        $statusCode = $response->getStatusCode();
                        $responseBody = json_decode($response->getBody()->getContents(), true);
                        Log::info("DIARY DELETE ENTRY: Response", ['status' => $statusCode, 'body' => $responseBody]);
                        if ($statusCode === 200 && isset($responseBody['message']) && $responseBody['message'] === "Food deleted successfully") {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => 'Запись о приеме пищи успешно удалена с сервера.',
                                'reply_markup' => $this->keyboardService->makeDiaryMenu()
                            ]);
                        } else {
                            $errorMessage = $this->extractErrorMessage($responseBody, 'питания (удаление из дневника)');
                            Log::warning("DIARY DELETE ENTRY: Ошибка удаления из дневника", ['status_code' => $statusCode, 'body' => $responseBody, 'id_deleted' => $mealEntryIdToDelete]);
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Не удалось удалить запись из дневника: {$errorMessage}",
                                'reply_markup' => $this->keyboardService->makeDiaryMenu()
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->handleGuzzleError($e, $chatId, "питания (удаление из дневника)");break;
                    }
                } elseif ($text === '❌ Нет') {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Удаление отменено.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                } else {
                    $confirmText = "Вы уверены, что хотите удалить запись о приеме пищи (ID: {$mealEntryIdToDelete})?";
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Пожалуйста, нажмите \"✅ Да\" или \"❌ Нет\".\n\n" . $confirmText,
                        'reply_markup' => $this->keyboardService->makeConfirmYesNo()
                    ]);
                    break;
                }
                $this->userStates[$chatId] = States::DIARY_MENU;
                unset($this->userSelections[$chatId]['diary_delete_map']);
                unset($this->userSelections[$chatId]['diary_entry_id_to_delete']);
                break;
            case States::AWAITING_DATE_VIEW_MEAL:
                $dateToView = null;
                $normalizedText = strtolower(trim($text));
                if ($normalizedText === 'вчера') { $dateToView = date('Y-m-d', strtotime('-1 day')); }
                elseif ($normalizedText === 'сегодня') { $dateToView = date('Y-m-d'); }
                elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $text, $matches)) {
                    if (checkdate($matches[2], $matches[1], $matches[3])) { $dateToView = "{$matches[3]}-{$matches[2]}-{$matches[1]}"; }
                }elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $matches)) { 
                if (checkdate($matches[2], $matches[3], $matches[1])) { $dateToLog = $text; }
                }

                if (!$dateToView) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Некорректный формат даты...', 'reply_markup' => $this->keyboardService->makeBackOnly()]);

                    break;
                }

                $activeEmail = $this->getActiveAccountEmail($chatId);
                $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;

                if (!$activeEmail || !$nutritionToken) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Аккаунт или токен для сервиса питания не определен.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                    $this->userStates[$chatId] = States::DIARY_MENU;
                    break;
                }

                try {
                    $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                    $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/eaten-foods/show-by-date';

                    $queryParams = [
                        'date' => $dateToView,
                    ];
                    Log::info("DIARY VIEW RATION: Requesting", ['url' => $serviceUrl, 'params' => $queryParams, 'email' => $activeEmail]);

                    $response = $client->get($serviceUrl, [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $nutritionToken
                        ],
                        'query' => $queryParams
                    ]);
                    $statusCode = $response->getStatusCode();
                    $responseBody = json_decode($response->getBody()->getContents(), true);
                    Log::info("DIARY VIEW RATION: Response", ['status' => $statusCode, 'body_preview' => substr(json_encode($responseBody), 0, 300)]);
                    if ($statusCode === 200 && isset($responseBody['data']['items'])) {
                        $eatenItems = $responseBody['data']['items'];
                        $totals = [
                            'proteins' => $responseBody['data']['Total proteins'] ?? 0,
                            'fats' => $responseBody['data']['Total fats'] ?? 0,
                            'carbs' => $responseBody['data']['Total carbs'] ?? 0,
                            'kcal' => $responseBody['data']['Total kcal'] ?? 0,
                        ];
                        if (empty($eatenItems)) {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "За дату " . date('d.m.Y', strtotime($dateToView)) . " нет записей о приемах пищи.",
                                'reply_markup' => $this->keyboardService->makeDiaryMenu()
                            ]);
                        } else {
                            $rationMsg = "Ваш рацион за " . date('d.m.Y', strtotime($dateToView)) . " (аккаунт: {$activeEmail}):\n\n";
                            $i = 1;
                            foreach ($eatenItems as $item) {
                                $rationMsg .= sprintf(
                                    "%d. %s (%s г)\n   Б: %s, Ж: %s, У: %s, К: %s\n",
                                    $i++,
                                    $item['food_name'] ?? 'Без имени',
                                    $item['weight'] ?? '0',
                                    $item['proteins'] ?? '0',
                                    $item['fats'] ?? '0',
                                    $item['carbs'] ?? '0',
                                    $item['kcal'] ?? '0'
                                );
                            }
                            $rationMsg .= "\n--------------------\n";
                            $rationMsg .= sprintf(
                                "ИТОГО за день:\nБ: %.2f г, Ж: %.2f г, У: %.2f г, К: %.2f ккал",
                                (float)$totals['proteins'], (float)$totals['fats'], (float)$totals['carbs'], (float)$totals['kcal']
                            );
                            if (isset($responseBody['meta']) && $responseBody['meta']['current_page'] < $responseBody['meta']['last_page']) {
                                $rationMsg .= "\n...\nПоказаны записи с первой страницы. Всего записей: " . $responseBody['meta']['total'];
                            }

                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => rtrim($rationMsg),
                                'reply_markup' => $this->keyboardService->makeDiaryMenu(),
                            ]);
                        }
                    } else {
                        $errorMessage = $this->extractErrorMessage($responseBody, 'питания (просмотр рациона)');
                        Log::warning("DIARY VIEW RATION: Ошибка получения рациона", ['status_code' => $statusCode, 'body' => $responseBody]);
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Не удалось загрузить рацион: {$errorMessage}",
                            'reply_markup' => $this->keyboardService->makeDiaryMenu()
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->handleGuzzleError($e, $chatId, "питания (просмотр рациона)");break;
                }
                $this->userStates[$chatId] = States::DIARY_MENU;
                break;
        } 
    }


    private function handleExerciseSelectionState(int $chatId, string $text, Message $message, int $currentState): void
    {
        $group = $this->userSelections[$chatId]['group'] ?? null;
        $type = $this->userSelections[$chatId]['type'] ?? null;
        $mode = $this->userSelections[$chatId]['training_mode'] ?? 'log'; 

        switch ($currentState) {
            case States::SELECTING_MUSCLE_GROUP:
                $groupKeys = array_keys($this->exercises);
                if (in_array($text, $groupKeys)) {
                    $selectedGroup = $text;
                    $this->userSelections[$chatId]['group'] = $selectedGroup; 
                    $this->userStates[$chatId] = States::SELECTING_EXERCISE_TYPE;
                    $typeKeys = isset($this->exercises[$selectedGroup]) ? array_keys($this->exercises[$selectedGroup]) : [];
                    if (empty($typeKeys)) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Для группы '{$selectedGroup}' не найдено типов упражнений. Возврат.", 'reply_markup' => $this->keyboardService->makeTrainingMenu()]);
                        $this->userStates[$chatId] = States::LOGGING_TRAINING_MENU; unset($this->userSelections[$chatId]['group'], $this->userSelections[$chatId]['training_mode']); break;
                    }
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Группа: {$selectedGroup}\nВыберите тип:", 'reply_markup' => $this->keyboardService->makeOptionsMenu($typeKeys, true, 2)]);
                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, выберите группу мышц с помощью кнопок.', 'reply_markup' => $this->keyboardService->makeOptionsMenu($groupKeys, true, 2)]);
                }
                break;

            case States::SELECTING_EXERCISE_TYPE:
                if (!$group || !isset($this->exercises[$group])) { /* ... обработка ошибки отсутствия группы ... */ break; }
                $typeKeys = array_keys($this->exercises[$group]);
                if (in_array($text, $typeKeys)) {
                    $selectedType = $text;
                    $this->userSelections[$chatId]['type'] = $selectedType; 
                    $this->userStates[$chatId] = States::SELECTING_EXERCISE;
                    $exerciseListObjects = isset($this->exercises[$group][$selectedType]) ? $this->exercises[$group][$selectedType] : [];
                    $exerciseNames = [];
                    foreach ($exerciseListObjects as $ex) { $exerciseNames[] = is_array($ex) && isset($ex['name']) ? $ex['name'] : (is_string($ex) ? $ex : 'Неизв. упр.'); }
                    if (empty($exerciseNames)) { /* ... обработка ошибки нет упражнений ... */ break; }
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Тип: {$selectedType}\nВыберите упражнение:", 'reply_markup' => $this->keyboardService->makeOptionsMenu($exerciseNames, true, 1)]);
                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, выберите тип упражнения с помощью кнопок.', 'reply_markup' => $this->keyboardService->makeOptionsMenu($typeKeys, true, 2)]);
                }
                break;

            case States::SELECTING_EXERCISE:
                if (!$group || !$type || !isset($this->exercises[$group][$type])) { /* ... обработка ошибки некорректных данных ... */ break; }

                $exerciseListObjects = $this->exercises[$group][$type];
                $selectedExerciseName = null;
                foreach ($exerciseListObjects as $ex) {
                    $currentExName = is_array($ex) && isset($ex['name']) ? $ex['name'] : (is_string($ex) ? $ex : null);
                    if ($currentExName && $text === $currentExName) {
                        $selectedExerciseName = $currentExName;
                        break;
                    }
                }

                if ($selectedExerciseName) {
                    if ($mode === 'log') { 
                        $this->userSelections[$chatId]['exercise'] = $selectedExerciseName;
                        $this->userStates[$chatId] = States::AWAITING_REPS;
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Упражнение: {$selectedExerciseName}\nВведите количество повторений:",
                            'reply_markup' => $this->keyboardService->makeBackOnly()
                        ]);
                    } elseif ($mode === 'technique') { 
                        $activeEmail = $this->getActiveAccountEmail($chatId);
                        $workoutToken = $this->userData[$chatId]['accounts'][$activeEmail]['workout_api_token'] ?? null;
                        if (!$activeEmail || !$workoutToken) { /* ... ошибка нет аккаунта/токена ... */ }
                        else {
                            try {
                                $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                                $encodedExerciseName = rawurlencode($selectedExerciseName);
                                $serviceUrl = env('WORKOUT_SERVICE_BASE_URI', 'http://localhost:8001') . "/api/v1/exercise/by-name/{$encodedExerciseName}/guide";
                                Log::info("WORKOUT TECHNIQUE: Запрос гайда", ['url' => $serviceUrl, 'exercise' => $selectedExerciseName]);
                                $response = $client->get($serviceUrl, ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $workoutToken]]);
                                $statusCode = $response->getStatusCode(); $responseBody = json_decode($response->getBody()->getContents(), true);
                                Log::info("WORKOUT TECHNIQUE: Ответ", ['status' => $statusCode, 'body' => $responseBody]);
                                if ($statusCode === 200 && !empty($responseBody['data']['tutorial'])) {
                                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Гайд по '{$selectedExerciseName}':\n{$responseBody['data']['tutorial']}", 'disable_web_page_preview' => false]);
                                } else { /* ... сообщение "гайд не найден" или ошибка API ... */ }
                            } catch (\GuzzleHttp\Exception\ClientException $e) { if ($e->getResponse() && $e->getResponse()->getStatusCode() == 404) { $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Гайд для '{$selectedExerciseName}' не найден."]); } else { $this->handleGuzzleError($e, $chatId, "тренировок (гайд)"); }}
                            catch (\Throwable $e) { $this->handleGuzzleError($e, $chatId, "тренировок (гайд)"); }
                        }
                        $this->userStates[$chatId] = States::LOGGING_TRAINING_MENU;
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Выберите следующее действие:', 'reply_markup' => $this->keyboardService->makeTrainingMenu()]);
                        unset($this->userSelections[$chatId]['group'], $this->userSelections[$chatId]['type'], $this->userSelections[$chatId]['training_mode']);

                    } elseif ($mode === 'view_progress') { 
                        $activeEmail = $this->getActiveAccountEmail($chatId);
                        $workoutToken = $this->userData[$chatId]['accounts'][$activeEmail]['workout_api_token'] ?? null;
                        if (!$activeEmail || !$workoutToken || !$group) { /* ... ошибка нет данных ... */ }
                        else {
                            try {
                                $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                                $serviceUrl = env('WORKOUT_SERVICE_BASE_URI', 'http://localhost:8001') . "/api/v1/user-exercise-progress";
                                $queryParams = ['muscle_group' => $group, 'exercise_name' => $selectedExerciseName];
                                Log::info("WORKOUT PROGRESS: Запрос", ['url' => $serviceUrl, 'params' => $queryParams]);
                                $response = $client->get($serviceUrl, ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $workoutToken], 'query' => $queryParams]);
                                $statusCode = $response->getStatusCode(); $responseBody = json_decode($response->getBody()->getContents(), true);
                                Log::info("WORKOUT PROGRESS: Ответ", ['status' => $statusCode, 'body' => $responseBody]);
                                if ($statusCode === 200 && isset($responseBody['data']) && !empty($responseBody['data']['record_weight'])) {
                                    $progressData = $responseBody['data'];
                                    $progressMsg = "Прогресс по '{$selectedExerciseName}' (Группа: {$group}):\n";
                                    $progressMsg .= "- Рекорд: {$progressData['record_weight']}кг x {$progressData['record_repeats']}\n";
                                    $progressMsg .= "- Последний: {$progressData['last_weight']}кг x {$progressData['last_repeats']}\n";
                                    if (isset($progressData['updated_at'])) { try { $utcDate = new \DateTime($progressData['updated_at'], new \DateTimeZone('UTC')); $utcDate->setTimezone(new \DateTimeZone('Europe/Moscow')); $progressMsg .= "(Обновлено: " . $utcDate->format('d.m.Y H:i') . " МСК)"; } catch (\Exception $e) {}}
                                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $progressMsg]);
                                } else { /* ... сообщение "нет данных о прогрессе" или ошибка API ... */ }
                            } catch (\GuzzleHttp\Exception\ClientException $e) { if ($e->getResponse() && $e->getResponse()->getStatusCode() == 404) { $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Данные о прогрессе для '{$selectedExerciseName}' не найдены."]); } else { $this->handleGuzzleError($e, $chatId, "тренировок (прогресс)"); }}
                            catch (\Throwable $e) { $this->handleGuzzleError($e, $chatId, "тренировок (прогресс)"); }
                        }
                        $this->userStates[$chatId] = States::LOGGING_TRAINING_MENU;
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Выберите следующее действие:', 'reply_markup' => $this->keyboardService->makeTrainingMenu()]);
                        unset($this->userSelections[$chatId]['group'], $this->userSelections[$chatId]['type'], $this->userSelections[$chatId]['training_mode']);

                    } else { 
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Внутренняя ошибка: неизвестный режим.', 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                        $this->userStates[$chatId] = States::DEFAULT;
                        unset($this->userSelections[$chatId]['group'], $this->userSelections[$chatId]['type'], $this->userSelections[$chatId]['training_mode']);
                    }
                } else { 
                    $exerciseListObjects = $this->exercises[$group][$type]; $exerciseNames = [];
                    foreach ($exerciseListObjects as $ex) { $exerciseNames[] = is_array($ex) && isset($ex['name']) ? $ex['name'] : (is_string($ex) ? $ex : 'Неизв. упр.');}
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Пожалуйста, выберите упражнение с помощью кнопок.', 'reply_markup' => $this->keyboardService->makeOptionsMenu($exerciseNames, true, 1)]);
                }
                break; 
        } 
    }
    private function handleTrainingLogInputState(int $chatId, string $text, Message $message, int $currentState): void
    {
        if ($currentState === States::AWAITING_REPS && (!is_numeric($text) || $text <= 0 || $text > 1000)) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Некорректный ввод. Введите целое положительное число повторений (не более 1000) или 'Назад'.", 
                'reply_markup' => $this->keyboardService->makeBackOnly()
            ]);
            return;
        }
        if ($currentState === States::AWAITING_WEIGHT && (!is_numeric($text) || $text < 0 || $text > 1000)) { 
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Некорректный ввод. Введите вес (число от 0 до 1000) или 'Назад'.", 
                'reply_markup' => $this->keyboardService->makeBackOnly()
            ]);
            return;
        }
        if ($currentState === States::AWAITING_REPS) {
            $this->userSelections[$chatId]['reps'] = $text;
            $this->userStates[$chatId] = States::AWAITING_WEIGHT;
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Повторения: {$text}\nВведите вес (можно 0):",
                'reply_markup' => $this->keyboardService->makeBackOnly()
            ]);
        } elseif ($currentState === States::AWAITING_WEIGHT) {
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
        $unprotectedCommands = ['/start'];
        if (!in_array($text, $unprotectedCommands) && !isset($this->userData[$chatId])) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пожалуйста, сначала зарегистрируйтесь или войдите с помощью команды /start.',
                'reply_markup' => $this->keyboardService->removeKeyboard()
            ]);
            return; 
        }
        switch ($text) {
            case '/start':
                if (isset($this->userData[$chatId]) && !empty($this->userData[$chatId]['accounts'])) {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    $activeName = $this->userData[$chatId]['accounts'][$activeEmail]['name'] ?? 'Пользователь';
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "С возвращением, {$activeName}! Чем могу помочь?",
                        'reply_markup' => $this->keyboardService->makeMainMenu()
                    ]);
                    $this->userStates[$chatId] = States::DEFAULT;
                    unset($this->userSelections[$chatId]); 
                } else {
                    $this->userStates[$chatId] = States::SHOWING_WELCOME_MESSAGE;
                    unset($this->userData[$chatId]); 
                    unset($this->userSelections[$chatId]);

                    $welcomeText = "👋 Добро пожаловать в PIUS Bot!\n\n" .
                                "Я помогу тебе отслеживать твое питание и тренировки.\n\n" .
                                "Основные возможности:\n" .
                                "🍏 Ведение дневника питания (БЖУК)\n" .
                                "💪 Запись тренировок и отслеживание прогресса\n" .
                                "📊 Аналитика (в будущем)\n\n" .
                                "Нажми \"Начать\", чтобы создать свой первый аккаунт и приступить!";

                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => $welcomeText,
                        'reply_markup' => $this->keyboardService->makeSingleButtonMenu('🚀 Начать!')
                    ]);
                }
                break;
            case '⚙️ Аккаунт':
                 if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Настройки аккаунта:',
                        'reply_markup' => $this->keyboardService->makeAccountMenu()
                    ]);
                 } 
                break;
            case 'ℹ️ Имя и почта':
                    if (in_array($currentState, [States::DEFAULT, States::BJU_MENU, States::DIARY_MENU])) {
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
                             $this->telegram->sendMessage([
                                 'chat_id' => $chatId,
                                 'text' => 'Ошибка: Активный аккаунт не найден.',
                                 'reply_markup' => $this->keyboardService->makeMainMenu()
                             ]);
                              $this->userStates[$chatId] = States::DEFAULT; 
                        }
                    }
                break;
            
            case '🤸 Посмотреть технику':
                    if (in_array($currentState, [States::DEFAULT, States::LOGGING_TRAINING_MENU, States::DIARY_MENU, States::BJU_MENU])) { 
                        $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP; 

                        if (!isset($this->userSelections[$chatId])) { 
                        $this->userSelections[$chatId] = [];
                        }
                        $this->userSelections[$chatId]['training_mode'] = 'technique';
                        unset($this->userSelections[$chatId]['group']);
                        unset($this->userSelections[$chatId]['type']);
                        unset($this->userSelections[$chatId]['exercise']);; 

                        $groupKeys = array_keys($this->exercises);
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Для просмотра техники, выберите группу мышц:\n" . $this->generateListMessage($groupKeys),
                            'reply_markup' => $this->keyboardService->makeOptionsMenu($groupKeys, true, 2)
                        ]);
                    }
                   break;
            case '➕ Добавить аккаунт':
                        if (in_array($currentState, [States::DEFAULT, States::BJU_MENU, States::DIARY_MENU])) { 
                            $this->userStates[$chatId] = States::AWAITING_NEW_ACCOUNT_NAME;
                            unset($this->userSelections[$chatId]['new_account_data']);
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Добавление нового аккаунта.\nВведите имя для нового аккаунта:",
                                'reply_markup' => $this->keyboardService->makeBackOnly()
                            ]);
                        }
                break;

            case '🔄 Переключить аккаунт':
                if (in_array($currentState, [States::DEFAULT, States::BJU_MENU, States::DIARY_MENU ])) {
                    if (!isset($this->userData[$chatId]['accounts']) || count($this->userData[$chatId]['accounts']) < 1) {
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Не найдено ни одного аккаунта.', 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                            $this->userStates[$chatId] = States::DEFAULT;
                    } elseif (count($this->userData[$chatId]['accounts']) === 1) {
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'У вас только один аккаунт.', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                    } else {
                            $accountListMsg = "Выберите аккаунт для переключения:\n\n";
                            $i = 1;
                            $accountsForSelection = []; 
                            $activeEmail = $this->getActiveAccountEmail($chatId); 
                                $sortedAccounts = $this->userData[$chatId]['accounts'];
                            ksort($sortedAccounts);
                            foreach ($sortedAccounts as $email => $accData) {
                                $isActive = ($email === $activeEmail) ? ' (активный)' : '';
                                $accountListMsg .= sprintf("%d. %s (%s)%s\n", $i, $accData['name'], $accData['email'], $isActive);
                                $accountsForSelection[$i] = $email;
                                $i++;
                            }
                            $accountListMsg .= "\nВведите номер аккаунта:";
                                $this->userSelections[$chatId]['account_switch_map'] = $accountsForSelection;
                            $this->userStates[$chatId] = States::AWAITING_ACCOUNT_SWITCH_SELECTION;
    
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => $accountListMsg,
                                'reply_markup' => $this->keyboardService->removeKeyboard()
                            ]);
                    }
                }
                break;
            case '💪 Тренировки':
                if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Раздел тренировок:',
                        'reply_markup' => $this->keyboardService->makeTrainingMenu()
                    ]);
                 }
                break;
            case '➕ Записать тренировку':
                if ($currentState === States::DEFAULT ) {
                    $this->userStates[$chatId] = States::LOGGING_TRAINING_MENU; 
                    $this->currentTrainingLog[$chatId] = []; 
                    unset($this->userSelections[$chatId]); 
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Начало записи тренировки. Добавьте первый подход/упражнение:',
                        'reply_markup' => $this->keyboardService->makeAddExerciseMenu()
                    ]);
                 }
                break;
            case '📈 Посмотреть прогресс':
                 if (in_array($currentState, [States::DEFAULT, States::LOGGING_TRAINING_MENU])) {
                     $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP; 

                    if (!isset($this->userSelections[$chatId])) { 
                        $this->userSelections[$chatId] = [];
                    }
                    $this->userSelections[$chatId]['training_mode'] = 'view_progress';
                    unset($this->userSelections[$chatId]['group']);
                    unset($this->userSelections[$chatId]['type']);
                    unset($this->userSelections[$chatId]['exercise']);

                     $groupKeys = array_keys($this->exercises);
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => "Для просмотра прогресса, выберите группу мышц:\n" . $this->generateListMessage($groupKeys),
                         'reply_markup' => $this->keyboardService->makeOptionsMenu($groupKeys, true, 2)
                     ]);
                 }
                break;
            case '📊 Отстающие группы': 
                if (in_array($currentState, [States::DEFAULT, States::LOGGING_TRAINING_MENU,])) {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    if (!$activeEmail) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Активный аккаунт не определен.', 'reply_markup' => $this->keyboardService->makeTrainingMenu()]);
                        $this->userStates[$chatId] = States::DEFAULT;
                        break;
                    }
                    $workoutToken = $this->userData[$chatId]['accounts'][$activeEmail]['workout_api_token'] ?? null;
                    if (!$workoutToken) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Токен для сервиса тренировок не найден.', 'reply_markup' => $this->keyboardService->makeTrainingMenu()]);
                        $this->userStates[$chatId] = States::DEFAULT;
                        break;
                    }
                    try {
                        $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                        $serviceUrl = env('WORKOUT_SERVICE_BASE_URI', 'http://localhost:8001') . '/api/v1/lagging-muscle-groups';

                        Log::info("WORKOUT LAGGING GROUPS: Запрос", ['url' => $serviceUrl, 'email' => $activeEmail]);
                        $response = $client->get($serviceUrl, [
                            'headers' => [
                                'Accept' => 'application/json',
                                'Authorization' => 'Bearer ' . $workoutToken
                            ]
                        ]);
                        $statusCode = $response->getStatusCode();
                        $responseBody = json_decode($response->getBody()->getContents(), true);
                        Log::info("WORKOUT LAGGING GROUPS: Ответ от сервера", ['status' => $statusCode, 'body' => $responseBody]);
                        if ($statusCode === 200 && isset($responseBody['data']['lagging_muscle_groups'])) {
                            $laggingGroups = $responseBody['data']['lagging_muscle_groups'];
                            if (empty($laggingGroups)) {
                                $this->telegram->sendMessage([
                                    'chat_id' => $chatId,
                                    'text' => 'Нет данных об отстающих группах мышц, или все группы прорабатываются равномерно!',
                                    'reply_markup' => $this->keyboardService->makeMainMenu() 
                                ]);
                            } else {
                                $messageText = "Отстающие группы мышц (в порядке приоритета):\n";
                                $i = 1;
                                foreach ($laggingGroups as $group) {
                                    $messageText .= "{$i}. {$group}\n";
                                    $i++;
                                }
                                $this->telegram->sendMessage([
                                    'chat_id' => $chatId,
                                    'text' => rtrim($messageText),
                                    'reply_markup' => $this->keyboardService->makeTrainingMenu() 
                                ]);
                            }
                        } else {
                            $errorMessage = $this->extractErrorMessage($responseBody, "тренировок (отстающие группы)");
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Не удалось получить данные об отстающих группах: {$errorMessage}",
                                'reply_markup' => $this->keyboardService->makeTrainingMenu() 
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->handleGuzzleError($e, $chatId, "тренировок (отстающие группы)");
                    }
                    $this->userStates[$chatId] = States::DEFAULT;

                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Эта функция доступна из меню тренировок.', 'reply_markup' => $this->keyboardService->makeTrainingMenu()]);
                }
                break;

            case '➕ Добавить упражнение':
                if ($currentState === States::LOGGING_TRAINING_MENU) {
                    $this->userStates[$chatId] = States::SELECTING_MUSCLE_GROUP;
                    $this->userSelections[$chatId]['mode'] = 'log';
                    $groupKeys = array_keys($this->exercises);
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Выберите группу мышц:\n" . $this->generateListMessage($groupKeys),
                        'reply_markup' => $this->keyboardService->makeOptionsMenu($groupKeys, true, 2)
                    ]);
                 }
                break;
            case '✅ Завершить запись': 
                if (in_array($currentState, [
                States::LOGGING_TRAINING_MENU,     
                States::SELECTING_MUSCLE_GROUP,     
                States::SELECTING_EXERCISE_TYPE,    
                States::SELECTING_EXERCISE,         
                States::AWAITING_REPS,              
                States::AWAITING_WEIGHT])) {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    if (!$activeEmail) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Активный аккаунт не определен.', 'reply_markup' => $this->keyboardService->makeMainMenu()]); 
                        $this->userStates[$chatId] = States::DEFAULT; unset($this->currentTrainingLog[$chatId]);
                        break;
                    }
                    $workoutToken = $this->userData[$chatId]['accounts'][$activeEmail]['workout_api_token'] ?? null;
                    if (!$workoutToken) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Токен для сервиса тренировок не найден. Попробуйте переключить или добавить аккаунт заново.', 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                        $this->userStates[$chatId] = States::DEFAULT; unset($this->currentTrainingLog[$chatId]);
                        break;
                    }
                    $currentLog = $this->currentTrainingLog[$chatId] ?? [];
                    $logCount = count($currentLog);

                    if (empty($currentLog)) {
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Вы не записали ни одного подхода. Тренировка не сохранена.',
                            'reply_markup' => $this->keyboardService->makeMainMenu() 
                        ]);
                        $this->userStates[$chatId] = States::DEFAULT; 
                        break;
                    }
                    $apiExercisesPayload = [];
                    foreach ($currentLog as $logEntry) {
                        $apiExercisesPayload[] = [
                            'exercise_name' => $logEntry['exercise'],
                            'weight' => (float) $logEntry['weight'],
                            'reps' => (int) $logEntry['reps']
                        ];
                    }
                    $payload = ['exercises' => $apiExercisesPayload];
                    $apiCallSuccessful = false;
                    try {
                        $client = new \GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 5]);
                        $serviceUrl = env('WORKOUT_SERVICE_BASE_URI', 'http://localhost:8001') . '/api/v1/workouts';

                        Log::info("WORKOUT SAVE: Отправка данных тренировки на сервер", ['url' => $serviceUrl, 'email' => $activeEmail, 'exercise_count' => count($apiExercisesPayload)]);
                        $response = $client->post($serviceUrl, [
                            'json' => $payload,
                            'headers' => [
                                'Accept' => 'application/json',
                                'Authorization' => 'Bearer ' . $workoutToken
                            ]
                        ]);
                        $statusCode = $response->getStatusCode();
                        $responseBody = json_decode($response->getBody()->getContents(), true);
                        Log::info("WORKOUT SAVE: Ответ от сервера", ['status' => $statusCode, 'body' => $responseBody]);

                        if ($statusCode === 201 && isset($responseBody['data']['message']) && $responseBody['data']['message'] === "Workout saved successfully") {
                            $ignoredCount = count($responseBody['data']['ignored_exercises'] ?? []);
                            $successMsg = "Тренировка завершена и записана на сервер ({$logCount} подходов/упр.). Отличная работа!";
                            if ($ignoredCount > 0) {
                                $successMsg .= "\n(Предупреждение: {$ignoredCount} упр. не были распознаны/сохранены сервисом)";
                            }
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $successMsg, 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                            $apiCallSuccessful = true;
                        } else {
                            $errorMessage = $this->extractErrorMessage($responseBody, 'тренировок (сохранение)');
                            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "Ошибка сохранения тренировки на сервере: {$errorMessage}", 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                        }
                    } catch (\Throwable $e) {
                        $this->handleGuzzleError($e, $chatId, "тренировок (сохранение)");
                    }
                    $this->userStates[$chatId] = States::DEFAULT;
                    unset($this->userSelections[$chatId]); 
                    if ($apiCallSuccessful) {
                        unset($this->currentTrainingLog[$chatId]); 
                    }
                } else {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Завершение тренировки доступно только во время ее записи или из меню записи тренировки.', 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                    $this->userStates[$chatId] = States::DEFAULT;
                }
                break;
            case '🍎 Питание':
                if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Раздел питания:',
                        'reply_markup' => $this->keyboardService->makeNutritionMenu()
                    ]);
                 }
                break;
            case '📖 Дневник':
                if ($currentState === States::DEFAULT ) {
                     $this->userStates[$chatId] = States::DIARY_MENU;
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => "Дневник питания:",
                         'reply_markup' => $this->keyboardService->makeDiaryMenu()
                     ]);
                 }
                break;
            case '🔍 БЖУ продуктов':
                 if ($currentState === States::DEFAULT ) {
                     $this->userStates[$chatId] = States::BJU_MENU;
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => 'Управление базой БЖУ ваших продуктов:',
                         'reply_markup' => $this->keyboardService->makeBjuMenu()
                     ]);
                 }
                break;
            case '➕ Записать приём пищи':
                 if ($currentState === States::DIARY_MENU) {
                     $this->userStates[$chatId] = States::AWAITING_ADD_MEAL_OPTION;
                     unset($this->userSelections[$chatId]['diary_entry']);
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => 'Как вы хотите записать прием пищи?',
                         'reply_markup' => $this->keyboardService->makeAddMealOptionsMenu()
                     ]);
                 }
                break;
            case '🗑️ Удалить приём пищи':
                if ($currentState === States::DIARY_MENU || $currentState === States::DEFAULT) {
                    $activeEmail = $this->getActiveAccountEmail($chatId);
                    if (!$activeEmail) {
                        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Активный аккаунт не определен.', 'reply_markup' => $this->keyboardService->makeDiaryMenu()]);
                        break;
                    }
                    $this->userStates[$chatId] = States::AWAITING_DATE_DELETE_MEAL;
                    unset($this->userSelections[$chatId]['diary_delete_map']);
                    unset($this->userSelections[$chatId]['diary_entry_id_to_delete']);
                    unset($this->userSelections[$chatId]['diary_entry']);
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'За какую дату удалить прием пищи? (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":',
                        'reply_markup' => $this->keyboardService->makeDateSelectionInline()
                    ]);
                } else {
                    Log::warning("Кнопка '🗑️ Удалить приём пищи' нажата в неожиданном состоянии: {$currentState} для chatId {$chatId}");
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Действие недоступно из текущего меню.',
                        'reply_markup' => $this->keyboardService->makeDiaryMenu() 
                    ]);
                    $this->userStates[$chatId] = States::DIARY_MENU; 
                }
                break;
            case '🗓️ Посмотреть рацион':
                 if ($currentState === States::DIARY_MENU) {
                     $this->userStates[$chatId] = States::AWAITING_DATE_VIEW_MEAL; 
                     $this->telegram->sendMessage([
                         'chat_id' => $chatId,
                         'text' => 'Введите дату для просмотра рациона (ДД.ММ.ГГГГ, сегодня, вчера) или "Назад":',
                         'reply_markup' => $this->keyboardService->makeDateSelectionInline()
                     ]);
                 }
                break;
            case '💾 Сохранить продукт':
                 if ($currentState === States::BJU_MENU) {
                     $this->userStates[$chatId] = States::AWAITING_PRODUCT_NAME_SAVE; 
                     unset($this->userSelections[$chatId]['bju_product']); 
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
                    if (!$activeEmail) { break; }
                    $nutritionToken = $this->userData[$chatId]['accounts'][$activeEmail]['nutrition_api_token'] ?? null;
                    if (!$nutritionToken) { break; }

                    try {
                        $client = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
                        $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/saved-foods';
                        Log::info("NUTRITION DELETE (LIST): Запрос списка продуктов для удаления", ['url' => $serviceUrl, 'email' => $activeEmail]);
                        $response = $client->get($serviceUrl, [
                            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $nutritionToken]
                        ]);
                        $statusCode = $response->getStatusCode();
                        $responseBody = json_decode($response->getBody()->getContents(), true);

                        if ($statusCode === 200 && isset($responseBody['data'])) {
                            $products = $responseBody['data'];
                            if (empty($products)) {
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'У вас нет сохраненных продуктов для удаления.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                            } else {
                                $deleteListMsg = "Какой продукт удалить? (Введите номер или 'Назад')\n\n";
                                $productMap = [];
                                $i = 1;
                                foreach ($products as $product) {
                                    $deleteListMsg .= sprintf("%d. %s (ID: %s)\n", $i, $product['food_name'] ?? 'Без имени', $product['id'] ?? 'N/A');
                                    if (isset($product['id'])) {
                                        $productMap[$i] = $product['id']; 
                                    }
                                    $i++;
                                }
                                $this->userSelections[$chatId]['product_to_delete_map'] = $productMap; 
                                $this->userStates[$chatId] = States::AWAITING_PRODUCT_NUMBER_DELETE;
                                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => rtrim($deleteListMsg), 'reply_markup' => $this->keyboardService->makeBackOnly()]);
                            }
                        } else { }
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
                        $serviceUrl = env('NUTRITION_SERVICE_BASE_URI', 'http://localhost:8080') . '/api/v1/saved-foods';
                        $queryParams = [
                        ];

                        Log::info("NUTRITION GET SAVED FOODS: Requesting", ['url' => $serviceUrl, 'email' => $activeEmail, 'params' => $queryParams]);

                        $response = $client->get($serviceUrl, [
                            'headers' => [
                                'Accept' => 'application/json',
                                'Authorization' => 'Bearer ' . $nutritionToken
                            ],
                            'query' => $queryParams 
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
                                    $productListMsg .= sprintf(
                                        "%d. %s (ID: %s)\n   Б: %s, Ж: %s, У: %s, К: %s / 100г\n",
                                        $i++,
                                        $product['food_name'] ?? 'Без имени',
                                        $product['id'] ?? 'N/A', 
                                        $product['proteins'] ?? '0', 
                                        $product['fats'] ?? '0',
                                        $product['carbs'] ?? '0',
                                        $product['kcal'] ?? '0' 
                                    );
                                }
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
            case '🔎 Поиск':
                $activeEmail = $this->getActiveAccountEmail($chatId);
                if (!$activeEmail) {
                    $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: Активный аккаунт не определен.', 'reply_markup' => $this->keyboardService->makeBjuMenu()]);
                    $this->userStates[$chatId] = States::BJU_MENU;
                    break;
                }

                if ($currentState === States::BJU_MENU || $currentState === States::DEFAULT) {
                    $this->userStates[$chatId] = States::AWAITING_PRODUCT_NAME_SEARCH;
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Введите название продукта для поиска в вашей базе на сервере (или "Назад"):',
                        'reply_markup' => $this->keyboardService->makeBackOnly()
                    ]);
                } else {
                    Log::warning("Кнопка '🔎 Поиск' (БЖУ) нажата в неожиданном состоянии: {$currentState} для chatId {$chatId}");
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Действие недоступно из текущего меню.',
                        'reply_markup' => $this->keyboardService->makeBjuMenu()
                    ]);
                    $this->userStates[$chatId] = States::BJU_MENU;
                }
                break;

            case '⬅️ Назад':
                Log::info("Обработка 'Назад' в handleMenuCommands, текущее состояние: {$currentState}");
                if (in_array($currentState, [
                    States::LOGGING_TRAINING_MENU,
                    States::DIARY_MENU,
                    States::BJU_MENU,
                ])) {
                    $this->userStates[$chatId] = States::DEFAULT;
                    unset($this->currentTrainingLog[$chatId]); 
                    unset($this->userSelections[$chatId]);     
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Выход в главное меню.',
                        'reply_markup' => $this->keyboardService->makeMainMenu()
                    ]);
                }
                elseif ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Главное меню.',
                        'reply_markup' => $this->keyboardService->makeMainMenu()
                    ]);
                    unset($this->userSelections[$chatId]);
                } else {

                    Log::warning("Кнопка 'Назад' нажата в неожиданном состоянии: {$currentState} для chatId {$chatId}. Возврат в главное меню.");
                    $this->userStates[$chatId] = States::DEFAULT;
                    unset($this->userSelections[$chatId]);
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Действие отменено. Возврат в главное меню.',
                        'reply_markup' => $this->keyboardService->makeMainMenu()
                    ]);
                }
                break;

            default: 
                if ($currentState === States::DEFAULT) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Неизвестная команда. Пожалуйста, используйте кнопки меню.',
                        'reply_markup' => $this->keyboardService->makeMainMenu()
                    ]);
                } elseif (in_array($currentState, [
                    States::LOGGING_TRAINING_MENU,
                    States::DIARY_MENU,
                    States::BJU_MENU,
                    
                ])) {
                    
                    $menuKeyboard = match ($currentState) {
                        States::LOGGING_TRAINING_MENU => $this->keyboardService->makeTrainingMenu(),
                        States::DIARY_MENU => $this->keyboardService->makeDiaryMenu(),
                        States::BJU_MENU => $this->keyboardService->makeBjuMenu(),
                        default => $this->keyboardService->makeMainMenu() 
                    };
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Пожалуйста, используйте кнопки.',
                        'reply_markup' => $menuKeyboard
                    ]);
                } else {
                    Log::warning("Необработанный текст '{$text}' в состоянии {$currentState} попал в default handleMenuCommands.", ['chat_id' => $chatId]);
                }
                break;
        }
    }
    private function getActiveAccountEmail(int $chatId): ?string
    {
        if (isset($this->userData[$chatId]['active_account_email'])) {
            $activeEmail = $this->userData[$chatId]['active_account_email'];
            if (isset($this->userData[$chatId]['accounts'][$activeEmail])) {
                return $activeEmail;
            } else {
                echo "Warning: Active account email '{$activeEmail}' not found in accounts for chat ID {$chatId}.\n";
                return null;
            }
        }
        return null;
    }
    private function getActiveAccountData(int $chatId): ?array
    {
        $activeEmail = $this->getActiveAccountEmail($chatId);
        if ($activeEmail) {
            return $this->userData[$chatId]['accounts'][$activeEmail];
        }
        return null;
    }
    private function handleNewAccountState(int $chatId, string $text, Message $message, int $currentState): void
    {
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

        if ($currentState === States::AWAITING_NEW_ACCOUNT_PASSWORD) {
            $plainPassword = $text;
            $passwordIsValid = true; $passwordErrors = [];
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

            $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Пароль принят. Пожалуйста, подождите, идет регистрация вашего аккаунта в системе... Это может занять несколько секунд.',
            'reply_markup' => $this->keyboardService->removeKeyboard() 
            ]);

            $nutritionApiToken = $this->registerAndLoginNutritionService($chatId, $name, $email, $plainPassword);
            if (!$nutritionApiToken) {
                $this->userStates[$chatId] = States::DEFAULT;
                unset($this->userSelections[$chatId]['new_account_data']);
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Добавление нового аккаунта прервано из-за ошибки с сервисом питания.', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                return;
            }
            $workoutApiToken = $this->registerWorkoutService($chatId, $name, $email, $plainPassword);
            if (!$workoutApiToken) {

                $this->userStates[$chatId] = States::DEFAULT;
                unset($this->userSelections[$chatId]['new_account_data']);
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Добавление нового аккаунта прервано из-за ошибки с сервисом тренировок.', 'reply_markup' => $this->keyboardService->makeAccountMenu()]);
                return;
            }
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
        } 
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
                return; 
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

            $client = new \GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 5]); 

            $nutritionTokenValid = false;
            $workoutTokenValid = false;
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
                } catch (\GuzzleHttp\Exception\ClientException $e) { 
                    Log::warning("SWITCH_ACC NUTRITION: Ошибка клиента (4xx) при проверке токена для {$selectedEmail} - Статус: " . $e->getResponse()->getStatusCode() . ", Сообщение: " . $e->getMessage());
                } catch (\Throwable $e) { 
                    $this->handleGuzzleError($e, $chatId, "питания (проверка токена)"); 
                }
            }

            if (!$workoutToken) {
                Log::warning("SWITCH_ACC WORKOUT: Нет workout_api_token для {$selectedEmail} у chatId {$chatId}");
            } else {
                try {
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
                } catch (\GuzzleHttp\Exception\ClientException $e) { 
                    Log::warning("SWITCH_ACC WORKOUT: Ошибка клиента (4xx) при проверке токена для {$selectedEmail} - Статус: " . $e->getResponse()->getStatusCode() . ", Сообщение: " . $e->getMessage());
                } catch (\Throwable $e) { 
                    $this->handleGuzzleError($e, $chatId, "тренировок (проверка токена)");
                }
            }

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
                $this->userStates[$chatId] = States::DEFAULT;
            }
            unset($this->userSelections[$chatId]['account_switch_map']);
        }
    }
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
    private function handleGuzzleError(\Throwable $e, int $chatId, string $serviceNameForUser): void
    {
        $userMessage = "Произошла ошибка при обращении к сервису {$serviceNameForUser}. Попробуйте позже.";
        if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
            $userMessage = "Не удалось подключиться к сервису {$serviceNameForUser}. Проверьте доступность сервиса и попробуйте позже.";
            Log::error("Ошибка СОЕДИНЕНИЯ с сервисом {$serviceNameForUser}: " . $e->getMessage(), ['chat_id' => $chatId]);
        } elseif ($e instanceof \GuzzleHttp\Exception\ClientException) { 
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $responseBodyContent = $response->getBody()->getContents();
            $apiErrorMessage = $this->extractErrorMessage(json_decode($responseBodyContent, true) ?: [], $serviceNameForUser . " (ошибка клиента {$statusCode})");
            $userMessage = "Ошибка от сервиса {$serviceNameForUser} (код: {$statusCode}): {$apiErrorMessage}. Попробуйте позже.";
            Log::warning("Ошибка КЛИЕНТА (4xx) от сервиса {$serviceNameForUser}", ['chat_id' => $chatId, 'status' => $statusCode, 'response' => $responseBodyContent, 'exception_message' => $e->getMessage()]);
        } elseif ($e instanceof \GuzzleHttp\Exception\ServerException) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $userMessage = "Сервис {$serviceNameForUser} временно недоступен (ошибка сервера {$statusCode}). Пожалуйста, попробуйте позже.";
            Log::error("Ошибка СЕРВЕРА (5xx) от сервиса {$serviceNameForUser}", ['chat_id' => $chatId, 'status' => $statusCode, 'exception_message' => $e->getMessage()]);
        } else { 
            Log::error("НЕПРЕДВИДЕННАЯ ошибка при запросе к сервису {$serviceNameForUser}: " . $e->getMessage(), ['chat_id' => $chatId, 'exception' => $e]);
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $userMessage,
            'reply_markup' => $this->keyboardService->makeMainMenu() 
        ]);
        $this->userStates[$chatId] = States::DEFAULT;
        unset($this->userSelections[$chatId]);
    }
    private function handleCallbackQuery(int $chatId, string $callbackData, int $messageId): void
    {
        Log::info("Получен CallbackQuery", ['chat_id' => $chatId, 'data' => $callbackData, 'message_id' => $messageId]);
        $currentState = $this->userStates[$chatId] ?? States::DEFAULT;
        $dateToProcess = null;

        if ($callbackData === 'date_today') {
            $dateToProcess = date('Y-m-d');
        } elseif ($callbackData === 'date_yesterday') {
            $dateToProcess = date('Y-m-d', strtotime('-1 day'));
        } elseif ($callbackData === 'date_cancel') {
            $this->telegram->editMessageText([ 
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => 'Выбор даты отменен.',
                'reply_markup' => json_encode(['inline_keyboard' => []]) 
            ]);

            return;
        }

        if ($dateToProcess) {
            $this->telegram->editMessageReplyMarkup([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode(['inline_keyboard' => []]) 
            ]);
            if (in_array($currentState, [
                States::AWAITING_DATE_MANUAL_ADD, States::AWAITING_DATE_SEARCH_ADD,
                States::AWAITING_DATE_VIEW_MEAL, States::AWAITING_DATE_DELETE_MEAL
            ])) {
                $fakeMessage = new Message(['text' => $dateToProcess, 'chat' => ['id' => $chatId]]);
                Log::info("Проталкиваем дату '{$dateToProcess}' из callback в состояние {$currentState}");

                if ($currentState === States::AWAITING_DATE_MANUAL_ADD ||
                    $currentState === States::AWAITING_DATE_SEARCH_ADD ||
                    $currentState === States::AWAITING_DATE_VIEW_MEAL ||
                    $currentState === States::AWAITING_DATE_DELETE_MEAL)
                {

                    $dateTextForHandler = date('d.m.Y', strtotime($dateToProcess));
                    $fakeMessage = new Message(['text' => $dateTextForHandler, 'chat' => ['id' => $chatId]]);

                    $this->handleDiaryStates($chatId, $dateTextForHandler, $fakeMessage, $currentState);
                }

            } else {
                Log::warning("CallbackQuery для даты получен в неожиданном состоянии: {$currentState}", ['chat_id' => $chatId, 'data' => $callbackData]);
                $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'Произошла ошибка при выборе даты. Пожалуйста, попробуйте еще раз.', 'reply_markup' => $this->keyboardService->makeMainMenu()]);
                $this->userStates[$chatId] = States::DEFAULT;
            }
        } else {
            Log::warning("Неизвестный callback_data", ['chat_id' => $chatId, 'data' => $callbackData]);

        }
    }
}