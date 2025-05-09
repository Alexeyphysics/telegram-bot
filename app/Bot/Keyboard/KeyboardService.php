<?php

// app/Bot/Keyboard/KeyboardService.php
namespace Bot\Keyboard;

use Telegram\Bot\Keyboard\Keyboard;

class KeyboardService
{
    public function makeMainMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                // ---> ДОБАВЛЕНЫ EMOJI <---
                Keyboard::button(['text' => '💪 Тренировки']),
                Keyboard::button(['text' => '🍎 Питание'])
            ])
            ->row([
                Keyboard::button(['text' => '⚙️ Аккаунт']) 
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeTrainingMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                // ---> ДОБАВЛЕНЫ EMOJI <---
                Keyboard::button(['text' => '➕ Записать тренировку'])
            ])
            ->row([
                Keyboard::button(['text' => '📈 Посмотреть прогресс']), // Изменил emoji
                Keyboard::button(['text' => '🤸 Посмотреть технику']) // Или ℹ️
            ])
            ->row([
                Keyboard::button(['text' => '📊 Отстающие группы']) // Заменил текст и добавил emoji
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeNutritionMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                // ---> ДОБАВЛЕНЫ EMOJI <---
                Keyboard::button(['text' => '📖 Дневник']) // Или 🗓️
            ])
            ->row([
                Keyboard::button(['text' => '🔍 БЖУ продуктов']) // Или 🍔
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeAccountMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                 // ---> ДОБАВЛЕНЫ EMOJI <---
                Keyboard::button(['text' => 'ℹ️ Имя и почта'])
            ])
            ->row([
                Keyboard::button(['text' => '🔄 Переключить аккаунт']),
                Keyboard::button(['text' => '➕ Добавить аккаунт'])
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeBackOnly(): Keyboard
    {
        return Keyboard::make()
            ->row([
                 // ---> ДОБАВЛЕН EMOJI <---
                Keyboard::button(['text' => '⬅️ Назад'])
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeAddExerciseMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                // ---> ДОБАВЛЕНЫ EMOJI <---
                Keyboard::button(['text' => '➕ Добавить упражнение'])
            ])
            ->row([
                Keyboard::button(['text' => '✅ Завершить запись']) // Изменил текст
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeBjuMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                 // ---> ДОБАВЛЕНЫ EMOJI <---
                Keyboard::button(['text' => '💾 Сохранить продукт']) // Изменил текст
            ])
            ->row([
                Keyboard::button(['text' => '🗑️ Удалить продукт']) // Изменил текст
            ])
            ->row([
                Keyboard::button(['text' => '📜 Сохранённые']) // Изменил текст
            ])
            ->row([
                Keyboard::button(['text' => '🔎 Поиск']) // Изменил текст
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeConfirmYesNo(): Keyboard
    {
        return Keyboard::make()
            ->row([
                 // ---> ДОБАВЛЕНЫ EMOJI <---
                Keyboard::button(['text' => '✅ Да']),
                Keyboard::button(['text' => '❌ Нет'])
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(true);
    }

    public function makeDiaryMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                 // ---> ДОБАВЛЕНЫ EMOJI <---
                Keyboard::button(['text' => '➕ Записать приём пищи'])
            ])
            ->row([
                Keyboard::button(['text' => '🗑️ Удалить приём пищи'])
            ])
            ->row([
                Keyboard::button(['text' => '🗓️ Посмотреть рацион']) // Изменил текст
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeAddMealOptionsMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                 // ---> ДОБАВЛЕНЫ EMOJI <---
                Keyboard::button(['text' => '🔍 Поиск в базе']) // Изменил текст
            ])
            ->row([
                Keyboard::button(['text' => '✍️ Записать БЖУ вручную']) // Изменил текст
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
                 // ---> КОНЕЦ ДОБАВЛЕНИЯ <---
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function removeKeyboard(): string // Метод для удаления клавиатуры
    {
        return Keyboard::remove();
    }
}