<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require dirname(__DIR__) . '/session.php';
startWalletSession();
$apiRole = currentWalletRole();
if ($apiRole === null) {
    apiRespond(['error' => 'Sign in to use this endpoint.'], 401);
}
$apiAccountId = $apiRole === 'admin' ? 1 : (int)($_SESSION['portal_user_id'] ?? 0);
$apiToken = $_SESSION['wallet_token'] ?? '';
session_write_close();

try {
    require dirname(__DIR__) . '/config.php';
} catch (Throwable $error) {
    error_log('Allo-Wallet REST API: ' . $error->getMessage());
    apiRespond(['error' => 'The database is unavailable. Start MySQL and check config.php.'], 503);
}
if ($apiRole !== 'admin') {
    $activeCheck = $pdo->prepare("SELECT auth_version FROM portal_accounts WHERE id = ? AND status = 'active'");
    $activeCheck->execute([$apiAccountId]);
    $storedVersion = $activeCheck->fetchColumn();
    if (!$storedVersion) apiRespond(['error' => 'This account is disabled. Contact an administrator.'], 403);
    if ((int)$storedVersion !== (int)($_SESSION['portal_auth_version'] ?? 0)) {
        apiRespond(['error' => 'Your password changed. Sign in again to continue.'], 401);
    }
}

function apiRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function apiRequireRole(array $roles): void
{
    global $apiRole;
    if (!in_array($apiRole, $roles, true)) {
        apiRespond(['error' => 'Your account cannot perform this action.'], 403);
    }
}

function apiRequireToken(): void
{
    global $apiToken;
    $provided = $_SERVER['HTTP_X_ALLO_TOKEN'] ?? '';
    if (!is_string($apiToken) || $apiToken === '' || !is_string($provided) || !hash_equals($apiToken, $provided)) {
        apiRespond(['error' => 'Your session token is missing or expired. Refresh the page and try again.'], 403);
    }
}

function apiReadJson(): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        apiRespond(['error' => 'Send this request as application/json.'], 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 100001);
    if (strlen($raw) > 100000) apiRespond(['error' => 'The request is too large.'], 413);
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        apiRespond(['error' => 'The request contains invalid JSON.'], 400);
    }
    if (!is_array($data)) apiRespond(['error' => 'Send a JSON object.'], 400);
    return $data;
}

function apiRequireMethod(array $allowed): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        apiRespond(['error' => 'This HTTP method is not supported here.'], 405);
    }
    return $method;
}

function apiPositiveInt($value, string $label): int
{
    if (!(is_int($value) || (is_string($value) && ctype_digit($value))) || (int)$value < 1) {
        apiRespond(['error' => "Choose a valid $label."], 422);
    }
    return (int)$value;
}

set_exception_handler(function (Throwable $error): void {
    error_log('Allo-Wallet REST API: ' . $error->getMessage());
    apiRespond(['error' => 'The request could not be completed. Check the server log.'], 500);
});
