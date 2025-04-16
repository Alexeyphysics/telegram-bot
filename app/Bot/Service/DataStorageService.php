<?php

// app/Bot/Service/DataStorageService.php
namespace Bot\Service;

class DataStorageService
{
    private string $userDataFile;
    private string $userProductsFile;
    private string $diaryFile;

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
        $this->userProductsFile = $storagePath . '/bot_products.json';
        $this->diaryFile = $storagePath . '/bot_diary.json';

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
        // При сохранении продуктов убедимся, что пустые массивы пользователя
        // сохраняются как пустые объекты JSON {} для консистентности
        $dataToSave = [];
        foreach ($this->userProducts as $chatId => $products) {
            if (is_array($products) && empty($products)) {
                $dataToSave[$chatId] = new \stdClass();
            } elseif (is_array($products)) {
                 ksort($products); // Сортируем продукты по имени для порядка в файле
                 $dataToSave[$chatId] = $products;
            } else {
                $dataToSave[$chatId] = $products; // На случай если там не массив (хотя не должно)
            }
        }
        return $this->saveJsonData($dataToSave, $this->userProductsFile);
    }

    public function saveAllDiaryData(array $allDiaryData): bool
    {
        $this->diaryData = $allDiaryData; // Обновляем данные внутри сервиса
        // Можно добавить сортировку дат или записей внутри дат при необходимости
        return $this->saveJsonData($this->diaryData, $this->diaryFile);
    }


    // --- Приватные методы загрузки/сохранения (перенесены из BotKernel) ---

    private function loadAllData(): void
    {
        $this->userData = $this->loadJsonData($this->userDataFile, "user data");
        $this->userProducts = $this->loadJsonData($this->userProductsFile, "user products");
        $this->diaryData = $this->loadJsonData($this->diaryFile, "diary data");
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

    /**
     * Сохраняет массив данных в JSON-файл.
     */
    private function saveJsonData(array $data, string $filePath): bool
    {
        // Кодируем с флагами для читаемости и корректной обработки Unicode
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($jsonContent === false) {
            echo "Error encoding JSON for {$filePath}: " . json_last_error_msg() . "\n";
            return false;
        }

        // Атомарная запись файла для большей надежности
        $tempFilePath = $filePath . '.tmp';
        if (file_put_contents($tempFilePath, $jsonContent) === false) {
            echo "Error writing to temporary file: {$tempFilePath}\n";
            return false;
        }

        // Права доступа (можно настроить более строго)
        chmod($tempFilePath, 0664);

        // Переименовываем временный файл в основной
        if (!rename($tempFilePath, $filePath)) {
             echo "Error renaming temporary file {$tempFilePath} to {$filePath}\n";
             // Попытка удалить временный файл
             unlink($tempFilePath);
             return false;
        }

        echo "Data saved successfully to {$filePath}\n";
        return true;
    }
}