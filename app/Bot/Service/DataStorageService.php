<?php

// app/Bot/Service/DataStorageService.php
namespace Bot\Service;

class DataStorageService
{
    private string $userDataFile;
    private string $userProductsFile;
    private string $diaryFile;
    private string $trainingLogFile;
    private array $trainingLogData = []; // <-- ДОБАВИТЬ

    // Массивы для хранения загруженных данных внутри сервиса
    private array $userData = [];
    private array $userProducts = [];
    private array $diaryData = [];

    public function __construct(string $storagePath)
    {
        // Убедимся, что папка существует или создаем ее
        if (!is_dir($storagePath)) {
            if (!mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $storagePath));
            }
            echo "Created storage directory by Service: {$storagePath}\n";
        }

        $this->userDataFile = $storagePath . '/bot_users.json';
        // Загружаем данные при инициализации сервиса
        $this->loadAllData();
    }

    // --- Публичные методы для получения данных ---

    public function getAllUserData(): array
    {
        return $this->userData;
    }

    public function getAllUserProducts(): array
    {
        return $this->userProducts;
    }

    public function getAllDiaryData(): array
    {
        return $this->diaryData;
    }
    public function getAllTrainingLogData(): array
    {
        return $this->trainingLogData;
    }

    // --- Публичные методы для сохранения ВСЕХ данных ---
    // BotKernel будет передавать обновленные полные массивы

    public function saveAllUserData(array $allUserData): bool
    {
        $this->userData = $allUserData; // Обновляем данные внутри сервиса
        return $this->saveJsonData($this->userData, $this->userDataFile);
    }

    public function saveAllUserProducts(array $allUserProducts): bool
    {
        $this->userProducts = $allUserProducts; // Обновляем данные внутри сервиса

        // Мы просто передаем полученный массив дальше в saveJsonData.
        // Логика обработки пустых {} и ksort должна быть применена ПЕРЕД вызовом этого метода,
        // если она нужна. В BotKernel данные уже должны быть в правильном виде.
        // Старая логика с ensureObjectsForKey здесь была избыточна и могла мешать.

        // Просто сохраняем ту структуру, которую передал BotKernel
        return $this->saveJsonData($this->userProducts, $this->userProductsFile);
    }

    public function saveAllDiaryData(array $allDiaryData): bool
    {
        $this->diaryData = $allDiaryData;
        // Для дневника обычно не нужно сохранять пустые {}
        // Можно добавить сортировку дат или записей, если нужно
        // ksort($this->diaryData); // Сортировка по chatId
        // foreach ($this->diaryData as $chatId => &$accountsData) {
        //    if (is_array($accountsData)) {
        //         ksort($accountsData); // Сортировка по email
        //         foreach ($accountsData as $email => &$dates) {
        //             if (is_array($dates)) {
        //                 ksort($dates); // Сортировка дат
        //             }
        //         }
        //     }
        // }
        return $this->saveJsonData($this->diaryData, $this->diaryFile);
    }
    // ---> ДОБАВИТЬ МЕТОД СОХРАНЕНИЯ ЛОГОВ ТРЕНИРОВОК <---
    public function saveAllTrainingLogData(array $allTrainingLogData): bool
    {
        $this->trainingLogData = $allTrainingLogData; // Обновляем данные внутри сервиса
        // Можно добавить сортировку дат, если нужно
        // ksort($this->trainingLogData);
        // foreach ($this->trainingLogData as &$accountsData) { ... }
        return $this->saveJsonData($this->trainingLogData, $this->trainingLogFile);
    }


    // --- Приватные методы загрузки/сохранения (перенесены из BotKernel) ---

    private function loadAllData(): void
    {
        $this->userData = $this->loadJsonData($this->userDataFile, "user data");
        
    }

    /**
     * Загружает данные из JSON-файла.
     * Возвращает массив данных или пустой массив в случае ошибки.
     */
    private function loadJsonData(string $filePath, string $dataType): array
    {
        if (!file_exists($filePath)) {
            echo "{$dataType} file not found at {$filePath}. Returning empty array.\n";
            // Создаем пустой файл, чтобы избежать ошибок при первом сохранении
             file_put_contents($filePath, '[]');
             return [];
        }

        $jsonContent = file_get_contents($filePath);
        if (empty($jsonContent)) {
            echo "{$dataType} file is empty at {$filePath}. Returning empty array.\n";
            return [];
        }

        $decodedData = json_decode($jsonContent, true);

        // Проверка на ошибки JSON и тип данных
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Error decoding {$dataType} JSON from {$filePath}: " . json_last_error_msg() . ". Returning empty array.\n";
            // Можно добавить логирование ошибки или бэкап файла
            return [];
        }

        if (!is_array($decodedData)) {
            echo "Decoded {$dataType} data from {$filePath} is not an array. Returning empty array.\n";
             return [];
        }

        echo ucfirst($dataType) . " loaded successfully from {$filePath}.\n";
        return $decodedData;
    }

    private function saveJsonData(array $data, string $filePath): bool
    {
        // Флаги JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION подходят
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($jsonContent === false) {
            echo "Error encoding JSON for {$filePath}: " . json_last_error_msg() . "\n";
            return false;
        }

        // Атомарная запись (оставляем без изменений)
        $tempFilePath = $filePath . '.tmp';
        if (file_put_contents($tempFilePath, $jsonContent) === false) {
            echo "Error writing to temporary file: {$tempFilePath}\n";
            return false;
        }
        chmod($tempFilePath, 0664);
        if (!rename($tempFilePath, $filePath)) {
             echo "Error renaming temporary file {$tempFilePath} to {$filePath}\n";
             @unlink($tempFilePath); // Попытка удалить временный файл
             return false;
        }

        echo "Data saved successfully to {$filePath}\n";
        return true;
    }
}