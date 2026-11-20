<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . '/session.php';
startWalletSession();

function authRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function authInput(): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') authRespond(['error' => 'Send this request as application/json.'], 415);
    $raw = file_get_contents('php://input', false, null, 0, 20001);
    if (strlen($raw) > 20000) authRespond(['error' => 'The request is too large.'], 413);
    try {
        $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        authRespond(['error' => 'The request contains invalid JSON.'], 400);
    }
    if (!is_array($body)) authRespond(['error' => 'Send a JSON object.'], 400);
    return $body;
}

function authThrottle(PDO $pdo, string $ip): array
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS attempts, MAX(attempted_at) AS latest_attempt FROM login_attempts
         WHERE ip = ? AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE'
    );
    $statement->execute([$ip]);
    $row = $statement->fetch();
    if ((int)$row['attempts'] < 5 || !$row['latest_attempt']) return ['blocked' => false, 'seconds' => 0];
    return ['blocked' => true, 'seconds' => max(1, strtotime($row['latest_attempt'] . ' UTC') + 600 - time())];
}

function authRecordFailure(PDO $pdo, string $ip): void
{
    $statement = $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, UTC_TIMESTAMP())');
    $statement->execute([$ip]);
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
}

function authSuccess(array $account, int $status = 200): never
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['wallet_token'] = bin2hex(random_bytes(32));
    if ($account['role'] === 'admin') {
        $_SESSION['owner_id'] = 1;
        $_SESSION['owner_name'] = $account['name'];
    } else {
        $_SESSION['portal_user_id'] = (int)$account['id'];
        $_SESSION['portal_user_name'] = $account['name'];
        $_SESSION['portal_role'] = $account['role'];
        $_SESSION['portal_auth_version'] = (int)($account['auth_version'] ?? 1);
    }
    $token = $_SESSION['wallet_token'];
    $role = $account['role'];
    session_write_close();
    $home = match ($role) {
        'admin' => 'admin.php',
        'employee' => 'employee.php',
        default => 'student.php'
    };
    authRespond([
        'message' => 'Signed in.',
        'token' => $token,
        'user' => ['id' => (int)$account['id'], 'name' => $account['name'], 'email' => $account['email'], 'role' => $role],
        'home' => $home
    ], $status);
}

set_exception_handler(function (Throwable $error): void {
    error_log('Allo-Wallet auth API: ' . $error->getMessage());
    authRespond(['error' => 'The authentication request could not be completed.'], 500);
});

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    authRespond(['error' => 'This HTTP method is not supported here.'], 405);
}

try {
    require dirname(__DIR__) . '/config.php';
} catch (Throwable $error) {
    error_log('Allo-Wallet auth API: ' . $error->getMessage());
    authRespond(['error' => 'The database is unavailable. Start MySQL and check config.php.'], 503);
}

if ($method === 'GET') {
    $role = currentWalletRole();
    if ($role === null) authRespond(['error' => 'Sign in to use this endpoint.'], 401);
    $accountId = $role === 'admin' ? 1 : (int)($_SESSION['portal_user_id'] ?? 0);
    $email = '';
    if ($role === 'admin') {
        $statement = $pdo->query('SELECT name, email FROM wallet_owner WHERE id = 1');
    } else {
        $statement = $pdo->prepare("SELECT name, email, auth_version FROM portal_accounts WHERE id = ? AND status = 'active'");
        $statement->execute([$accountId]);
    }
    $account = $statement->fetch();
    if (!$account) authRespond(['error' => 'This account is disabled. Contact an administrator.'], 403);
    if ($role !== 'admin' && (int)$account['auth_version'] !== (int)($_SESSION['portal_auth_version'] ?? 0)) {
        authRespond(['error' => 'Your password changed. Sign in again to continue.'], 401);
    }
    authRespond([
        'user' => ['id' => $accountId, 'name' => $account['name'], 'email' => $account['email'], 'role' => $role],
        'token' => $_SESSION['wallet_token']
    ]);
}

