<?php 

require_once '../session_init.php';
require_once '../get_config.php';

require_once __DIR__ . '/auth.php';

if (isset($_GET['error'])) {
    // Handle error - maybe log it and display an error message
    die('Authorization server returned an error: ' . htmlspecialchars($_GET['error']));
}

if (!isset($_GET['state']) || $_SESSION['oauth2state'] !== $_GET['state']) {
    unset($_SESSION['oauth2state']);
    exit('Invalid state');
}

if (isset($_GET['code'])) {
    $clientId = $config['openid']['clientId'];
    $client_secret = $config['openid']['client_secret'];
    $callback = $config['openid']['callback'];
    $grant_type = $config['openid']['grant_type'];

    $authCode = $_GET['code'];

    // Now, let's exchange the auth code for tokens
    $tokenUrl = $config['openid']['token_url'];

    $postFields = [
        'grant_type' => $grant_type,
        'client_id' => $clientId,
        'client_secret' => $client_secret,
        'redirect_uri' => $callback,
        'code' => $authCode
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));

    $response = curl_exec($ch);
    $responseData = json_decode($response, true);
    
    if (isset($responseData['access_token'])) {
        // Store the tokens securely! Maybe in your session or a secure cookie
        $_SESSION['tokens'] = $responseData;

        // now get the user information
        $userinfo_endpoint = $config['openid']['userinfo_endpoint']; 
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $userinfo_endpoint);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $_SESSION['tokens']['access_token']));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $userinfo = curl_exec($ch);
        $userinfoData = json_decode($userinfo, true);
        curl_close($ch);

        $_SESSION['user_data'] = $userinfoData;

        #$_SESSION['authorized'] = true;
        #/*
        if (authorize($userinfoData['preferred_username'])) {
            $_SESSION['authorized'] = true;
        } else {
            $_SESSION['authorized'] = false;
        }
        #*/
        // Redirect to your app's main page, or wherever you'd like
        header('Location: '.$config['openid']['header_url']);
        exit;
    } else {
        // Handle the error - maybe log and display an error message
        die('Failed fetching tokens from main authority');
    }
}

