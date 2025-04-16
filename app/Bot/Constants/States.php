<?php

// app/Bot/Constants/States.php
namespace Bot\Constants;

// Состояния бота
class States
{
    public const DEFAULT = 0;

    // Регистрация/Аккаунт
    public const AWAITING_NAME = 1;
    public const AWAITING_EMAIL = 2;
    public const AWAITING_PASSWORD = 3;
    // Можно добавить состояние для меню Аккаунта, если нужно различать его от DEFAULT
    // public const ACCOUNT_MENU = 4;

    // Запись Тренировки
    public const LOGGING_TRAINING_MENU = 10; // Меню "Добавить/Завершить"
    public const SELECTING_MUSCLE_GROUP = 11;
    public const SELECTING_EXERCISE_TYPE = 12;
    public const SELECTING_EXERCISE = 13;
    public const AWAITING_REPS = 14;
    public const AWAITING_WEIGHT = 15;
    // Состояния для просмотра прогресса/отстающих можно добавить отдельно, если нужно
    // public const VIEWING_PROGRESS_SELECT_GROUP = 16; ...

    // БЖУ Продуктов
    public const BJU_MENU = 20; // Состояние для меню БЖУ (если нужно отличать от DEFAULT)
    public const AWAITING_PRODUCT_NAME_SAVE = 30;
    public const AWAITING_PRODUCT_PROTEIN = 31;
    public const AWAITING_PRODUCT_FAT = 32;
    public const AWAITING_PRODUCT_CARBS = 33;
    //public const AWAITING_PRODUCT_KCAL = 34;
    public const AWAITING_SAVE_CONFIRMATION = 35;
    public const AWAITING_PRODUCT_NUMBER_DELETE = 40;
    public const AWAITING_DELETE_CONFIRMATION = 41;
    public const AWAITING_PRODUCT_NAME_SEARCH = 50;

    // Дневник Питания
    public const DIARY_MENU = 60;
    public const AWAITING_ADD_MEAL_OPTION = 61;
    // -- Добавление через поиск
    public const AWAITING_SEARCH_PRODUCT_NAME_ADD = 62;
    public const AWAITING_GRAMS_SEARCH_ADD = 63;
    public const AWAITING_ADD_MEAL_CONFIRM_SEARCH = 64;
    // -- Добавление вручную
    public const AWAITING_GRAMS_MANUAL_ADD = 65;
    public const AWAITING_PRODUCT_NAME_MANUAL_ADD = 66;
    public const AWAITING_PROTEIN_MANUAL_ADD = 67;
    public const AWAITING_FAT_MANUAL_ADD = 68;
    public const AWAITING_CARBS_MANUAL_ADD = 69;
    //public const AWAITING_KCAL_MANUAL_ADD = 70;
    public const AWAITING_ADD_MEAL_CONFIRM_MANUAL = 71;
    // -- Удаление
    public const AWAITING_DATE_DELETE_MEAL = 80;
    public const AWAITING_MEAL_NUMBER_DELETE = 81;
    public const AWAITING_DELETE_MEAL_CONFIRM = 83;
    // -- Просмотр
    public const AWAITING_DATE_VIEW_MEAL = 90;
}