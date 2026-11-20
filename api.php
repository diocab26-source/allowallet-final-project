<?php
// A small JSON API. JavaScript sends actions here; PHP updates MySQL.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('UTC');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function validAmount($amount): bool
{
    return is_int($amount) && $amount > 0 && $amount <= 100000000;
}

// Keep the field names with each error so JavaScript can highlight the right input.
class FormValidationException extends InvalidArgumentException
{
    public array $fields;

    public function __construct(array $fields)
    {
        $this->fields = $fields;
        parent::__construct((string) reset($fields));
    }
}

// Password changes use the same short IP cooldown as sign-in.
function getApiLoginThrottle(PDO $pdo, string $ip): array
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS attempts, MAX(attempted_at) AS latest_attempt
         FROM login_attempts
         WHERE ip = ? AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE'
    );
    $statement->execute([$ip]);
    $attempts = $statement->fetch();
    if ((int)$attempts['attempts'] < 5 || !$attempts['latest_attempt']) {
        return ['blocked' => false, 'seconds' => 0];
    }
    $latest = strtotime($attempts['latest_attempt'] . ' UTC');
    return ['blocked' => true, 'seconds' => max(1, $latest + 600 - time())];
}

function recordApiLoginFailure(PDO $pdo, string $ip): void
{
    $statement = $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, UTC_TIMESTAMP())');
    $statement->execute([$ip]);
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
}

function clearApiLoginFailures(PDO $pdo, string $ip): void
{
    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?');
    $statement->execute([$ip]);
}

function checkedPassword($value, string $field, int $minimum): string
{
    if (!is_string($value)) {
        $message = $minimum === 0 ? 'Enter your current password.' : 'Use 8–64 characters. If you use emoji, keep the password shorter.';
        throw new FormValidationException([$field => $message]);
    }
    if ($value === '' && $minimum === 0) {
        throw new FormValidationException([$field => 'Enter your current password.']);
    }
    if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
        throw new FormValidationException([$field => 'Remove the unsupported character from this password.']);
    }
    $length = mb_strlen($value);
    if ($length < $minimum || $length > 64 || strlen($value) > 72) {
        $rule = $minimum === 0 ? 'Use 64 characters or fewer.' : 'Use 8–64 characters. If you use emoji, keep the password shorter.';
        throw new FormValidationException([$field => $rule]);
    }
    return $value;
}

function checkedText($value, int $maximum, string $label): string
{
    if (is_string($value)) $value = preg_replace('/^\s+|\s+$/u', '', $value);
    if (!is_string($value) || $value === '' || mb_strlen($value) > $maximum) {
        throw new InvalidArgumentException("$label is required and must be at most $maximum characters.");
    }
    return trim($value);
}

function checkedOptionalText($value, int $maximum, string $field, string $label): string
{
    if ($value === null) return '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new FormValidationException([$field => "$label must be valid text."]);
    }
    $value = preg_replace('/^\s+|\s+$/u', '', $value) ?? '';
    if (mb_strlen($value) > $maximum) {
        throw new FormValidationException([$field => "$label must be $maximum characters or fewer."]);
    }
    return $value;
}

function checkedOptionalDate($value): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new FormValidationException(['date' => 'Choose a valid date.']);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $dateErrors = DateTimeImmutable::getLastErrors();
    $hasDateErrors = is_array($dateErrors)
        && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0);
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    if (!$date || $hasDateErrors || $date->format('Y-m-d') !== $value
        || $value < '2000-01-01' || $value > $today) {
        throw new FormValidationException(['date' => 'Choose a date from January 1, 2000 through today.']);
    }
    return $value;
}

function checkedNextAllowanceDate($value): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new FormValidationException(['next_allowance_date' => 'Choose a valid next allowance date.']);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $dateErrors = DateTimeImmutable::getLastErrors();
    $hasDateErrors = is_array($dateErrors)
        && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0);
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    if (!$date || $hasDateErrors || $date->format('Y-m-d') !== $value || $value < $today) {
        throw new FormValidationException(['next_allowance_date' => 'Choose today or a later date.']);
    }
    return $value;
}

