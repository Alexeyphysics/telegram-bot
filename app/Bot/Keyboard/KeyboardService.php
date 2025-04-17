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
                Keyboard::button(['text' => 'Тренировки']),
                Keyboard::button(['text' => 'Питание'])
            ])
            ->row([
                Keyboard::button(['text' => 'Аккаунт'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);
    }

    public function makeTrainingMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'Записать тренировку'])
            ])
            ->row([
                Keyboard::button(['text' => 'Посмотреть прогресс в упражнениях']),
                Keyboard::button(['text' => 'Посмотреть технику выполнения'])
            ])
            ->row([
                Keyboard::button(['text' => 'Вывести отстающие группы мышц'])
            ])
            ->row([
                Keyboard::button(['text' => 'Назад'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);
    }

    public function makeNutritionMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'Дневник'])
            ])
            ->row([
                Keyboard::button(['text' => 'БЖУ продуктов'])
            ])
            ->row([
                Keyboard::button(['text' => 'Назад'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);
    }

    public function makeAccountMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'Вывести имя и почту']) // Текущий активный
            ])
            // ---> ИЗМЕНЕНО/ДОБАВЛЕНО <---
            ->row([
                Keyboard::button(['text' => 'Переключить аккаунт']), // Переименовано
                Keyboard::button(['text' => 'Добавить аккаунт'])     // Добавлено
            ])
            // ---> КОНЕЦ ИЗМЕНЕНИЙ <---
            ->row([
                Keyboard::button(['text' => 'Назад'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);
    }

    public function makeBackOnly(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'Назад'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);
    }

    public function makeAddExerciseMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'Добавить упражнение'])
            ])
            ->row([
                Keyboard::button(['text' => 'Завершить запись тренировки'])
            ])
            ->row([
                Keyboard::button(['text' => 'Назад'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);
    }

    public function makeBjuMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'Сохранить информацию о продукте'])
            ])
            ->row([
                Keyboard::button(['text' => 'Удалить информацию о продукте'])
            ])
            ->row([
                Keyboard::button(['text' => 'Сохранённые продукты'])
            ])
            ->row([
                Keyboard::button(['text' => 'Поиск продуктов'])
            ])
            ->row([
                Keyboard::button(['text' => 'Назад'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);
    }

    public function makeConfirmYesNo(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'Да']),
                Keyboard::button(['text' => 'Нет'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true); // Оставляем true, т.к. это обычно одноразовое подтверждение
    }

    public function makeDiaryMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'Записать приём пищи'])
            ])
            ->row([
                Keyboard::button(['text' => 'Удалить приём пищи'])
            ])
            ->row([
                Keyboard::button(['text' => 'Посмотреть рацион за дату'])
            ])
            ->row([
                Keyboard::button(['text' => 'Назад'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);
    }

    public function makeAddMealOptionsMenu(): Keyboard
    {
        return Keyboard::make()
            ->row([
                Keyboard::button(['text' => 'Поиск в базе знаний'])
            ])
            ->row([
                Keyboard::button(['text' => 'Записать БЖУ самому'])
            ])
            ->row([
                Keyboard::button(['text' => 'Назад'])
            ])
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);
    }

    public function removeKeyboard(): string // Метод для удаления клавиатуры
    {
        return Keyboard::remove();
    }
}