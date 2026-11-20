<?php
require __DIR__ . '/session.php';
startWalletSession();
header('Cache-Control: no-store');

// Logging out changes the session, so require a POST and the form's token.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use the Log out button in your portal.');
}
$token = $_POST['token'] ?? '';
if (!is_string($token) || !hash_equals($_SESSION['wallet_token'], $token)) {
    http_response_code(403);
    exit('This page has expired. Reload the portal before logging out.');
}

$_SESSION = [];
$cookie = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 3600,
    'path' => $cookie['path'],
    'secure' => $cookie['secure'],
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_destroy();
header('Location: portal.php', true, 303);
exit;