function checkedRestoreDate($value): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new FormValidationException(['date' => 'The original entry date is invalid.']);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $dateErrors = DateTimeImmutable::getLastErrors();
    $hasDateErrors = is_array($dateErrors)
        && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0);
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    if (!$date || $hasDateErrors || $date->format('Y-m-d') !== $value
        || $value < '1000-01-01' || $value > $today) {
        throw new FormValidationException(['date' => 'The original entry date is invalid.']);
    }
    return $value;
}

function checkedGoalId($value): string
{
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1 || (int) $value > 4294967295) {
        throw new FormValidationException(['goal_id' => 'Choose a valid savings goal.']);
    }
    return $value;
}

function availableBalance(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COALESCE(SUM(CASE WHEN type IN ('income', 'withdrawal') THEN CAST(amount AS SIGNED) ELSE -CAST(amount AS SIGNED) END), 0) FROM transactions"
    )->fetchColumn();
}

function savedInGoal(PDO $pdo, string $goalId): int
{
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN type = 'saving' THEN CAST(amount AS SIGNED) ELSE -CAST(amount AS SIGNED) END), 0) FROM transactions WHERE goal_id = ? AND type IN ('saving', 'withdrawal')"
    );
    $statement->execute([$goalId]);
    return (int) $statement->fetchColumn();
}

// Copy the original settings goal once and attach earlier savings to it.
function prepareGoals(PDO $pdo, array $settings): void
{
    $statement = $pdo->prepare('SELECT id FROM goals WHERE id = 1');
    $statement->execute();
    if (!$statement->fetchColumn()) {
        $statement = $pdo->prepare(
            'INSERT INTO goals (id, name, target, created_at, is_active) VALUES (1, ?, ?, UTC_TIMESTAMP(3), 1)'
        );
        $statement->execute([$settings['goal_name'], $settings['goal_target']]);
    }
    $pdo->exec("UPDATE transactions SET goal_id = 1 WHERE type IN ('saving', 'withdrawal') AND goal_id IS NULL");
}

function checkTransaction(array $entry): array
{
    $errors = [];
    $type = $entry['type'] ?? null;
    $amount = $entry['amount'] ?? null;
    if (!in_array($type, ['income', 'expense', 'saving', 'withdrawal'], true)) {
        $errors['type'] = 'Choose allowance, expense, savings, or withdrawal.';
    }
    if (!validAmount($amount)) {
        $errors['amount'] = 'Enter an amount from PHP 0.01 to PHP 1,000,000.00, using whole centavos.';
    }
    $description = '';
    try {
        $description = checkedText($entry['description'] ?? null, 60, 'Description');
    } catch (InvalidArgumentException $error) {
        $errors['description'] = $error->getMessage();
    }
    $category = $entry['category'] ?? null;
    $categories = ['Food & drinks', 'Transport', 'Dorm & bills', 'School', 'Personal', 'Others'];
    if ($type === 'expense' && !in_array($category, $categories, true)) {
        $errors['category'] = 'Choose a valid expense category.';
    }
    if (($type === 'income' && $category !== 'Allowance')
        || (in_array($type, ['saving', 'withdrawal'], true) && $category !== 'Savings')) {
        $errors['category'] = 'The category does not match this transaction.';
    }
    if ($errors) throw new FormValidationException($errors);
    $goalId = in_array($type, ['saving', 'withdrawal'], true)
        ? checkedGoalId($entry['goal_id'] ?? null) : null;
    return [$type, $amount, $description, $category, $goalId];
}

