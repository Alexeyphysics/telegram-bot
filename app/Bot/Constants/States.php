<?php
namespace Bot\Constants;

class States
{
    public const DEFAULT = 0;
    public const AWAITING_NAME = 1;
    public const AWAITING_EMAIL = 2;
    public const AWAITING_PASSWORD = 3;
    public const AWAITING_NEW_ACCOUNT_NAME = 4;  
    public const AWAITING_NEW_ACCOUNT_EMAIL = 5;
    public const AWAITING_NEW_ACCOUNT_PASSWORD = 6;
    public const AWAITING_ACCOUNT_SWITCH_SELECTION = 7;
    public const LOGGING_TRAINING_MENU = 10; 
    public const SELECTING_MUSCLE_GROUP = 11;
    public const SELECTING_EXERCISE_TYPE = 12;
    public const SELECTING_EXERCISE = 13;
    public const AWAITING_REPS = 14;
    public const AWAITING_WEIGHT = 15;
    public const BJU_MENU = 20;
    public const AWAITING_PRODUCT_NAME_SAVE = 30;
    public const AWAITING_PRODUCT_PROTEIN = 31;
    public const AWAITING_PRODUCT_FAT = 32;
    public const AWAITING_PRODUCT_CARBS = 33;
    public const AWAITING_SAVE_CONFIRMATION = 35;
    public const AWAITING_PRODUCT_NUMBER_DELETE = 40;
    public const AWAITING_DELETE_CONFIRMATION = 41;
    public const AWAITING_PRODUCT_NAME_SEARCH = 50;
    public const DIARY_MENU = 60;
    public const AWAITING_ADD_MEAL_OPTION = 61;
    public const AWAITING_SEARCH_PRODUCT_NAME_ADD = 62;
    public const AWAITING_GRAMS_SEARCH_ADD = 63;
    public const AWAITING_ADD_MEAL_CONFIRM_SEARCH = 64;
    public const AWAITING_DATE_MANUAL_ADD = 601;
    public const AWAITING_DATE_SEARCH_ADD = 602; 
    public const AWAITING_GRAMS_MANUAL_ADD = 65;
    public const AWAITING_PRODUCT_NAME_MANUAL_ADD = 66;
    public const AWAITING_PROTEIN_MANUAL_ADD = 67;
    public const AWAITING_FAT_MANUAL_ADD = 68;
    public const AWAITING_CARBS_MANUAL_ADD = 69;
    public const AWAITING_ADD_MEAL_CONFIRM_MANUAL = 71;
    public const AWAITING_DATE_DELETE_MEAL = 80;
    public const AWAITING_MEAL_NUMBER_DELETE = 81;
    public const AWAITING_DELETE_MEAL_CONFIRM = 83;
    public const AWAITING_DATE_VIEW_MEAL = 90;
}