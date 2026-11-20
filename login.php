<?php
// First visit: create the one owner account. Later visits: sign in or reset its password.
require __DIR__ . '/session.php';
startWalletSession();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (currentWalletRole() !== null) {
    header('Location: ' . currentWalletHome());
    exit;
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function getLoginThrottle(PDO $pdo, string $ip): array
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
    $seconds = max(1, $latest + 600 - time());
    return ['blocked' => true, 'seconds' => $seconds];
}

function recordLoginFailure(PDO $pdo, string $ip): void
{
    $statement = $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, UTC_TIMESTAMP())');
    $statement->execute([$ip]);
    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    $statement->execute();
}

function clearLoginFailures(PDO $pdo, string $ip): void
{
    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?');
    $statement->execute([$ip]);
}

$recoveryQuestions = [
    'What was your favorite school subject?',
    'What was the name of your first teacher?',
    'What was the name of your first pet?',
    'Which school did you attend first?',
    'What is your favorite merienda?'
];
$errors = [];
$setup = false;
$databaseAvailable = true;
$owner = null;
$name = '';
$email = '';
$securityQuestion = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = 'unknown';
$localRequest = in_array($ip, ['127.0.0.1', '::1'], true);

try {
    require __DIR__ . '/config.php';
    $owner = $pdo->query('SELECT id, name, email, password_hash, security_question, security_answer_hash FROM wallet_owner WHERE id = 1')->fetch() ?: null;
    $setup = !$owner;
    $mode = $setup ? 'setup' : (($_GET['mode'] ?? '') === 'reset' ? 'reset' : 'login');
    $legacyRecovery = !$setup && $mode === 'reset'
        && (empty($owner['security_question']) || empty($owner['security_answer_hash']));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($owner && in_array($action, ['login', 'reset'], true)) {
            $mode = $action;
        } elseif (!$owner && $action === 'setup') {
            $mode = 'setup';
        }
        $legacyRecovery = !$setup && $mode === 'reset'
            && (empty($owner['security_question']) || empty($owner['security_answer_hash']));

        $token = $_POST['token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['wallet_token'], $token)) {
            $errors['form'] = 'This page has expired. Please submit the form again.';
            http_response_code(403);
        } elseif (($owner && !in_array($action, ['login', 'reset'], true)) || (!$owner && $action !== 'setup')) {
            $errors['form'] = 'The wallet account changed. Please refresh this page and try again.';
            http_response_code(409);
        } elseif ($mode === 'reset' && $legacyRecovery && !$localRequest) {
            $errors['form'] = 'This older account must set up password recovery from the XAMPP computer.';
            http_response_code(403);
        } else {
            $throttle = $mode === 'setup' ? ['blocked' => false, 'seconds' => 0] : getLoginThrottle($pdo, $ip);
            if ($throttle['blocked']) {
                $minutes = max(1, (int)ceil($throttle['seconds'] / 60));
                $errors['form'] = 'There have been too many sign-in or recovery attempts. Please try again in about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
                http_response_code(429);
                header('Retry-After: ' . $throttle['seconds']);
            } else {
                $name = is_string($_POST['name'] ?? null) && mb_check_encoding($_POST['name'], 'UTF-8')
                    ? preg_replace('/^\s+|\s+$/u', '', $_POST['name']) : '';
                $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $confirmation = is_string($_POST['confirmation'] ?? null) ? $_POST['confirmation'] : '';
                $securityQuestion = is_string($_POST['security_question'] ?? null) ? $_POST['security_question'] : '';
                $securityAnswer = is_string($_POST['security_answer'] ?? null) ? $_POST['security_answer'] : '';
                $resetAnswer = is_string($_POST['reset_answer'] ?? null) ? $_POST['reset_answer'] : '';
                if (mb_check_encoding($securityAnswer, 'UTF-8')) {
                    $securityAnswer = preg_replace('/^\s+|\s+$/u', '', $securityAnswer) ?? '';
                }
                if (mb_check_encoding($resetAnswer, 'UTF-8')) {
                    $resetAnswer = preg_replace('/^\s+|\s+$/u', '', $resetAnswer) ?? '';
                }
                $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
                $newConfirmation = is_string($_POST['new_confirmation'] ?? null) ? $_POST['new_confirmation'] : '';

                if ($mode === 'setup' && ($name === '' || mb_strlen($name) > 50)) {
                    $errors['name'] = 'Enter your name, using 50 characters or fewer.';
                }
                if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Enter a valid email address, such as student@example.com.';
                }

                if ($mode === 'setup' || $legacyRecovery) {
                    if (!in_array($securityQuestion, $recoveryQuestions, true)) {
                        $errors['security_question'] = 'Choose a recovery question.';
                    }
                    if (!mb_check_encoding($securityAnswer, 'UTF-8') || $securityAnswer === '' || mb_strlen($securityAnswer) > 100 || strlen($securityAnswer) > 72) {
                        $errors['security_answer'] = 'Enter an answer using up to 100 characters and 72 UTF-8 bytes.';
                    }
                }

                if ($mode === 'setup') {
                    if (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")) {
                        $errors['password'] = 'Remove the unsupported character from your password.';
                    } elseif (mb_strlen($password) < 8 || mb_strlen($password) > 64 || strlen($password) > 72) {
                        $errors['password'] = 'Use 8–64 characters. If you use emoji, keep the password shorter.';
                    }
                    if ($confirmation === '' || $confirmation !== $password) {
                        $errors['confirmation'] = 'Enter the same password again.';
                    }
                } elseif ($mode === 'login') {
                    if ($password === '') {
                        $errors['password'] = 'Enter your password.';
                    } elseif (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")) {
                        $errors['password'] = 'Remove the unsupported character from your password.';
                    } elseif (mb_strlen($password) > 64 || strlen($password) > 72) {
                        $errors['password'] = 'Use a password of 64 characters or fewer.';
                    }
                } else {
                    if (!$legacyRecovery && (!mb_check_encoding($resetAnswer, 'UTF-8') || $resetAnswer === '' || mb_strlen($resetAnswer) > 100 || strlen($resetAnswer) > 72)) {
                        $errors['reset_answer'] = 'Enter your answer using up to 100 characters and 72 UTF-8 bytes.';
                    }
                    if (!mb_check_encoding($newPassword, 'UTF-8') || str_contains($newPassword, "\0")) {
                        $errors['new_password'] = 'Remove the unsupported character from your password.';
                    } elseif (mb_strlen($newPassword) < 8 || mb_strlen($newPassword) > 64 || strlen($newPassword) > 72) {
                        $errors['new_password'] = 'Use 8–64 characters. If you use emoji, keep the password shorter.';
                    }
                    if ($newConfirmation === '' || $newConfirmation !== $newPassword) {
                        $errors['new_confirmation'] = 'Enter the same password again.';
                    }
                }

                if (!$errors) {
                    if ($mode === 'setup') {
                        $statement = $pdo->prepare(
                            'INSERT INTO wallet_owner (id, name, email, password_hash, security_question, security_answer_hash)
                             VALUES (1, ?, ?, ?, ?, ?)'
                        );
                        $statement->execute([
                            $name,
                            $email,
                            password_hash($password, PASSWORD_DEFAULT),
                            $securityQuestion,
                            password_hash(mb_strtolower($securityAnswer, 'UTF-8'), PASSWORD_DEFAULT)
                        ]);
                        $owner = ['name' => $name, 'email' => $email];
                    } elseif ($mode === 'login') {
                        $passwordMatches = password_verify($password, $owner['password_hash']);
                        if ($email !== $owner['email'] || !$passwordMatches) {
                            recordLoginFailure($pdo, $ip);
                            $errors['form'] = 'Email or password is incorrect. Please try again.';
                        }
                    } elseif ($legacyRecovery) {
                        if ($email !== strtolower($owner['email'])) {
                            recordLoginFailure($pdo, $ip);
                            $errors['form'] = 'The email address is incorrect. Please try again.';
                        } else {
                            $statement = $pdo->prepare(
                                'UPDATE wallet_owner SET password_hash = ?, security_question = ?, security_answer_hash = ? WHERE id = 1'
                            );
                            $answerHash = password_hash(mb_strtolower($securityAnswer, 'UTF-8'), PASSWORD_DEFAULT);
                            $statement->execute([
                                password_hash($newPassword, PASSWORD_DEFAULT),
                                $securityQuestion,
                                $answerHash
                            ]);
                            $owner['security_question'] = $securityQuestion;
                            $owner['security_answer_hash'] = $answerHash;
                        }
                    } else {
                        $answerMatches = password_verify(mb_strtolower($resetAnswer, 'UTF-8'), $owner['security_answer_hash']);
                        if ($email !== $owner['email'] || !$answerMatches) {
                            recordLoginFailure($pdo, $ip);
                            $errors['form'] = 'Email or recovery answer is incorrect. Please try again.';
                        } else {
                            $statement = $pdo->prepare('UPDATE wallet_owner SET password_hash = ? WHERE id = 1');
                            $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT)]);
                        }
                    }

                    if (!$errors) {
                        if ($mode !== 'setup') clearLoginFailures($pdo, $ip);
                        // A fresh session ID prevents reusing the pre-login session.
                        session_regenerate_id(true);
                        $_SESSION['owner_id'] = 1;
                        $_SESSION['owner_name'] = $owner['name'];
                        $_SESSION['wallet_token'] = bin2hex(random_bytes(32));
                        header('Location: admin.php', true, 303);
                        exit;
                    }
                }

                if ($errors && http_response_code() < 400) http_response_code(422);
            }
        }
    }
} catch (Throwable $error) {
    error_log('Allo-Wallet login: ' . $error->getMessage());
    if ($error instanceof PDOException && $error->getCode() === '23000') {
        $setup = false;
        $mode = 'login';
        $errors['form'] = 'The owner account has already been created. Please sign in.';
        http_response_code(409);
    } else {
        $databaseAvailable = false;
        $errors['form'] = 'Cannot connect to the wallet. Start MySQL and import the latest database/allo_wallet.sql in phpMyAdmin.';
        http_response_code(503);
    }
}