$input = authInput();
$action = $method === 'DELETE' ? 'logout' : ($input['action'] ?? 'login');
if ($action === 'logout') {
    $token = $_SESSION['wallet_token'] ?? '';
    $provided = $_SERVER['HTTP_X_ALLO_TOKEN'] ?? '';
    if (!is_string($token) || !is_string($provided) || $token === '' || !hash_equals($token, $provided)) {
        authRespond(['error' => 'Your session token is missing or expired.'], 403);
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
    authRespond(['message' => 'Signed out.']);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = 'unknown';
$initialToken = $_SESSION['wallet_token'] ?? '';
if ($action === 'register') {
    $providedToken = $_SERVER['HTTP_X_ALLO_TOKEN'] ?? '';
    if (!is_string($providedToken) || !is_string($initialToken) || $initialToken === '' || !hash_equals($initialToken, $providedToken)) {
        authRespond(['error' => 'Refresh the portal page and try again.'], 403);
    }
}
$throttle = authThrottle($pdo, $ip);
if ($throttle['blocked']) {
    header('Retry-After: ' . $throttle['seconds']);
    authRespond(['error' => 'Too many sign-in attempts. Try again in a few minutes.'], 429);
}

if ($action === 'register') {
    $name = $input['name'] ?? null;
    $email = $input['email'] ?? null;
    $password = $input['password'] ?? null;
    $confirmation = $input['confirmation'] ?? null;
    if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) authRespond(['error' => 'Enter a valid name.'], 422);
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80) authRespond(['error' => 'Name is required and must be 80 characters or fewer.'], 422);
    if (!is_string($email)) authRespond(['error' => 'Enter a valid email address.'], 422);
    $email = strtolower(trim($email));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) authRespond(['error' => 'Enter a valid email address.'], 422);
    if (!is_string($password) || !mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")
        || mb_strlen($password) < 8 || mb_strlen($password) > 64 || strlen($password) > 72) {
        authRespond(['error' => 'Password must be 8 to 64 characters and no more than 72 UTF-8 bytes.'], 422);
    }
    if (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
        authRespond(['error' => 'Enter the same password again.'], 422);
    }
    $ownerEmail = $pdo->query('SELECT email FROM wallet_owner WHERE id = 1')->fetchColumn();
    if (is_string($ownerEmail) && strtolower($ownerEmail) === $email) {
        authRespond(['error' => 'That email address is already in use.'], 409);
    }
    $statement = $pdo->prepare('SELECT id FROM portal_accounts WHERE email = ?');
    $statement->execute([$email]);
    if ($statement->fetchColumn()) authRespond(['error' => 'That email address is already in use.'], 409);
    $statement = $pdo->prepare(
        "INSERT INTO portal_accounts (name, email, password_hash, role) VALUES (?, ?, ?, 'student')"
    );
    try {
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') authRespond(['error' => 'That email address is already in use.'], 409);
        throw $error;
    }
    authSuccess([
        'id' => (int)$pdo->lastInsertId(),
        'name' => $name,
        'email' => $email,
        'role' => 'student'
    ], 201);
}

if ($action !== 'login') authRespond(['error' => 'Choose login or register.'], 422);
$email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
$password = is_string($input['password'] ?? null) ? $input['password'] : '';
$account = null;
if ($email !== '' && strlen($email) <= 254) {
    $statement = $pdo->prepare('SELECT id, name, email, password_hash FROM wallet_owner WHERE id = 1 AND email = ?');
    $statement->execute([$email]);
    $owner = $statement->fetch();
    if ($owner && is_string($password) && password_verify($password, $owner['password_hash'])) {
        $account = $owner + ['role' => 'admin'];
    } else {
        $statement = $pdo->prepare("SELECT id, name, email, password_hash, role, auth_version FROM portal_accounts WHERE email = ? AND status = 'active'");
        $statement->execute([$email]);
        $portalAccount = $statement->fetch();
        if ($portalAccount && is_string($password) && password_verify($password, $portalAccount['password_hash'])) {
            $account = $portalAccount;
        }
    }
}
if (!$account) {
    authRecordFailure($pdo, $ip);
    authRespond(['error' => 'Email or password is incorrect. Please try again.'], 401);
}
$pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
authSuccess($account);
