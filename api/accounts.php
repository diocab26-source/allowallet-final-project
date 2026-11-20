<?php
require __DIR__ . '/common.php';

$method = apiRequireMethod(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
apiRequireRole(['admin', 'employee']);

function accountFields(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'status' => $row['status'],
        'createdAt' => $row['created_at']
    ];
}

function checkedAccountName($value): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        apiRespond(['error' => 'Enter a valid name.'], 422);
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > 80) {
        apiRespond(['error' => 'Name is required and must be 80 characters or fewer.'], 422);
    }
    return $value;
}

function checkedAccountEmail($value): string
{
    if (!is_string($value)) apiRespond(['error' => 'Enter a valid email address.'], 422);
    $value = strtolower(trim($value));
    if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        apiRespond(['error' => 'Enter a valid email address.'], 422);
    }
    return $value;
}

function checkedAccountPassword($value): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
        apiRespond(['error' => 'Enter a valid password.'], 422);
    }
    if (mb_strlen($value) < 8 || mb_strlen($value) > 64 || strlen($value) > 72) {
        apiRespond(['error' => 'Password must be 8 to 64 characters and no more than 72 UTF-8 bytes.'], 422);
    }
    return $value;
}

function accountEmailInUse(PDO $pdo, string $email, ?int $excludeId = null): bool
{
    $owner = $pdo->query('SELECT email FROM wallet_owner WHERE id = 1')->fetchColumn();
    if (is_string($owner) && strtolower($owner) === $email) return true;
    $sql = 'SELECT id FROM portal_accounts WHERE email = ?';
    $params = [$email];
    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (bool)$statement->fetchColumn();
}

global $pdo, $apiRole;

if ($method === 'GET') {
    $id = isset($_GET['id']) ? apiPositiveInt($_GET['id'], 'account ID') : null;
    if ($apiRole === 'employee') {
        if ($id !== null) {
            $statement = $pdo->prepare("SELECT * FROM portal_accounts WHERE id = ? AND role = 'student' AND status = 'active'");
            $statement->execute([$id]);
            $row = $statement->fetch();
            if (!$row) apiRespond(['error' => 'Student account not found.'], 404);
            apiRespond(['account' => accountFields($row)]);
        }
        $rows = $pdo->query("SELECT * FROM portal_accounts WHERE role = 'student' AND status = 'active' ORDER BY name, id")->fetchAll();
    } elseif ($id !== null) {
        $statement = $pdo->prepare('SELECT * FROM portal_accounts WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row) apiRespond(['error' => 'Account not found.'], 404);
        apiRespond(['account' => accountFields($row)]);
    } else {
        $rows = $pdo->query('SELECT * FROM portal_accounts ORDER BY role, name, id')->fetchAll();
    }
    apiRespond(['accounts' => array_map('accountFields', $rows)]);
}

apiRequireRole(['admin']);
apiRequireToken();
$input = apiReadJson();

if ($method === 'POST') {
    $name = checkedAccountName($input['name'] ?? null);
    $email = checkedAccountEmail($input['email'] ?? null);
    $password = checkedAccountPassword($input['password'] ?? null);
    $role = $input['role'] ?? null;
    if (!in_array($role, ['student', 'employee'], true)) {
        apiRespond(['error' => 'Choose student or employee access.'], 422);
    }
    if (accountEmailInUse($pdo, $email)) apiRespond(['error' => 'That email address is already in use.'], 409);
    $statement = $pdo->prepare(
        'INSERT INTO portal_accounts (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
    );
    try {
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') apiRespond(['error' => 'That email address is already in use.'], 409);
        throw $error;
    }
    $id = (int)$pdo->lastInsertId();
    $statement = $pdo->prepare('SELECT * FROM portal_accounts WHERE id = ?');
    $statement->execute([$id]);
    apiRespond(['account' => accountFields($statement->fetch())], 201);
}

$id = apiPositiveInt($_GET['id'] ?? $input['id'] ?? null, 'account ID');
$statement = $pdo->prepare('SELECT * FROM portal_accounts WHERE id = ?');
$statement->execute([$id]);
$existing = $statement->fetch();
if (!$existing) apiRespond(['error' => 'Account not found.'], 404);

if ($method === 'DELETE') {
    $statement = $pdo->prepare("UPDATE portal_accounts SET status = 'disabled', auth_version = auth_version + 1 WHERE id = ?");
    $statement->execute([$id]);
    apiRespond(['message' => 'Account disabled.', 'id' => $id]);
}

$name = checkedAccountName($input['name'] ?? $existing['name']);
$email = checkedAccountEmail($input['email'] ?? $existing['email']);
$status = $input['status'] ?? $existing['status'];
if (!in_array($status, ['active', 'disabled'], true)) {
    apiRespond(['error' => 'Choose active or disabled status.'], 422);
}
if (accountEmailInUse($pdo, $email, $id)) apiRespond(['error' => 'That email address is already in use.'], 409);

if (array_key_exists('password', $input)) {
    $password = checkedAccountPassword($input['password']);
    $statement = $pdo->prepare('UPDATE portal_accounts SET name = ?, email = ?, status = ?, password_hash = ?, auth_version = auth_version + 1 WHERE id = ?');
    $statement->execute([$name, $email, $status, password_hash($password, PASSWORD_DEFAULT), $id]);
} else {
    if ($status !== $existing['status']) {
        $statement = $pdo->prepare('UPDATE portal_accounts SET name = ?, email = ?, status = ?, auth_version = auth_version + 1 WHERE id = ?');
        $statement->execute([$name, $email, $status, $id]);
    } else {
        $statement = $pdo->prepare('UPDATE portal_accounts SET name = ?, email = ?, status = ? WHERE id = ?');
        $statement->execute([$name, $email, $status, $id]);
    }
}
$statement = $pdo->prepare('SELECT * FROM portal_accounts WHERE id = ?');
$statement->execute([$id]);
apiRespond(['account' => accountFields($statement->fetch())]);
