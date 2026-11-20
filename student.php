<?php
require __DIR__ . '/session.php';
startWalletSession();
header('Cache-Control: no-store');
if (currentWalletRole() !== 'student') {
    header('Location: ' . currentWalletHome());
    exit;
}
$name = htmlspecialchars($_SESSION['portal_user_name'] ?? 'Student', ENT_QUOTES, 'UTF-8');
$accountId = (int)$_SESSION['portal_user_id'];
$token = htmlspecialchars($_SESSION['wallet_token'], ENT_QUOTES, 'UTF-8');
session_write_close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#173d32">
  <title>Student portal · Allo-Wallet</title>
  <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
  <script>(function(){let t="system";try{const s=localStorage.getItem("allo-wallet-theme");if(["light","dark","system"].includes(s))t=s}catch(e){}const d=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches;document.documentElement.dataset.theme=t==="dark"||(t==="system"&&d)?"dark":"light"}());</script>
  <link rel="stylesheet" href="assets/styles.css?v=<?= filemtime(__DIR__ . '/assets/styles.css') ?>">
  <script src="assets/portal-dashboard.js?v=<?= filemtime(__DIR__ . '/assets/portal-dashboard.js') ?>" defer></script>
</head>
<body class="dashboard-page" data-role="student" data-token="<?= $token ?>" data-account-id="<?= $accountId ?>">
  <header class="header">
    <div class="header-inner">
      <a class="brand" href="student.php"><img src="assets/logo.svg" width="42" height="42" alt=""><span>allo<span class="brand-light">wallet</span><span class="gold-text">.</span></span></a>
      <nav class="main-nav" aria-label="Main navigation"><a class="nav-link" aria-current="page" href="student.php">My allowance</a></nav>
      <div class="account-menu"><span class="owner-name"><?= $name ?></span><form class="logout-form" action="logout.php" method="post"><input type="hidden" name="token" value="<?= $token ?>"><button class="button secondary" type="submit">Log out</button></form></div>
    </div>
  </header>
  <main class="role-main">
    <section class="role-hero">
      <p class="eyebrow">STUDENT PORTAL</p>
      <h1>Your allowance, made clear<span class="gold-text">.</span></h1>
      <p>See what has been added and keep track of your own spending.</p>
    </section>
    <section class="student-balance-card" aria-live="polite">
      <p>Available allowance</p>
      <strong id="ledger-balance">Loading…</strong>
      <span id="ledger-entry-count">Your allowance and expense entries</span>
    </section>
    <div class="role-columns student-columns">
      <section class="panel" aria-labelledby="expense-entry-title">
        <div class="section-heading"><span class="section-number">01</span><div><h2 id="expense-entry-title">Record an expense</h2><p>Expenses cannot be greater than your available allowance.</p></div></div>
        <p class="form-feedback" id="ledger-form-feedback" role="status" hidden></p>
        <form id="expense-create-form" class="role-form" method="post" action="student.php">
          <label for="expense-amount">Amount in pesos<input id="expense-amount" name="amount" type="number" min="0.01" max="1000000" step="0.01" required></label>
          <label for="expense-description">Description<input id="expense-description" name="description" maxlength="100" placeholder="e.g. Lunch" required></label>
          <button class="button primary" type="submit">Add expense</button>
        </form>
      </section>
      <section class="panel" aria-labelledby="student-ledger-title">
        <div class="section-heading"><span class="section-number gold-number">02</span><div><h2 id="student-ledger-title">My activity</h2><p>Only you and authorized staff can view your entries.</p></div></div>
        <p id="ledger-feedback" class="form-feedback" role="status" hidden></p>
        <div class="table-scroll role-table-scroll"><table class="role-table"><thead><tr><th>Type</th><th>Description</th><th>Date</th><th>Amount</th><th>Actions</th></tr></thead><tbody id="ledger-table-body"><tr><td colspan="5">Loading activity…</td></tr></tbody></table></div>
      </section>
    </div>
  </main>
</body>
</html>
