<?php

namespace Bot\Keyboard;

use Telegram\Bot\Keyboard\Keyboard;

class KeyboardService
{
    public function makeMainMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => '💪 Тренировки']),
                Keyboard::button(['text' => '🍎 Питание'])
            ])
            ->row([
                Keyboard::button(['text' => '⚙️ Аккаунт']) 
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeTrainingMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => '➕ Записать тренировку'])
            ])
            ->row([
                Keyboard::button(['text' => '📈 Посмотреть прогресс']), 
                Keyboard::button(['text' => '🤸 Посмотреть технику']) 
            ])
            ->row([
                Keyboard::button(['text' => '📊 Отстающие группы']) 
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeNutritionMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([

                Keyboard::button(['text' => '📖 Дневник']) 
            ])
            ->row([
                Keyboard::button(['text' => '🔍 БЖУ продуктов']) 
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeAccountMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'ℹ️ Имя и почта'])
            ])
            ->row([
                Keyboard::button(['text' => '🔄 Переключить аккаунт']),
                Keyboard::button(['text' => '➕ Добавить аккаунт'])
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeBackOnly(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeAddExerciseMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => '➕ Добавить упражнение'])
            ])
            ->row([
                Keyboard::button(['text' => '✅ Завершить запись'])
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeBjuMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => '💾 Сохранить продукт']) 
            ])
            ->row([
                Keyboard::button(['text' => '🗑️ Удалить продукт']) 
            ])
            ->row([
                Keyboard::button(['text' => '📜 Сохранённые']) 
            ])
            ->row([
                Keyboard::button(['text' => '🔎 Поиск']) 
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeConfirmYesNo(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => '✅ Да']),
                Keyboard::button(['text' => '❌ Нет'])
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(true);
    }

    public function makeDiaryMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => '➕ Записать приём пищи'])
            ])
            ->row([
                Keyboard::button(['text' => '🗑️ Удалить приём пищи'])
            ])
            ->row([
                Keyboard::button(['text' => '🗓️ Посмотреть рацион'])
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function makeAddMealOptionsMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => '🔍 Поиск в базе']) 
            ])
            ->row([
                Keyboard::button(['text' => '✍️ Записать БЖУ вручную'])
            ])
            ->row([
                Keyboard::button(['text' => '⬅️ Назад'])
            ])
            ->setResizeKeyboard(true)->setOneTimeKeyboard(false);
    }

    public function removeKeyboard(): string 
    {
        return Keyboard::remove();
    }
}