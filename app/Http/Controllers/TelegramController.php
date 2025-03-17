<?php

namespace App\Http\Controllers;

use Telegram\Bot\Api;

class TelegramController extends Controller
{
    protected $telegram;

    public function __construct()
    {
        $this->telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    }

    public function handleUpdates()
{
    $updateId = 0;

    while (true) {
        $updates = $this->telegram->getUpdates(['offset' => $updateId + 1]);
        foreach ($updates as $update) {
            $updateId = $update->getUpdateId();

            if ($update->isType('message')) {
                $message = $update->getMessage();
                $chatId = $message->getChat()->getId();
                $text = $message->getText();

                echo "Получено сообщение: $text (Chat ID: $chatId)\n";

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Привет! Ты написал: ' . $text
                ]);
            }
        }
        sleep(1);
    }
}
}