// Read the current database state after each successful action.
function readWallet(PDO $pdo): array
{
    $settings = $pdo->query('SELECT * FROM wallet_settings WHERE id = 1')->fetch();
    if (!$settings) {
        throw new RuntimeException('Wallet settings are missing.');
    }
    $rows = $pdo->query('SELECT * FROM transactions ORDER BY DATE(created_at), id')->fetchAll();
    $goalRows = $pdo->query('SELECT id, name, target, is_active FROM goals ORDER BY id')->fetchAll();
    if (!$goalRows) {
        throw new RuntimeException('Savings goals are missing.');
    }
    $categories = ['Food & drinks', 'Transport', 'Dorm & bills', 'School', 'Personal', 'Others'];
    $budgets = array_fill_keys($categories, null);
    $budgetStatement = $pdo->prepare('SELECT category, monthly_limit FROM category_budgets');
    $budgetStatement->execute();
    $budgetRows = $budgetStatement->fetchAll();
    foreach ($budgetRows as $budgetRow) {
        if (!array_key_exists($budgetRow['category'], $budgets)) {
            throw new RuntimeException('A saved budget uses an unknown expense category.');
        }
        $budgets[$budgetRow['category']] = (int) $budgetRow['monthly_limit'];
    }
    $goalSavings = [];
    foreach ($goalRows as $goalRow) {
        $goalSavings[(string) $goalRow['id']] = 0;
    }
    $entries = [];
    foreach ($rows as $row) {
        $goalId = $row['goal_id'] === null ? null : (string) $row['goal_id'];
        if (in_array($row['type'], ['saving', 'withdrawal'], true)) {
            if ($goalId === null || !array_key_exists($goalId, $goalSavings)) {
                throw new RuntimeException('A savings entry points to a missing goal.');
            }
            $goalSavings[$goalId] += $row['type'] === 'saving' ? (int) $row['amount'] : -(int) $row['amount'];
        }
        $entries[] = [
            'id' => (string) $row['id'],
            'type' => $row['type'],
            'amount' => (int) $row['amount'],
            'description' => $row['description'],
            'category' => $row['category'],
            'goalId' => $goalId,
            'date' => str_replace(' ', 'T', $row['created_at']) . 'Z'
        ];
    }
    $goals = [];
    foreach ($goalRows as $goalRow) {
        $goals[] = [
            'id' => (string) $goalRow['id'],
            'name' => $goalRow['name'],
            'target' => (int) $goalRow['target'],
            'saved' => $goalSavings[(string) $goalRow['id']],
            'active' => (bool) $goalRow['is_active']
        ];
    }
    $canImport = !$settings['legacy_imported'] && count($entries) === 0
        && count($goals) === 1 && $goals[0]['id'] === '1' && $goals[0]['active']
        && $goals[0]['name'] === 'Emergency fund' && $goals[0]['target'] === 500000
        && $settings['goal_name'] === 'Emergency fund' && (int) $settings['goal_target'] === 500000;
    return [
        'transactions' => $entries,
        'goals' => $goals,
        'nextAllowanceDate' => $settings['next_allowance_date'],
        'budgets' => $budgets,
        'canImport' => $canImport
    ];
}

