<?php
require __DIR__ . '/session.php';
startWalletSession();
header('Cache-Control: no-store');
if (currentWalletRole() !== 'employee') {
    header('Location: ' . currentWalletHome());
    exit;
}
$name = htmlspecialchars($_SESSION['portal_user_name'] ?? 'Employee', ENT_QUOTES, 'UTF-8');
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
  <title>Employee dashboard · Allo-Wallet</title>
  <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
  <script>(function(){let t="system";try{const s=localStorage.getItem("allo-wallet-theme");if(["light","dark","system"].includes(s))t=s}catch(e){}const d=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches;document.documentElement.dataset.theme=t==="dark"||(t==="system"&&d)?"dark":"light"}());</script>
  <link rel="stylesheet" href="assets/styles.css?v=<?= filemtime(__DIR__ . '/assets/styles.css') ?>">
  <script src="assets/portal-dashboard.js?v=<?= filemtime(__DIR__ . '/assets/portal-dashboard.js') ?>" defer></script>
</head>
<body class="dashboard-page" data-role="employee" data-token="<?= $token ?>" data-account-id="<?= $accountId ?>">
  <header class="header">
    <div class="header-inner">
      <a class="brand" href="employee.php"><img src="assets/logo.svg" width="42" height="42" alt=""><span>allo<span class="brand-light">wallet</span><span class="gold-text">.</span></span></a>
      <nav class="main-nav" aria-label="Main navigation"><a class="nav-link" aria-current="page" href="employee.php">Employee portal</a></nav>
      <div class="account-menu"><span class="owner-name"><?= $name ?></span><form class="logout-form" action="logout.php" method="post"><input type="hidden" name="token" value="<?= $token ?>"><button class="button secondary" type="submit">Log out</button></form></div>
    </div>
  </header>
  <main class="role-main">
    <section class="role-hero">
      <p class="eyebrow">EMPLOYEE PORTAL</p>
      <h1>Student allowance desk<span class="gold-text">.</span></h1>
      <p>Find a student and record an allowance. Your changes are attached to your employee account.</p>
    </section>
    <div class="role-columns employee-columns">
      <section class="panel" aria-labelledby="allowance-entry-title">
        <div class="section-heading"><span class="section-number">01</span><div><h2 id="allowance-entry-title">Issue an allowance</h2><p>Enter the amount in pesos. The portal stores whole centavos.</p></div></div>
        <p class="form-feedback" id="ledger-form-feedback" role="status" hidden></p>
        <form id="allowance-create-form" class="role-form" method="post" action="employee.php">
          <label for="student-select">Student<select id="student-select" name="student_id" required><option value="">Loading students…</option></select></label>
          <label for="allowance-amount">Amount in pesos<input id="allowance-amount" name="amount" type="number" min="0.01" max="1000000" step="0.01" required></label>
          <label for="allowance-description">Description<input id="allowance-description" name="description" maxlength="100" placeholder="e.g. Weekly allowance" required></label>
          <button class="button primary" type="submit">Save allowance</button>
        </form>
      </section>
      <section class="panel" aria-labelledby="student-ledger-title">
        <div class="section-heading"><span class="section-number gold-number">02</span><div><h2 id="student-ledger-title">Student ledger</h2><p id="ledger-student-name">Choose a student to view their entries.</p></div></div>
        <p class="ledger-balance-line">Available balance <strong id="ledger-balance">—</strong></p>
        <div id="ledger-feedback" class="form-feedback" role="status" hidden></div>
        <div class="table-scroll role-table-scroll"><table class="role-table"><thead><tr><th>Type</th><th>Description</th><th>By</th><th>Amount</th><th>Actions</th></tr></thead><tbody id="ledger-table-body"><tr><td colspan="5">Select a student to load the ledger.</td></tr></tbody></table></div>
      </section>
    </div>
  </main>
</body>
</html>
