<?php
session_start();
require_once ("utils.php");
require_once ("WSAPI/WSAPI.php");
require_once "functions.php";

$GLOBALS["LANG"] = "EN";
$GLOBALS["DEFAULT_TIMEZONE"] = "Europe/Madrid";
$GLOBALS["DEBUG_LOG"] = false; // Set to true to activate logs (on STDERR)

// Url of the WS-API and LC2
$GLOBALS["WS_LINK"] = "https://dev-api.linkcareapp.com/ServerWSDL.php";

// Credentials of the SERVICE USER
$GLOBALS['SERVICE_USER'] = 'service';
$GLOBALS['SERVICE_PASSWORD'] = 'password';
$GLOBALS['SERVICE_TEAM'] = 'LINKCARE';

// Load particular configuration
if (file_exists(__DIR__ . '/../conf/configuration.php')) {
    include_once __DIR__ . '/../conf/configuration.php';
}

date_default_timezone_set($GLOBALS["DEFAULT_TIMEZONE"]);

// TASK and FORM CODES of the TASK containing the exercises and the summary of every single day
$GLOBALS['TASK_CODES']['TRAINING_EXERCISES'] = 'DT_EJERCICIOS';
$GLOBALS['FORM_CODES']['SUMMARY'] = 'DT_SUMMARY_FORM';

/*
 * ITEM CODES of the TASK where the summary will be generated
 * Each ITEM code is the ITEM_CODE of the first column of an array representing the summary of a group of exercises
 */
$GLOBALS['ITEM_CODES']['ARRAY_EXERCISES'] = 'FECHA_EJERCICIOS';
$GLOBALS['ITEM_CODES']['ARRAY_STRETCHING'] = 'FECHA_ESTIRAMIENTOS';

// Item with the effort level informed by the patient after doing an exercise
$GLOBALS['ITEM_CODES']['EFFORT'] = 'VAS';
