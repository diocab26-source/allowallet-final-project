<?php
require __DIR__ . '/common.php';

$method = apiRequireMethod(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
apiRequireRole(['admin', 'employee', 'student']);

function checkedLedgerDescription($value): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        apiRespond(['error' => 'Enter a valid description.'], 422);
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > 100) {
        apiRespond(['error' => 'Description is required and must be 100 characters or fewer.'], 422);
    }
    return $value;
}

function checkedLedgerAmount($value): int
{
    if (!(is_int($value) || (is_string($value) && ctype_digit($value))) || (int)$value < 1 || (int)$value > 100000000) {
        apiRespond(['error' => 'Enter an amount from 1 to 100,000,000 centavos.'], 422);
    }
    return (int)$value;
}

function studentLedgerBalance(PDO $pdo, int $studentId): int
{
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN entry_type = 'allowance' THEN amount ELSE -amount END), 0)
         FROM allowance_entries WHERE student_id = ?"
    );
    $statement->execute([$studentId]);
    return (int)$statement->fetchColumn();
}

function checkedStudent(PDO $pdo, int $studentId, bool $activeOnly = true): array
{
    $sql = "SELECT id, name FROM portal_accounts WHERE id = ? AND role = 'student'";
    if ($activeOnly) $sql .= " AND status = 'active'";
    $statement = $pdo->prepare($sql);
    $statement->execute([$studentId]);
    $student = $statement->fetch();
    if (!$student) apiRespond(['error' => $activeOnly ? 'Active student account not found.' : 'Student account not found.'], 404);
    return $student;
}

function ledgerEntryFields(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'studentId' => (int)$row['student_id'],
        'studentName' => $row['student_name'],
        'type' => $row['entry_type'],
        'amount' => (int)$row['amount'],
        'description' => $row['description'],
        'createdByRole' => $row['created_by_role'],
        'createdByAccountId' => $row['created_by_account_id'] === null ? null : (int)$row['created_by_account_id'],
        'createdByName' => $row['creator_name'] ?? ucfirst($row['created_by_role']),
        'createdAt' => $row['created_at']
    ];
}

function ledgerStudentFilter(PDO $pdo, string $role, int $accountId): int
{
    if ($role === 'student') return $accountId;
    if (!isset($_GET['student_id'])) apiRespond(['error' => 'Provide a student_id for this request.'], 422);
    return apiPositiveInt($_GET['student_id'], 'student ID');
}

function ledgerCanManage(array $entry, string $role, int $accountId): bool
{
    if ($role === 'admin') return true;
    if ($role === 'student') {
        return (int)$entry['student_id'] === $accountId && $entry['entry_type'] === 'expense';
    }
    return $entry['entry_type'] === 'allowance'
        && $entry['created_by_role'] === 'employee'
        && (int)$entry['created_by_account_id'] === $accountId;
}

global $pdo, $apiRole, $apiAccountId;

if ($method === 'GET') {
    $studentId = ledgerStudentFilter($pdo, $apiRole, $apiAccountId);
    if ($apiRole === 'employee') checkedStudent($pdo, $studentId);
    elseif ($apiRole === 'admin') checkedStudent($pdo, $studentId, false);
    $statement = $pdo->prepare(
        'SELECT e.*, s.name AS student_name, a.name AS creator_name
         FROM allowance_entries e
         JOIN portal_accounts s ON s.id = e.student_id
         LEFT JOIN portal_accounts a ON a.id = e.created_by_account_id
         WHERE e.student_id = ? ORDER BY e.created_at DESC, e.id DESC'
    );
    $statement->execute([$studentId]);
    $rows = $statement->fetchAll();
    $balance = 0;
    foreach ($rows as $row) $balance += $row['entry_type'] === 'allowance' ? (int)$row['amount'] : -(int)$row['amount'];
    apiRespond([
        'studentId' => $studentId,
        'balance' => $balance,
        'entries' => array_map('ledgerEntryFields', $rows)
    ]);
}

apiRequireToken();
$input = apiReadJson();

if ($method === 'POST') {
    if ($apiRole === 'student') {
        $studentId = $apiAccountId;
        $entryType = 'expense';
    } else {
        $entryType = $input['type'] ?? null;
        if ($entryType !== 'allowance') apiRespond(['error' => 'Employees and administrators can create allowance entries here.'], 403);
        $studentId = apiPositiveInt($input['student_id'] ?? null, 'student ID');
        checkedStudent($pdo, $studentId);
    }
    $amount = checkedLedgerAmount($input['amount'] ?? null);
    $description = checkedLedgerDescription($input['description'] ?? null);
    if ($apiRole === 'student' && $amount > studentLedgerBalance($pdo, $studentId)) {
        apiRespond(['error' => 'This expense is greater than your available allowance balance.'], 422);
    }
    $statement = $pdo->prepare(
        'INSERT INTO allowance_entries (student_id, entry_type, amount, description, created_by_role, created_by_account_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $statement->execute([
        $studentId,
        $entryType,
        $amount,
        $description,
        $apiRole,
        $apiRole === 'admin' ? null : $apiAccountId
    ]);
    apiRespond(['message' => 'Entry created.', 'id' => (int)$pdo->lastInsertId()], 201);
}

$inputId = $input['id'] ?? $_GET['id'] ?? null;
$entryId = apiPositiveInt($inputId, 'entry ID');
$statement = $pdo->prepare('SELECT * FROM allowance_entries WHERE id = ?');
$statement->execute([$entryId]);
$entry = $statement->fetch();
if (!$entry) apiRespond(['error' => 'Ledger entry not found.'], 404);
if (!ledgerCanManage($entry, $apiRole, $apiAccountId)) {
    apiRespond(['error' => 'Your account cannot change this ledger entry.'], 403);
}

if ($method === 'DELETE') {
    $statement = $pdo->prepare('DELETE FROM allowance_entries WHERE id = ?');
    $statement->execute([$entryId]);
    apiRespond(['message' => 'Entry deleted.', 'id' => $entryId]);
}

$amount = checkedLedgerAmount($input['amount'] ?? $entry['amount']);
$description = checkedLedgerDescription($input['description'] ?? $entry['description']);
if ($apiRole === 'student' && $entry['entry_type'] === 'expense'
    && $amount > studentLedgerBalance($pdo, (int)$entry['student_id']) + (int)$entry['amount']) {
    apiRespond(['error' => 'This expense is greater than your available allowance balance.'], 422);
}
$statement = $pdo->prepare('UPDATE allowance_entries SET amount = ?, description = ? WHERE id = ?');
$statement->execute([$amount, $description, $entryId]);
apiRespond(['message' => 'Entry updated.', 'id' => $entryId]);
