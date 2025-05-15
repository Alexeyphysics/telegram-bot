<?php

namespace Bot\Service;

class DataStorageService
{
    private string $userDataFile;
    private string $userProductsFile;
    private string $diaryFile;
    private string $trainingLogFile;
    private array $trainingLogData = []; 
    private array $userData = [];
    private array $userProducts = [];
    private array $diaryData = [];

    public function __construct(string $storagePath)
    {
        if (!is_dir($storagePath)) {
            if (!mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $storagePath));
            }
            echo "Created storage directory by Service: {$storagePath}\n";
        }

        $this->userDataFile = $storagePath . '/bot_users.json';
        $this->loadAllData();
    }


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

    public function saveAllUserData(array $allUserData): bool
    {
        $this->userData = $allUserData; 
        return $this->saveJsonData($this->userData, $this->userDataFile);
    }

    public function saveAllUserProducts(array $allUserProducts): bool
    {
        $this->userProducts = $allUserProducts;
        return $this->saveJsonData($this->userProducts, $this->userProductsFile);
    }

    public function saveAllDiaryData(array $allDiaryData): bool
    {
        $this->diaryData = $allDiaryData;
        return $this->saveJsonData($this->diaryData, $this->diaryFile);
    }
    public function saveAllTrainingLogData(array $allTrainingLogData): bool
    {
        $this->trainingLogData = $allTrainingLogData; 
        return $this->saveJsonData($this->trainingLogData, $this->trainingLogFile);
    }

    private function loadAllData(): void
    {
        $this->userData = $this->loadJsonData($this->userDataFile, "user data");
        
    }

    private function loadJsonData(string $filePath, string $dataType): array
    {
        if (!file_exists($filePath)) {
            echo "{$dataType} file not found at {$filePath}. Returning empty array.\n";
             file_put_contents($filePath, '[]');
             return [];
        }

        $jsonContent = file_get_contents($filePath);
        if (empty($jsonContent)) {
            echo "{$dataType} file is empty at {$filePath}. Returning empty array.\n";
            return [];
        }

        $decodedData = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Error decoding {$dataType} JSON from {$filePath}: " . json_last_error_msg() . ". Returning empty array.\n";
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
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($jsonContent === false) {
            echo "Error encoding JSON for {$filePath}: " . json_last_error_msg() . "\n";
            return false;
        }

        $tempFilePath = $filePath . '.tmp';
        if (file_put_contents($tempFilePath, $jsonContent) === false) {
            echo "Error writing to temporary file: {$tempFilePath}\n";
            return false;
        }
        chmod($tempFilePath, 0664);
        if (!rename($tempFilePath, $filePath)) {
             echo "Error renaming temporary file {$tempFilePath} to {$filePath}\n";
             return false;
        }

        echo "Data saved successfully to {$filePath}\n";
        return true;
    }
}