<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    // Убедись, что твоя команда здесь указана, или просто удали этот массив,
    // чтобы Laravel использовал автообнаружение (рекомендуется).
    // protected $commands = [
    //     \App\Console\Commands\RunTelegramBot::class,
    // ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands'); // Стандартная загрузка команд из папки

        require base_path('routes/console.php');
    }
}