if (!isset($mode)) $mode = $setup ? 'setup' : 'login';
if ($setup && $mode === 'reset') $mode = 'setup';
$legacyRecovery = !$setup && $mode === 'reset'
    && (empty($owner['security_question']) || empty($owner['security_answer_hash']));
$manualReset = $legacyRecovery && !$localRequest;
$titles = [
    'setup' => 'A fresh start for your wallet.',
    'login' => 'Welcome back.',
    'reset' => 'Let’s get you back in.'
];
$title = $titles[$mode] ?? $titles['login'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#173d32">
  <title><?= $mode === 'setup' ? 'Create your account' : ($mode === 'reset' ? 'Reset your password' : 'Sign in') ?> · Allo-Wallet</title>
  <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
  <script>
    (function () {
      let choice = "system";
      try {
        const saved = localStorage.getItem("allo-wallet-theme");
        if (["light", "dark", "system"].includes(saved)) choice = saved;
      } catch (error) {
        // Use the system theme if browser storage is unavailable.
      }
      const systemDark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
      document.documentElement.dataset.theme = choice === "dark" || (choice === "system" && systemDark) ? "dark" : "light";
    }());
  </script>
  <link rel="stylesheet" href="assets/styles.css?v=<?= filemtime(__DIR__ . '/assets/styles.css') ?>">
  <script src="assets/auth.js" defer></script>
</head>
<body class="auth-page">
  <main class="auth-layout">
    <section class="auth-story">
      <a class="brand" href="login.php"><img src="assets/logo.svg" width="45" height="45" alt="Coin dropping into a graduation cap"><span>allo<span class="brand-light">wallet</span><span class="gold-text">.</span></span></a>
      <div class="auth-story-content"><p class="eyebrow">A LITTLE PLANNING GOES A LONG WAY</p><h1>Make every<br> peso count<span class="gold-text">.</span></h1><p>Your allowance, everyday expenses, and the things you're saving for. A little clarity for student life.</p><div class="auth-feature"><span aria-hidden="true">↙</span><div><strong>A place for your allowance</strong><p>Know what you have and where it goes.</p></div></div><div class="auth-feature"><span aria-hidden="true">◎</span><div><strong>A little closer to your goals</strong><p>Start small. Build a habit that lasts.</p></div></div></div>
      <p class="auth-story-footer">Built for student life. And everything after.</p>
    </section>
    <section class="auth-form-panel" aria-labelledby="auth-title">
      <p class="eyebrow"><?= $mode === 'setup' ? 'YOUR WALLET STARTS HERE' : ($mode === 'reset' ? 'PASSWORD RECOVERY' : 'YOUR OWN LITTLE MONEY SPACE') ?></p>
      <h2 id="auth-title"><?= escapeHtml($title) ?></h2>
      <p class="auth-subtitle"><?php if ($mode === 'setup'): ?>Create the owner account for this wallet. Your existing entries will stay right here.<?php elseif ($legacyRecovery): ?>This older account has no recovery question yet. From this XAMPP computer, enter your account email, set a new password, and choose a recovery question.<?php elseif ($mode === 'reset'): ?>Answer the recovery question you chose when setting up this wallet.<?php else: ?>Sign in to check in on your wallet.<?php endif; ?></p>
      <?php if ($manualReset): ?>
      <div class="form-feedback" role="status">This older account has no recovery question. In phpMyAdmin, open <strong>allo_wallet → wallet_owner</strong>, edit row <strong>id 1</strong>, and replace its <strong>password_hash</strong> using <a href="README.md">the reset steps in README.md</a>.</div>
      <p class="auth-note"><a href="login.php">Back to sign in</a></p>
      <?php else: ?>
      <form id="auth-form" action="login.php" method="post" data-setup="<?= $mode === 'setup' ? 'true' : 'false' ?>">
        <input type="hidden" name="token" value="<?= escapeHtml($_SESSION['wallet_token']) ?>">
        <input type="hidden" name="action" value="<?= escapeHtml($mode) ?>">
        <div id="auth-feedback" class="form-feedback" role="alert" <?= !$errors ? 'hidden' : '' ?>><?= escapeHtml($errors['form'] ?? ($errors ? 'Please correct the highlighted fields.' : '')) ?></div>
        <?php if ($mode === 'setup'): ?>
        <div class="form-field">
          <label for="auth-name">Your name</label>
          <input id="auth-name" name="name" type="text" maxlength="50" autocomplete="name" placeholder="e.g. Alex Santos" value="<?= escapeHtml($name) ?>" aria-describedby="auth-name-error" <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?> required>
          <small class="field-error" id="auth-name-error" <?= !isset($errors['name']) ? 'hidden' : '' ?>><?= escapeHtml($errors['name'] ?? '') ?></small>
        </div>
        <?php endif; ?>
        <div class="form-field">
          <label for="auth-email">Email address</label>
          <input id="auth-email" name="email" type="email" maxlength="254" autocomplete="email" placeholder="student@example.com" value="<?= escapeHtml($email) ?>" aria-describedby="auth-email-error" <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?> required>
          <small class="field-error" id="auth-email-error" <?= !isset($errors['email']) ? 'hidden' : '' ?>><?= escapeHtml($errors['email'] ?? '') ?></small>
        </div>
        <?php if ($mode === 'setup' || $legacyRecovery): ?>
        <div class="form-field">
          <label for="auth-security-question">Recovery question</label>
          <select id="auth-security-question" name="security_question" aria-describedby="auth-security-question-error" <?= isset($errors['security_question']) ? 'aria-invalid="true"' : '' ?> required>
            <option value="">Choose a question</option>
            <?php foreach ($recoveryQuestions as $question): ?>
            <option value="<?= escapeHtml($question) ?>" <?= $securityQuestion === $question ? 'selected' : '' ?>><?= escapeHtml($question) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="field-error" id="auth-security-question-error" <?= !isset($errors['security_question']) ? 'hidden' : '' ?>><?= escapeHtml($errors['security_question'] ?? '') ?></small>
        </div>
        <div class="form-field">
          <label for="auth-security-answer">Your answer</label>
          <input id="auth-security-answer" name="security_answer" type="text" maxlength="100" autocomplete="off" aria-describedby="auth-security-answer-hint auth-security-answer-error" <?= isset($errors['security_answer']) ? 'aria-invalid="true"' : '' ?> required>
          <small class="field-hint" id="auth-security-answer-hint">Answers are not case-sensitive. Choose one you will remember.</small>
          <small class="field-error" id="auth-security-answer-error" <?= !isset($errors['security_answer']) ? 'hidden' : '' ?>><?= escapeHtml($errors['security_answer'] ?? '') ?></small>
        </div>
        <?php endif; ?>
        <?php if ($mode === 'login' || $mode === 'setup'): ?>
        <div class="form-field">
          <label for="auth-password">Password</label>
          <div class="password-input"><input id="auth-password" name="password" type="password" <?= $mode === 'setup' ? 'minlength="8"' : '' ?> maxlength="64" autocomplete="<?= $mode === 'setup' ? 'new-password' : 'current-password' ?>" placeholder="<?= $mode === 'setup' ? 'Create a password' : 'Enter your password' ?>" aria-describedby="password-hint auth-password-error" <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?> required><button type="button" class="password-toggle" aria-controls="auth-password" aria-label="Show password" aria-pressed="false">Show</button></div>
          <small class="field-hint" id="password-hint"><?= $mode === 'setup' ? 'Use 8–64 characters.' : 'Use the password you chose when setting up this wallet.' ?></small>
          <small class="field-error" id="auth-password-error" <?= !isset($errors['password']) ? 'hidden' : '' ?>><?= escapeHtml($errors['password'] ?? '') ?></small>
        </div>
        <?php if ($mode === 'setup'): ?>
        <div class="form-field">
          <label for="auth-confirmation">Confirm password</label>
          <input id="auth-confirmation" name="confirmation" type="password" minlength="8" maxlength="64" autocomplete="new-password" placeholder="Enter your password again" aria-describedby="auth-confirmation-error" <?= isset($errors['confirmation']) ? 'aria-invalid="true"' : '' ?> required>
          <small class="field-error" id="auth-confirmation-error" <?= !isset($errors['confirmation']) ? 'hidden' : '' ?>><?= escapeHtml($errors['confirmation'] ?? '') ?></small>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <?php if (!$legacyRecovery): ?>
        <div class="form-field">
          <label for="auth-reset-answer">Recovery answer</label>
          <small class="field-hint" id="auth-reset-question-hint">Your question: <?= escapeHtml($owner['security_question']) ?></small>
          <input id="auth-reset-answer" name="reset_answer" type="text" maxlength="100" autocomplete="off" aria-describedby="auth-reset-question-hint auth-reset-answer-error" <?= isset($errors['reset_answer']) ? 'aria-invalid="true"' : '' ?> required>
          <small class="field-error" id="auth-reset-answer-error" <?= !isset($errors['reset_answer']) ? 'hidden' : '' ?>><?= escapeHtml($errors['reset_answer'] ?? '') ?></small>
        </div>
        <?php endif; ?>
        <div class="form-field">
          <label for="auth-new-password">New password</label>
          <div class="password-input"><input id="auth-new-password" name="new_password" type="password" minlength="8" maxlength="64" autocomplete="new-password" aria-describedby="auth-new-password-hint auth-new-password-error" <?= isset($errors['new_password']) ? 'aria-invalid="true"' : '' ?> required><button type="button" class="password-toggle" aria-controls="auth-new-password" aria-label="Show new password" aria-pressed="false">Show</button></div>
          <small class="field-hint" id="auth-new-password-hint">Use 8–64 characters.</small>
          <small class="field-error" id="auth-new-password-error" <?= !isset($errors['new_password']) ? 'hidden' : '' ?>><?= escapeHtml($errors['new_password'] ?? '') ?></small>
        </div>
        <div class="form-field">
          <label for="auth-new-confirmation">Confirm new password</label>
          <input id="auth-new-confirmation" name="new_confirmation" type="password" minlength="8" maxlength="64" autocomplete="new-password" aria-describedby="auth-new-confirmation-error" <?= isset($errors['new_confirmation']) ? 'aria-invalid="true"' : '' ?> required>
          <small class="field-error" id="auth-new-confirmation-error" <?= !isset($errors['new_confirmation']) ? 'hidden' : '' ?>><?= escapeHtml($errors['new_confirmation'] ?? '') ?></small>
        </div>
        <?php endif; ?>
        <button type="submit" class="button primary full-width" <?= !$databaseAvailable ? 'disabled' : '' ?>><?= $mode === 'setup' ? 'Create my account' : ($mode === 'reset' ? 'Reset my password' : 'Sign in to my wallet') ?><span aria-hidden="true">→</span></button>
      </form>
      <?php if ($mode === 'login'): ?>
      <p class="auth-note"><a href="login.php?mode=reset">Forgot password?</a></p>
      <p class="auth-note"><a href="portal.php">Student and employee portal</a></p>
      <?php elseif ($mode === 'reset'): ?>
      <p class="auth-note"><a href="login.php">Back to sign in</a></p>
      <?php else: ?>
      <p class="auth-note">One owner account keeps this local wallet simple.</p>
      <p class="auth-note"><a href="portal.php">Student and employee portal</a></p>
      <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
