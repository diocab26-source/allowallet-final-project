<?php
require __DIR__ . '/session.php';
startWalletSession();
header('Cache-Control: no-store');
if (currentWalletRole() !== 'admin') {
    header('Location: ' . currentWalletHome());
    exit;
}
require __DIR__ . '/config.php';
$ownerName = htmlspecialchars($_SESSION['owner_name'] ?? 'Administrator', ENT_QUOTES, 'UTF-8');
$token = htmlspecialchars($_SESSION['wallet_token'], ENT_QUOTES, 'UTF-8');
$counts = ['student' => 0, 'employee' => 0, 'disabled' => 0];
foreach ($pdo->query('SELECT role, status, COUNT(*) AS total FROM portal_accounts GROUP BY role, status') as $row) {
    if ($row['status'] === 'active') $counts[$row['role']] = (int)$row['total'];
    else $counts['disabled'] += (int)$row['total'];
}
session_write_close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#173d32">
  <title>Admin dashboard · Allo-Wallet</title>
  <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
  <script>(function(){let t="system";try{const s=localStorage.getItem("allo-wallet-theme");if(["light","dark","system"].includes(s))t=s}catch(e){}const d=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches;document.documentElement.dataset.theme=t==="dark"||(t==="system"&&d)?"dark":"light"}());</script>
  <link rel="stylesheet" href="assets/styles.css?v=<?= filemtime(__DIR__ . '/assets/styles.css') ?>">
  <script src="assets/portal-dashboard.js?v=<?= filemtime(__DIR__ . '/assets/portal-dashboard.js') ?>" defer></script>
</head>
<body class="dashboard-page" data-role="admin" data-token="<?= $token ?>" data-account-id="1">
  <header class="header">
    <div class="header-inner">
      <a class="brand" href="admin.php"><img src="assets/logo.svg" width="42" height="42" alt=""><span>allo<span class="brand-light">wallet</span><span class="gold-text">.</span></span></a>
      <nav class="main-nav" aria-label="Main navigation"><a class="nav-link" aria-current="page" href="admin.php">Accounts</a><a class="nav-link" href="index.php">Personal wallet</a></nav>
      <div class="account-menu"><span class="owner-name"><?= $ownerName ?></span><form class="logout-form" action="logout.php" method="post"><input type="hidden" name="token" value="<?= $token ?>"><button class="button secondary" type="submit">Log out</button></form></div>
    </div>
  </header>
  <main class="role-main">
    <section class="role-hero">
      <p class="eyebrow">ADMINISTRATOR DASHBOARD</p>
      <h1>Manage the allowance portal<span class="gold-text">.</span></h1>
      <p>Create employee accounts, review student accounts, and control access.</p>
    </section>
    <section class="role-stats" aria-label="Account summary">
      <article class="summary-card"><h2>Active students</h2><p class="summary-value" id="student-count"><?= $counts['student'] ?></p></article>
      <article class="summary-card"><h2>Active employees</h2><p class="summary-value" id="employee-count"><?= $counts['employee'] ?></p></article>
      <article class="summary-card"><h2>Disabled accounts</h2><p class="summary-value" id="disabled-count"><?= $counts['disabled'] ?></p></article>
    </section>
    <div class="role-columns">
      <section class="panel" aria-labelledby="create-account-title">
        <div class="section-heading"><span class="section-number">01</span><div><h2 id="create-account-title">Create an account</h2><p>Add an employee or student account.</p></div></div>
        <p class="form-feedback" id="account-form-feedback" role="status" hidden></p>
        <form id="account-create-form" class="role-form" method="post" action="admin.php">
          <label for="account-name">Full name<input id="account-name" name="name" maxlength="80" required></label>
          <label for="account-email">Email address<input id="account-email" name="email" type="email" maxlength="254" required></label>
          <label for="account-password">Temporary password<input id="account-password" name="password" type="password" minlength="8" maxlength="64" autocomplete="new-password" required></label>
          <label for="account-role">Access role<select id="account-role" name="role"><option value="employee">Employee</option><option value="student">Student</option></select></label>
          <button class="button primary" type="submit">Create account</button>
        </form>
      </section>
      <section class="panel account-list-panel" aria-labelledby="accounts-title">
        <div class="section-heading"><span class="section-number gold-number">02</span><div><h2 id="accounts-title">Accounts</h2><p>Update contact details, reset passwords, or disable access.</p></div></div>
        <div id="accounts-feedback" class="form-feedback" role="status" hidden></div>
        <div class="table-scroll role-table-scroll"><table class="role-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody id="accounts-table-body"><tr><td colspan="5">Loading accounts…</td></tr></tbody></table></div>
      </section>
    </div>
  </main>
</body>
</html>