$pdo = null;
try {
    // The token stops other websites from submitting changes through your browser.
    require __DIR__ . '/session.php';
    startWalletSession();
    if (!isSignedIn()) respond(['error' => 'Please sign in to use your wallet.'], 401);
    $token = $_SESSION['wallet_token'];
    session_write_close();

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'GET' && $method !== 'POST') {
        header('Allow: GET, POST');
        respond(['error' => 'Use GET to read the wallet or POST to update it.'], 405);
    }
    $input = null;
    if ($method === 'POST') {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            respond(['error' => 'Send the request as JSON.'], 415);
        }
        if (!hash_equals($token, $_SERVER['HTTP_X_WALLET_TOKEN'] ?? '')) {
            respond(['error' => 'Your page session expired. Refresh the page and try again.'], 403);
        }
        // Limit imports to a small, predictable request size.
        $body = file_get_contents('php://input', false, null, 0, 3000001);
        if (strlen($body) > 3000000) {
            respond(['error' => 'This import is too large. The maximum request size is 3 MB.'], 413);
        }
        $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['action'] ?? null)) {
            throw new InvalidArgumentException('Choose a valid wallet action.');
        }
    }

    require __DIR__ . '/config.php';
    $pdo->beginTransaction();
    // Lock the settings row while creating goals or moving wallet money.
    $settings = $pdo->query('SELECT * FROM wallet_settings WHERE id = 1 FOR UPDATE')->fetch();
    if (!$settings) {
        throw new RuntimeException('Wallet settings are missing.');
    }
    prepareGoals($pdo, $settings);
    $preferredGoalId = null;
    $rotateSession = false;

    if ($method === 'POST') {
        switch ($input['action']) {
            case 'change_password':
                $currentPassword = checkedPassword($input['current_password'] ?? null, 'current_password', 0);
                $newPassword = checkedPassword($input['new_password'] ?? null, 'new_password', 8);
                $confirmation = $input['confirmation'] ?? null;
                if (!is_string($confirmation) || !hash_equals($newPassword, $confirmation)) {
                    throw new FormValidationException(['confirmation' => 'Enter the same password again.']);
                }
                if (!mb_check_encoding($confirmation, 'UTF-8') || strlen($confirmation) > 72 || str_contains($confirmation, "\0")) {
                    throw new FormValidationException(['confirmation' => 'Enter the same valid password again.']);
                }

                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = 'unknown';
                $throttle = getApiLoginThrottle($pdo, $ip);
                if ($throttle['blocked']) {
                    $pdo->rollBack();
                    $minutes = max(1, (int)ceil($throttle['seconds'] / 60));
                    header('Retry-After: ' . $throttle['seconds']);
                    respond(['error' => 'There have been too many password attempts. Please try again in about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.'], 429);
                }

                $ownerStatement = $pdo->query('SELECT password_hash FROM wallet_owner WHERE id = 1 FOR UPDATE');
                $owner = $ownerStatement->fetch();
                if (!$owner || !password_verify($currentPassword, $owner['password_hash'])) {
                    recordApiLoginFailure($pdo, $ip);
                    $pdo->commit();
                    respond([
                        'error' => 'Current password is incorrect. Please try again.',
                        'fields' => ['current_password' => 'Current password is incorrect. Please try again.']
                    ], 422);
                }

                $passwordStatement = $pdo->prepare('UPDATE wallet_owner SET password_hash = ? WHERE id = 1');
                $passwordStatement->execute([password_hash($newPassword, PASSWORD_DEFAULT)]);
                clearApiLoginFailures($pdo, $ip);
                $rotateSession = true;
                break;

            case 'add_transaction':
                $entry = $input;
                if (($entry['type'] ?? null) === 'income') $entry['description'] = 'Allowance received';
                if (($entry['type'] ?? null) === 'saving') $entry['description'] = 'Savings contribution';
                if (($entry['type'] ?? null) === 'withdrawal') $entry['description'] = 'Withdrawal';
                [$type, $amount, $description, $category, $goalId] = checkTransaction($entry);
                $date = checkedOptionalDate($input['date'] ?? null);
                if ($type === 'income') {
                    $source = checkedOptionalText($input['source'] ?? null, 40, 'source', 'Source');
                    $description = $source === '' ? 'Allowance received' : 'Allowance · ' . $source;
                }
                if (in_array($type, ['saving', 'withdrawal'], true)) {
                    $goalStatement = $pdo->prepare('SELECT name, is_active FROM goals WHERE id = ? FOR UPDATE');
                    $goalStatement->execute([$goalId]);
                    $goal = $goalStatement->fetch();
                    if (!$goal) {
                        throw new FormValidationException(['goal_id' => 'Choose a savings goal that still exists.']);
                    }
                    if ($type === 'saving' && !(bool) $goal['is_active']) {
                        throw new FormValidationException(['goal_id' => 'Choose an active goal before adding savings.']);
                    }
                }
                if ($type === 'saving') {
                    $balance = availableBalance($pdo);
                    if ($amount > $balance) {
                        throw new FormValidationException(['amount' => 'You need enough available balance to set this amount aside.']);
                    }
                    $description = mb_substr('Saved for ' . $goal['name'], 0, 60, 'UTF-8');
                } elseif ($type === 'withdrawal') {
                    if ($amount > savedInGoal($pdo, $goalId)) {
                        throw new FormValidationException(['amount' => 'You cannot withdraw more than this goal has saved.']);
                    }
                    $description = mb_substr('Withdrawal from ' . $goal['name'], 0, 60, 'UTF-8');
                }
                if ($date === null) {
                    $statement = $pdo->prepare(
                        'INSERT INTO transactions (type, amount, description, category, goal_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(3))'
                    );
                    $statement->execute([$type, $amount, $description, $category, $goalId]);
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO transactions (type, amount, description, category, goal_id, created_at) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $statement->execute([$type, $amount, $description, $category, $goalId, $date . ' 12:00:00.000']);
                }
                break;

            case 'delete_transaction':
                $id = $input['id'] ?? null;
                if (!is_string($id) || !ctype_digit($id) || (int) $id < 1 || (int) $id > 4294967295) {
                    throw new InvalidArgumentException('Choose a valid transaction to remove.');
                }
                $statement = $pdo->prepare('SELECT type, amount, goal_id FROM transactions WHERE id = ? FOR UPDATE');
                $statement->execute([$id]);
                $existing = $statement->fetch();
                if (!$existing) {
                    throw new InvalidArgumentException('This entry has already been removed. Refresh your wallet.');
                }
                if ($existing['type'] === 'saving'
                    && savedInGoal($pdo, (string) $existing['goal_id']) - (int) $existing['amount'] < 0) {
                    throw new FormValidationException(['amount' => 'This savings has already been used by a withdrawal, so it cannot be removed.']);
                }
                $statement = $pdo->prepare('DELETE FROM transactions WHERE id = ?');
                $statement->execute([$id]);
                if ($statement->rowCount() === 0) {
                    throw new InvalidArgumentException('This entry has already been removed. Refresh your wallet.');
                }
                break;

            case 'update_transaction':
                $id = $input['id'] ?? null;
                if (!is_string($id) || !ctype_digit($id) || (int) $id < 1 || (int) $id > 4294967295) {
                    throw new InvalidArgumentException('Choose a valid transaction to edit.');
                }
                $statement = $pdo->prepare('SELECT * FROM transactions WHERE id = ? FOR UPDATE');
                $statement->execute([$id]);
                $existing = $statement->fetch();
                if (!$existing) throw new InvalidArgumentException('This entry is no longer available. Refresh your wallet.');
                if (in_array($existing['type'], ['saving', 'withdrawal'], true)) {
                    throw new InvalidArgumentException('Savings and withdrawal entries can be removed, but not edited.');
                }
                $entry = $input;
                $entry['type'] = $existing['type'];
                [$type, $amount, $description, $category] = checkTransaction($entry);
                $date = checkedOptionalDate($input['date'] ?? null);

                $balance = availableBalance($pdo);
                $oldAmount = (int) $existing['amount'];
                $newBalance = $type === 'income'
                    ? $balance + $amount - $oldAmount
                    : $balance + $oldAmount - $amount;
                if ($newBalance < 0 && $newBalance < $balance) {
                    throw new FormValidationException(['amount' => 'This edit would use money already set aside for savings. Reduce the amount or add allowance first.']);
                }

                if ($date === null) {
                    $statement = $pdo->prepare(
                        'UPDATE transactions SET amount = ?, description = ?, category = ?, created_at = UTC_TIMESTAMP(3) WHERE id = ?'
                    );
                    $statement->execute([$amount, $description, $category, $id]);
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE transactions SET amount = ?, description = ?, category = ?, created_at = ? WHERE id = ?'
                    );
                    $statement->execute([$amount, $description, $category, $date . ' 12:00:00.000', $id]);
                }
                break;

            case 'restore_transaction':
                $entry = $input['transaction'] ?? null;
                if (!is_array($entry)) throw new InvalidArgumentException('The entry to restore is missing.');
                [$type, $amount, $description, $category, $goalId] = checkTransaction($entry);
                $date = checkedRestoreDate($entry['date'] ?? null);
                if ($goalId !== null) {
                    $goalStatement = $pdo->prepare('SELECT id FROM goals WHERE id = ? FOR UPDATE');
                    $goalStatement->execute([$goalId]);
                    if (!$goalStatement->fetchColumn()) {
                        throw new FormValidationException(['goal_id' => 'Choose a savings goal that still exists.']);
                    }
                }
                if ($type === 'saving') {
                    $balance = availableBalance($pdo);
                    if ($amount > $balance) {
                        throw new FormValidationException(['amount' => 'There is not enough available balance to restore this savings entry.']);
                    }
                } elseif ($type === 'withdrawal' && $amount > savedInGoal($pdo, $goalId)) {
                    throw new FormValidationException(['amount' => 'This goal no longer has enough saved to restore the withdrawal.']);
                }
                $statement = $pdo->prepare(
                    'INSERT INTO transactions (type, amount, description, category, goal_id, created_at) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([$type, $amount, $description, $category, $goalId, $date . ' 12:00:00.000']);
                break;

            case 'save_goal':
                $goalId = checkedGoalId($input['goal_id'] ?? null);
                $errors = [];
                $name = '';
                try {
                    $name = checkedText($input['name'] ?? null, 50, 'Goal name');
                } catch (InvalidArgumentException $error) {
                    $errors['name'] = $error->getMessage();
                }
                if (!validAmount($input['target'] ?? null)) {
                    $errors['target'] = 'Enter a positive goal target, up to PHP 1,000,000.00.';
                }
                if ($errors) throw new FormValidationException($errors);
                $statement = $pdo->prepare('UPDATE goals SET name = ?, target = ? WHERE id = ? AND is_active = 1');
                $statement->execute([$name, $input['target'], $goalId]);
                if ($statement->rowCount() === 0) {
                    $statement = $pdo->prepare('SELECT id FROM goals WHERE id = ? AND is_active = 1');
                    $statement->execute([$goalId]);
                    if (!$statement->fetchColumn()) {
                        throw new FormValidationException(['goal_id' => 'Choose an active goal to rename or retarget.']);
                    }
                }
                break;

            case 'create_goal':
                $errors = [];
                $name = '';
                try {
                    $name = checkedText($input['name'] ?? null, 50, 'Goal name');
                } catch (InvalidArgumentException $error) {
                    $errors['name'] = $error->getMessage();
                }
                if (!validAmount($input['target'] ?? null)) {
                    $errors['target'] = 'Enter a positive goal target, up to PHP 1,000,000.00.';
                }
                if ($errors) throw new FormValidationException($errors);
                $statement = $pdo->prepare('INSERT INTO goals (name, target, created_at, is_active) VALUES (?, ?, UTC_TIMESTAMP(3), 1)');
                $statement->execute([$name, $input['target']]);
                $preferredGoalId = (string) $pdo->lastInsertId();
                break;

            case 'archive_goal':
                $goalId = checkedGoalId($input['goal_id'] ?? null);
                $goalStatement = $pdo->prepare('SELECT id, is_active FROM goals WHERE id = ? FOR UPDATE');
                $goalStatement->execute([$goalId]);
                $goal = $goalStatement->fetch();
                if (!$goal || !(bool) $goal['is_active']) {
                    throw new FormValidationException(['goal_id' => 'Choose an active goal to archive.']);
                }
                $activeCount = (int) $pdo->query('SELECT COUNT(*) FROM goals WHERE is_active = 1')->fetchColumn();
                if ($activeCount <= 1) {
                    throw new FormValidationException(['goal_id' => 'Keep at least one active savings goal.']);
                }
                $statement = $pdo->prepare('UPDATE goals SET is_active = 0 WHERE id = ?');
                $statement->execute([$goalId]);
                break;

            case 'save_settings':
                $nextAllowanceDate = checkedNextAllowanceDate($input['next_allowance_date'] ?? null);
                $statement = $pdo->prepare('UPDATE wallet_settings SET next_allowance_date = ? WHERE id = 1');
                $statement->execute([$nextAllowanceDate]);
                break;

            case 'save_budgets':
                $categories = ['Food & drinks', 'Transport', 'Dorm & bills', 'School', 'Personal', 'Others'];
                $fieldNames = [
                    'Food & drinks' => 'budget_food_drinks',
                    'Transport' => 'budget_transport',
                    'Dorm & bills' => 'budget_dorm_bills',
                    'School' => 'budget_school',
                    'Personal' => 'budget_personal',
                    'Others' => 'budget_others'
                ];
                $budgetValues = $input['budgets'] ?? null;
                if (!is_array($budgetValues)) {
                    throw new FormValidationException(['budgets' => 'Enter a limit for each category or leave it blank.']);
                }
                $errors = [];
                foreach ($categories as $category) {
                    $field = $fieldNames[$category];
                    if (!array_key_exists($category, $budgetValues)) {
                        $errors[$field] = 'Leave this limit blank or enter a positive amount.';
                    } elseif ($budgetValues[$category] !== null && !validAmount($budgetValues[$category])) {
                        $errors[$field] = 'Enter a positive monthly limit up to PHP 1,000,000.00, or leave it blank.';
                    }
                }
                foreach (array_keys($budgetValues) as $category) {
                    if (!is_string($category) || !in_array($category, $categories, true)) {
                        $errors['budgets'] = 'Choose one of the listed expense categories.';
                    }
                }
                if ($errors) throw new FormValidationException($errors);

                $pdo->prepare('DELETE FROM category_budgets')->execute();
                $statement = $pdo->prepare('INSERT INTO category_budgets (category, monthly_limit) VALUES (?, ?)');
                foreach ($categories as $category) {
                    if ($budgetValues[$category] !== null) {
                        $statement->execute([$category, $budgetValues[$category]]);
                    }
                }
                break;

            case 'import_wallet':
                // Import only into a fresh database wallet. Never replace existing records.
                if (!readWallet($pdo)['canImport']) {
                    throw new InvalidArgumentException('Import is available only for an empty, unchanged database wallet.');
                }
                $wallet = $input['wallet'] ?? null;
                if (!is_array($wallet) || !is_array($wallet['transactions'] ?? null)
                    || !array_is_list($wallet['transactions']) || count($wallet['transactions']) > 10000) {
                    throw new InvalidArgumentException('The browser wallet is invalid or has more than 10,000 entries.');
                }
                $name = checkedText($wallet['goalName'] ?? null, 50, 'Goal name');
                if (!validAmount($wallet['goalTarget'] ?? null)) {
                    throw new InvalidArgumentException('The browser wallet has an invalid savings target.');
                }
                $statement = $pdo->prepare(
                    'INSERT INTO transactions (type, amount, description, category, goal_id, created_at) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $ids = [];
                foreach ($wallet['transactions'] as $entry) {
                    if (!is_array($entry)) {
                        throw new InvalidArgumentException('The browser wallet contains an invalid entry.');
                    }
                    $oldId = checkedText($entry['id'] ?? null, 100, 'Entry ID');
                    if (isset($ids[$oldId])) {
                        throw new InvalidArgumentException('The browser wallet contains duplicate entries.');
                    }
                    $ids[$oldId] = true;
                    if (($entry['type'] ?? null) === 'saving') $entry['goal_id'] = '1';
                    [$type, $amount, $description, $category, $goalId] = checkTransaction($entry);
                    $dateText = $entry['date'] ?? null;
                    $date = is_string($dateText)
                        ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $dateText) : false;
                    if (!$date || $date->format('Y-m-d\TH:i:s.v\Z') !== $dateText
                        || $date->format('Y') < '1000') {
                        throw new InvalidArgumentException('The browser wallet contains an invalid date.');
                    }
                    $statement->execute([$type, $amount, $description, $category, $goalId, $date->format('Y-m-d H:i:s.v')]);
                }
                $statement = $pdo->prepare('UPDATE goals SET name = ?, target = ? WHERE id = 1');
                $statement->execute([$name, $wallet['goalTarget']]);
                $statement = $pdo->prepare(
                    'UPDATE wallet_settings SET goal_name = ?, goal_target = ?, legacy_imported = 1 WHERE id = 1'
                );
                $statement->execute([$name, $wallet['goalTarget']]);
                $preferredGoalId = '1';
                break;

            default:
                throw new InvalidArgumentException('Unknown wallet action.');
        }
    }

    $wallet = readWallet($pdo);
    if ($preferredGoalId !== null) $wallet['selectedGoalId'] = $preferredGoalId;
    $pdo->commit();
    if ($rotateSession) {
        startWalletSession();
        session_regenerate_id(true);
        $_SESSION['wallet_token'] = bin2hex(random_bytes(32));
        $token = $_SESSION['wallet_token'];
        session_write_close();
    }
    respond(['wallet' => $wallet, 'token' => $token]);
} catch (FormValidationException $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => $error->getMessage(), 'fields' => $error->fields], 422);
} catch (InvalidArgumentException | JsonException $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => $error instanceof JsonException ? 'The request contains invalid JSON.' : $error->getMessage()], 422);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Allo-Wallet: ' . $error->getMessage());
    respond(['error' => 'Cannot reach the wallet database. Start MySQL in XAMPP, import database/allo_wallet.sql, and check config.php.'], 503);
}
