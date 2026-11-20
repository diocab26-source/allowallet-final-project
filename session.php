<?php
// Shared session settings for login, the wallet page, and the API.
function startWalletSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('ALLO_WALLET_SESSION');
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if (basename($scriptDirectory) === 'api') $scriptDirectory = dirname($scriptDirectory);
    $path = rtrim($scriptDirectory, '/') . '/';
    session_start([
        'cookie_path' => $path,
        'cookie_httponly' => true,
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true
    ]);
    if (!isset($_SESSION['wallet_token'])) {
        $_SESSION['wallet_token'] = bin2hex(random_bytes(32));
    }
}

function isSignedIn(): bool
{
    return isset($_SESSION['owner_id']) && $_SESSION['owner_id'] === 1;
}

function currentWalletRole(): ?string
{
    if (isSignedIn()) return 'admin';
    $role = $_SESSION['portal_role'] ?? null;
    return in_array($role, ['student', 'employee'], true)
        && isset($_SESSION['portal_user_id']) ? $role : null;
}

function currentWalletHome(): string
{
    return match (currentWalletRole()) {
        'admin' => 'admin.php',
        'employee' => 'employee.php',
        'student' => 'student.php',
        default => 'portal.php'
    };
}
