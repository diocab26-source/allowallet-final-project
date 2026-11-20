<?php
require __DIR__ . '/session.php';
startWalletSession();
header('Cache-Control: no-store');
if (currentWalletRole() !== null) {
    header('Location: ' . currentWalletHome());
    exit;
}
$formToken = htmlspecialchars($_SESSION['wallet_token'], ENT_QUOTES, 'UTF-8');
session_write_close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#173d32">
  <meta name="description" content="Manage student allowances and everyday spending with Allo-Wallet.">
  <title>Student allowance portal · Allo-Wallet</title>
  <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
  <script>
    (function () {
      let choice = "system";
      try {
        const saved = localStorage.getItem("allo-wallet-theme");
        if (["light", "dark", "system"].includes(saved)) choice = saved;
      } catch (error) {}
      const systemDark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
      document.documentElement.dataset.theme = choice === "dark" || (choice === "system" && systemDark) ? "dark" : "light";
    }());
  </script>
  <link rel="stylesheet" href="assets/styles.css?v=<?= filemtime(__DIR__ . '/assets/styles.css') ?>">
  <script src="assets/portal.js?v=<?= filemtime(__DIR__ . '/assets/portal.js') ?>" defer></script>
</head>
<body class="auth-page portal-page">
  <main class="auth-layout">
    <section class="auth-story">
      <a class="brand" href="portal.php"><img src="assets/logo.svg" width="45" height="45" alt="Allo-Wallet logo"><span>allo<span class="brand-light">wallet</span><span class="gold-text">.</span></span></a>
      <div class="auth-story-content">
        <p class="eyebrow">STUDENT ALLOWANCE PORTAL</p>
        <h1>Know what you have<span class="gold-text">.</span></h1>
        <p>Keep your allowance, expenses, and school spending clear in one place.</p>
        <div class="auth-feature"><span aria-hidden="true">₱</span><div><strong>Students</strong><p>Track your allowance and record expenses.</p></div></div>
        <div class="auth-feature"><span aria-hidden="true">↗</span><div><strong>Employees</strong><p>Manage student allowance entries.</p></div></div>
        <div class="auth-feature"><span aria-hidden="true">◎</span><div><strong>Administrators</strong><p>Manage accounts and review the system.</p></div></div>
      </div>
      <p class="auth-story-footer">Built for student life. And everything after.</p>
    </section>
    <section class="auth-form-panel" aria-labelledby="portal-title" data-token="<?= $formToken ?>">
      <p class="eyebrow">WELCOME TO ALLO-WALLET</p>
      <h2 id="portal-title">Sign in to your portal.</h2>
      <p class="auth-subtitle">Students, employees, and administrators use their account email here.</p>
      <div id="portal-feedback" class="form-feedback" role="alert" hidden></div>
      <form class="portal-login-form" method="post" action="portal.php" data-portal-auth="login">
        <div class="form-field">
          <label for="portal-login-email">Email address</label>
          <input id="portal-login-email" name="email" type="email" maxlength="254" autocomplete="username" required>
        </div>
        <div class="form-field">
          <label for="portal-login-password">Password</label>
          <div class="password-input"><input id="portal-login-password" name="password" type="password" maxlength="64" autocomplete="current-password" required><button type="button" class="password-toggle" aria-controls="portal-login-password" aria-label="Show password" aria-pressed="false">Show</button></div>
        </div>
        <button class="button primary full-width" type="submit">Sign in <span aria-hidden="true">→</span></button>
      </form>
      <p class="auth-note">Forgot your password? Ask an administrator to reset it.</p>
      <details class="portal-register">
        <summary>Create a student account</summary>
        <form class="portal-register-form" method="post" action="portal.php" data-portal-auth="register">
          <div class="form-field">
            <label for="portal-register-name">Full name</label>
            <input id="portal-register-name" name="name" type="text" maxlength="80" autocomplete="name" required>
          </div>
          <div class="form-field">
            <label for="portal-register-email">Email address</label>
            <input id="portal-register-email" name="email" type="email" maxlength="254" autocomplete="email" required>
          </div>
          <div class="form-field">
            <label for="portal-register-password">Create a password</label>
            <input id="portal-register-password" name="password" type="password" minlength="8" maxlength="64" autocomplete="new-password" required>
          </div>
          <div class="form-field">
            <label for="portal-register-confirmation">Confirm password</label>
            <input id="portal-register-confirmation" name="confirmation" type="password" minlength="8" maxlength="64" autocomplete="new-password" required>
          </div>
          <button class="button secondary full-width" type="submit">Create student account</button>
        </form>
      </details>
      <p class="auth-note"><a href="login.php">Administrator setup and sign in</a></p>
    </section>
  </main>
</body>
</html>
