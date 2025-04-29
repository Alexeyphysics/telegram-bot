<?php

namespace App\Providers;

use Bot\BotKernel;
use Bot\Keyboard\KeyboardService;
use Bot\Service\DataStorageService;
use Illuminate\Contracts\Foundation\Application; // <-- Нужно для Application
use Illuminate\Support\ServiceProvider;
use Telegram\Bot\Api; // <-- Используем SDK

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // --- Настройка Telegram SDK ---
        $this->app->singleton(Api::class, function ($app) {
            // Убедись, что ключ в config совпадает с тем, что ты указал в config/services.php
            $token = config('services.telegram.bot_token');
            if (!$token || $token === 'YOUR_BOT_TOKEN') {
                 throw new \InvalidArgumentException('Telegram Bot Token not configured in config/services.php or .env');
            }
            return new Api($token); // Создаем экземпляр Api
        });

        // --- Настройка DataStorageService ---
        $this->app->singleton(DataStorageService::class, function ($app) {
            // Получаем путь к папке storage/bot
            $storagePath = storage_path('bot'); // Используем хелпер storage_path()
            // Можно использовать storage_path('bot') если папка bot прямо в storage/
            // Или можно взять из конфига: config('bot.storage_path', storage_path('app/bot'))

            // Создаем экземпляр сервиса, передавая путь
            return new DataStorageService($storagePath);
        });

        // --- Настройка KeyboardService ---
        // Если он не имеет зависимостей, можно не регистрировать,
        // Laravel создаст его сам. Но для ясности можно добавить:
        $this->app->singleton(KeyboardService::class, function ($app) {
            return new KeyboardService();
        });

        // --- Настройка BotKernel ---
        // Laravel автоматически внедрит Api, DataStorageService, KeyboardService,
        // если они указаны как type-hint в конструкторе BotKernel.
        // Явное создание не требуется, если конструктор BotKernel будет выглядеть так:
        // public function __construct(Api $telegram, DataStorageService $dataStorage, KeyboardService $keyboardService)

        // Если же BotKernel нужно собрать сложнее, можно сделать так:
        // $this->app->singleton(BotKernel::class, function ($app) {
        //     return new BotKernel(
        //         $app->make(Api::class),
        //         $app->make(DataStorageService::class),
        //         $app->make(KeyboardService::class)
        //         // Передача других зависимостей, если они нужны
        //     );
        // });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}