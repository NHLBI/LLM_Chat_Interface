<?php

require_once __DIR__ . '/session_init.php';
require_once 'get_config.php'; // Determine the environment dynamically
#echo '<pre>'.print_r($config,1).'</pre>';

// lib.required.php
require_once 'db.php';

require_once 'inc/assistants.inc.php';
require_once 'inc/azure-api.inc.php';
require_once 'inc/images.inc.php';
require_once 'inc/login-session.inc.php';
require_once 'inc/mocha.inc.php';
require_once 'inc/RAG.inc.php';
require_once 'inc/system-message.inc.php';
require_once 'inc/utils.inc.php';
require_once 'inc/workflows.inc.php';

$pdo = get_connection();

define('DOC_GEN_DIR',dirname(__DIR__) . '/doc_gen');

// Before proceeding, check if the session has the required user data.
// If not, let’s give it a chance to appear.
if (!waitForUserSession()) {
    // If after waiting the session is still missing the user id, load the splash page.
    require_once 'splash.php';
    exit;
}

// Handle the splash screen
if (empty($_SESSION['splash'])) $_SESSION['splash'] = '';

if ((!empty($_SESSION['user_data']['userid']) && $_SESSION['authorized'] !== true) || empty($_SESSION['splash'])) {
    require_once 'splash.php';
    exit;
}

// Start the PHP session to enable session variables
$sessionTimeout = $config['session']['timeout'];  // Load session timeout from config

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $sessionTimeout)) {
    // Last request was more than timeout ago
    logout();
}
$_SESSION['LAST_ACTIVITY'] = time(); // Update last activity time stamp

// Initialize user data if not set
if (empty($_SESSION['user_data'])) $_SESSION['user_data'] = [];

$user = (empty($_SESSION['user_data']['userid'])) ? '' : $_SESSION['user_data']['userid'];

$application_path = $config['app']['application_path'];

// Confirm authentication, redirect if false
if (isAuthenticated()) {
    if (empty($_SESSION['LAST_REGEN']) || (time() - $_SESSION['LAST_REGEN'] > 900)) {
        session_regenerate_id(true);
        $_SESSION['LAST_REGEN'] = time();
    }

} else {
    header('Location: auth_redirect.php');
    exit;
}

// Verify that there is a chat with this id for this user
// If a 'chat_id' parameter was passed, store its value as a string in the session variable 'chat_id'
$chat_id = filter_input(INPUT_GET, 'chat_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (!verify_user_chat($user, $chat_id)) {
    echo " -- " . htmlspecialchars($user) . "<br>\n";
    die("Error: there is no chat record for the specified user and chat id. If you need assistance, please contact " . htmlspecialchars($config['app']['emailhelp']));
}

// Parse models from configuration
$models_str = $config['azure']['deployments'];
$models_a = explode(",", $models_str);

$models = array();
foreach ($models_a as $m) {
    $a = explode(":", $m);
    $models[$a[0]] = array('label' => $a[1]) + $config[$a[0]];
}
#echo "<pre>".print_r($models,1)."</pre>";die();

// Define temperature options
$temperatures = [];
$i = 0;
while ($i < 2.1) {
    $temperatures[] = round($i, 1);
    $i += 0.1;
}

// Initialize chat_id if not set
if (empty($_GET['chat_id'])) $_GET['chat_id'] = '';

// Handle model selection
if (isset($_POST['model']) && array_key_exists($_POST['model'], $models)) {
    #print_r($_POST);
    $deployment = $_SESSION['deployment'] = $_POST['model'];
    if (!empty($_GET['chat_id'])) update_deployment($user, $chat_id, $deployment);
}

// Retrieve all chats for the user
$all_chats = get_all_chats($user);
if (!empty($chat_id) && !empty($all_chats[$chat_id])) {
    // Active chat + deployment
    $deployment = $_SESSION['deployment'] = $all_chats[$chat_id]['deployment'];

    // Temperature
    $_SESSION['temperature'] = (isset($all_chats[$chat_id]['temperature']) && $all_chats[$chat_id]['temperature'] !== '')
        ? $all_chats[$chat_id]['temperature']
        : ($_SESSION['temperature'] ?? '0.7');

    // Reasoning effort + verbosity (with allow-lists + safe fallbacks)
    $allowed_effort = ['minimal','low','medium','high'];
    $allowed_verb   = ['low','medium','high'];

    $effort_db   = $all_chats[$chat_id]['reasoning_effort'] ?? null;
    $verbosity_db= $all_chats[$chat_id]['verbosity']        ?? null;

    $_SESSION['reasoning_effort'] = in_array($effort_db, $allowed_effort, true)
        ? $effort_db
        : ($_SESSION['reasoning_effort'] ?? 'medium');

    $_SESSION['verbosity'] = in_array($verbosity_db, $allowed_verb, true)
        ? $verbosity_db
        : ($_SESSION['verbosity'] ?? 'medium');

} else {
    // No active chat yet: ensure sane defaults in session so the UI has values
    $_SESSION['temperature']      = $_SESSION['temperature']      ?? '0.7';
    $_SESSION['reasoning_effort'] = $_SESSION['reasoning_effort'] ?? 'medium';
    $_SESSION['verbosity']        = $_SESSION['verbosity']        ?? 'medium';
}

// Set default deployment if not already set
if (empty($_SESSION['deployment'])) {
    $deployment = $_SESSION['deployment'] = $config['azure']['default'];
    #echo "4 This is the deployment: {$deployment}<br>\n";
} else {
    $deployment = $_SESSION['deployment'];
    #echo "5 This is the deployment: {$deployment}<br>\n";
}

$context_limit = $config[$deployment]['context_limit'];

#echo "1 This is the deployment: {$deployment}<br>\n";
#echo '<pre>'.print_r($config[$deployment],1).'</pre>';

// Handle temperature selection
if (isset($_POST['temperature'])) {
    $_SESSION['temperature'] = (float)$_POST['temperature'];
    $temperature = $_SESSION['temperature'];
    if (!empty($_GET['chat_id'])) update_temperature($user, $chat_id, $temperature);
}

// Set default temperature if not set or out of bounds
if (!isset($_SESSION['temperature']) || (float)$_SESSION['temperature'] < 0 || (float)$_SESSION['temperature'] > 2) {
    $_SESSION['temperature'] = 0.7;
}

// Handle reasoning_effort selection
if (isset($_POST['reasoning_effort'])) {
    // Accept only allowed values; default to 'medium'
    $allowed = ['minimal','low','medium','high'];
    $val = strtolower((string)$_POST['reasoning_effort']);
    $val = in_array($val, $allowed, true) ? $val : 'medium';

    $_SESSION['reasoning_effort'] = $val;
    if (!empty($_GET['chat_id'])) update_reasoning_effort($user, $chat_id, $val);
}

// Handle verbosity selection
if (isset($_POST['verbosity'])) {
    // Accept only allowed values; default to 'medium'
    $allowed = ['low','medium','high'];
    $val = strtolower((string)$_POST['verbosity']);
    $val = in_array($val, $allowed, true) ? $val : 'medium';

    $_SESSION['verbosity'] = $val;
    if (!empty($_GET['chat_id'])) update_verbosity($user, $chat_id, $val);
}

// Sensible defaults if not set yet
$_SESSION['reasoning_effort'] = $_SESSION['reasoning_effort'] ?? 'medium';
$_SESSION['verbosity']        = $_SESSION['verbosity']        ?? 'medium';

// Uncomment for debugging
// echo "<pre>". print_r($_SESSION,1) ."</pre>";
// echo "<pre>". print_r($_SERVER,1) ."</pre>";

