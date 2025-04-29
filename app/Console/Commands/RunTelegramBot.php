<?php

namespace App\Console\Commands;

use Bot\BotKernel; // <-- Укажи правильный namespace для твоего BotKernel
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log; // <-- Используем логгер Laravel
use Throwable; // <-- Для обработки общих ошибок

class RunTelegramBot extends Command
{

    protected $signature = 'bot:run';

    protected $description = 'Запускает Telegram бота в режиме Long Polling';

    // Внедряем BotKernel через конструктор
    private BotKernel $botKernel;

    public function __construct(BotKernel $botKernel)
    {
        parent::__construct();
        $this->botKernel = $botKernel;
    }

    public function handle(): int
    {
        $this->info('Запуск Telegram бота...'); // Сообщение в консоль

        try {
            // Вызываем основной цикл работы бота из BotKernel
            $this->botKernel->run();
        } catch (Throwable $e) {
            // Логируем критическую ошибку через логгер Laravel
            Log::critical('Telegram бот остановлен с ошибкой: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->error('Бот остановлен с ошибкой: ' . $e->getMessage()); // Сообщение об ошибке в консоль
            // Возвращаем код ошибки
            return Command::FAILURE;
        }

        // Если run() завершится сам (маловероятно в while(true)), вернем успех
        $this->info('Telegram бот завершил работу.');
        return Command::SUCCESS;
    }
}