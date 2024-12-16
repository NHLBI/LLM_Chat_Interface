<?php

require_once 'get_config.php';
session_start();

$clientId = $config['openid']['clientId'];
$callback = $config['openid']['callback'];

$scope = 'openid profile';  // Asking for identity and profile information
$state = bin2hex(random_bytes(16));  // Generate a random state
$_SESSION['oauth2state'] = $state;  // Store state in session for later validation

$authorizationUrlBase = $config['openid']['authorization_url_base'];
$authorizationUrl = $authorizationUrlBase . '?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $callback,
    'response_type' => 'code',
    'scope' => $scope,
    'state' => $state
]);
header('Location: ' . $authorizationUrl);
exit;

