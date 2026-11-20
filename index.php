<?php
// Protect the wallet page before sending any HTML.
require __DIR__ . '/session.php';
startWalletSession();
header('Cache-Control: no-store');
if (!isSignedIn()) {
    header('Location: ' . currentWalletHome());
    exit;
}
$displayName = htmlspecialchars($_SESSION['owner_name'], ENT_QUOTES, 'UTF-8');
$logoutToken = htmlspecialchars($_SESSION['wallet_token'], ENT_QUOTES, 'UTF-8');
$walletToday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$budgetCategories = [
    ['label' => 'Food & drinks', 'id' => 'food-drinks', 'field' => 'budget_food_drinks'],
    ['label' => 'Transport', 'id' => 'transport', 'field' => 'budget_transport'],
    ['label' => 'Dorm & bills', 'id' => 'dorm-bills', 'field' => 'budget_dorm_bills'],
    ['label' => 'School', 'id' => 'school', 'field' => 'budget_school'],
    ['label' => 'Personal', 'id' => 'personal', 'field' => 'budget_personal'],
    ['label' => 'Others', 'id' => 'others', 'field' => 'budget_others']
];
session_write_close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#173d32">
  <meta name="description" content="A simple student budget tracker for your allowance, daily expenses, and savings.">
  <title>Allo-Wallet — Make every peso count.</title>
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
  <script src="assets/validation.js" defer></script>
  <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>" defer></script>
</head>
<body>
  <a class="skip-link" href="#overview">Skip to wallet</a>

  <!-- The header and all sections stay on one page. -->
  <header class="header">
    <div class="header-inner">
      <a class="brand" href="#overview" aria-label="Allo-Wallet home">
        <img src="assets/logo.svg" width="42" height="42" alt="A gold coin dropping into a graduation cap">
        <span>allo<span class="brand-light">wallet</span><span class="gold-text">.</span></span>
      </a>
      <nav class="main-nav" aria-label="Main navigation">
        <a href="#overview" class="nav-link">My wallet</a>
        <a href="#insights" class="nav-link">Insights</a>
        <a href="#activity" class="nav-link">Activity</a>
        <a href="#settings" class="nav-link">Settings</a>
        <a href="admin.php" class="nav-link">Admin</a>
      </nav>
      <div class="account-menu">
        <button type="button" id="account-menu-button" class="account-menu-button" aria-label="Account menu for <?= $displayName ?>" aria-expanded="false" aria-controls="account-menu-panel">
          <span class="owner-name"><?= $displayName ?></span><span aria-hidden="true">v</span>
        </button>
        <div class="account-menu-panel" id="account-menu-panel" role="group" aria-labelledby="account-menu-button" hidden>
          <a class="account-menu-link" href="#settings" id="account-settings-link">Settings</a>
          <button type="button" class="account-menu-link" id="change-password-open" aria-haspopup="dialog">Change password</button>
          <label class="theme-choice" for="theme-select">Theme
            <select id="theme-select" name="theme">
              <option value="system">System</option>
              <option value="light">Light</option>
              <option value="dark">Dark</option>
            </select>
          </label>
          <form class="logout-form" action="logout.php" method="post"><input type="hidden" name="token" value="<?= $logoutToken ?>"><button type="submit" class="button secondary">Log out</button></form>
        </div>
      </div>
    </div>
  </header>

  <main>
    <section class="intro" id="overview">
      <div>
        <p class="eyebrow">A LITTLE PLANNING GOES A LONG WAY</p>
        <h1>Make every peso count<span class="gold-text">.</span></h1>
        <p class="subtitle">Your allowance, daily expenses, and next big goal. All in one little wallet.</p>
        <p class="safe-to-spend" id="safe-to-spend" role="status" aria-live="polite">Set your next allowance date in Settings to see a daily guide.</p>
      </div>
      <p class="today">
        <time id="today-date"></time>
        <span class="today-separator" aria-hidden="true">·</span>
        <time id="live-clock" aria-label="Current time in the Philippines"></time>
      </p>
    </section>

    <div class="storage-warning" id="storage-warning" role="alert" hidden><span id="warning-text"></span><button type="button" id="retry-connection" class="button secondary">Try again</button></div>
    <div class="import-banner" id="import-banner" hidden><p>Found your earlier browser wallet. Bring those entries into your new database wallet.</p><button type="button" class="button secondary" id="import-wallet">Import my entries</button></div>
    <div id="message" class="message" role="status" aria-live="polite" hidden></div>

    <div class="summary-period-toolbar">
      <span>Show totals for</span>
      <div class="summary-period-control" role="group" aria-label="Summary card period">
        <button type="button" class="period-button" data-summary-period="week" aria-pressed="false">This week</button>
        <button type="button" class="period-button" data-summary-period="month" aria-pressed="true">This month</button>
        <button type="button" class="period-button" data-summary-period="all" aria-pressed="false">All time</button>
      </div>
    </div>
    <!-- JavaScript updates these totals whenever a transaction changes. -->
    <section class="summary-grid" aria-label="Your wallet totals">
      <article class="summary-card balance-card">
        <div class="summary-heading"><h2>Available balance (all time)</h2><span class="stat-symbol" aria-hidden="true">₱</span></div>
        <p class="summary-value loading-skeleton" id="balance" aria-label="Loading"></p>
        <p class="summary-note"><span class="balance-dot"></span>Yours to budget, yours to enjoy</p>
      </article>
      <article class="summary-card">
        <div class="summary-heading"><h2 id="allowance-total-label">Allowance · This month</h2><span class="stat-symbol sage" aria-hidden="true">↙</span></div>
        <p class="summary-value loading-skeleton" id="total-allowance" aria-label="Loading"></p>
        <p class="summary-note" id="allowance-summary-note">Every little top-up counts</p>
      </article>
      <article class="summary-card">
        <div class="summary-heading"><h2 id="expenses-total-label">Expenses · This month</h2><span class="stat-symbol gold" aria-hidden="true">↗</span></div>
        <p class="summary-value loading-skeleton" id="total-expenses" aria-label="Loading"></p>
        <p class="summary-note" id="expenses-summary-note">For the everyday essentials</p>
      </article>
      <article class="summary-card">
        <div class="summary-heading"><h2 id="savings-total-label">Savings · This month</h2><span class="stat-symbol sage" aria-hidden="true">◎</span></div>
        <p class="summary-value loading-skeleton" id="total-savings" aria-label="Loading"></p>
        <p class="summary-note" id="savings-summary-note">A little closer to your goal</p>
      </article>
    </section>

    <p class="form-instructions">Enter amounts in pesos without commas, with up to two decimal places. Dates and allowance sources are optional.</p>
    <div class="forms-grid">
      <div class="forms-stack">
        <section class="panel" aria-labelledby="allowance-title">
          <div class="section-heading"><span class="section-number">01</span><div><h2 id="allowance-title">A little money in</h2><p>Add your daily or weekly allowance.</p></div></div>
          <form id="allowance-form" class="allowance-form">
            <div class="form-field">
              <label for="allowance-amount">Amount (₱)</label>
              <input id="allowance-amount" name="amount" type="number" inputmode="decimal" min="0.01" max="1000000" step="0.01" placeholder="e.g. 1000" aria-describedby="allowance-amount-error" required>
              <small class="field-error" id="allowance-amount-error" hidden></small>
            </div>
            <button class="button primary" type="submit"><span aria-hidden="true">＋</span> Add allowance</button>
            <div class="form-field">
              <label for="allowance-source">Source <span class="optional-label">(optional)</span></label>
              <input id="allowance-source" name="source" type="text" maxlength="40" placeholder="e.g. Parents or scholarship" aria-describedby="allowance-source-hint allowance-source-error">
              <small class="field-hint" id="allowance-source-hint">Up to 40 characters.</small>
              <small class="field-error" id="allowance-source-error" hidden></small>
            </div>
            <div class="form-field">
              <label for="allowance-date">Date <span class="optional-label">(optional)</span></label>
              <input id="allowance-date" name="date" type="date" min="2000-01-01" max="<?= htmlspecialchars($walletToday, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($walletToday, ENT_QUOTES, 'UTF-8') ?>" aria-describedby="allowance-date-hint allowance-date-error">
              <small class="field-hint" id="allowance-date-hint">Choose a date from January 1, 2000 through today.</small>
              <small class="field-error" id="allowance-date-error" hidden></small>
            </div>
            <p class="form-feedback" id="allowance-form-feedback" role="status" hidden></p>
          </form>
        </section>

        <section class="panel" aria-labelledby="expense-title">
          <div class="section-heading"><span class="section-number">02</span><div><h2 id="expense-title">Keep the small things in sight</h2><p>A quick note now, a clearer picture later.</p></div></div>
          <form id="expense-form">
            <div class="form-field">
              <label for="expense-description">What did you spend on?</label>
              <input id="expense-description" name="description" type="text" maxlength="60" placeholder="e.g. Lunch at the canteen" aria-describedby="expense-description-hint expense-description-error" required>
              <small class="field-hint" id="expense-description-hint">A short description, up to 60 characters.</small>
              <small class="field-error" id="expense-description-error" hidden></small>
            </div>
            <div class="form-row">
              <div class="form-field">
                <label for="expense-amount">Amount (₱)</label>
                <input id="expense-amount" name="amount" type="number" inputmode="decimal" min="0.01" max="1000000" step="0.01" placeholder="e.g. 85" aria-describedby="expense-amount-error" required>
                <small class="field-error" id="expense-amount-error" hidden></small>
              </div>
              <div class="form-field">
                <label for="expense-category">Category</label>
                <select id="expense-category" name="category" aria-describedby="expense-category-error" required>
                  <option value="">Choose a category</option>
                  <option>Food &amp; drinks</option><option>Transport</option><option>Dorm &amp; bills</option><option>School</option><option>Personal</option><option>Others</option>
                </select>
                <small class="field-error" id="expense-category-error" hidden></small>
              </div>
            </div>
            <div class="form-field">
              <label for="expense-date">Date <span class="optional-label">(optional)</span></label>
              <input id="expense-date" name="date" type="date" min="2000-01-01" max="<?= htmlspecialchars($walletToday, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($walletToday, ENT_QUOTES, 'UTF-8') ?>" aria-describedby="expense-date-hint expense-date-error">
              <small class="field-hint" id="expense-date-hint">Choose a date from January 1, 2000 through today.</small>
              <small class="field-error" id="expense-date-error" hidden></small>
            </div>
            <div class="form-actions"><button class="button primary" type="submit"><span aria-hidden="true">＋</span> Add expense</button><button class="button secondary" type="reset">Clear</button></div>
            <p class="form-feedback" id="expense-form-feedback" role="status" hidden></p>
          </form>
        </section>
      </div>

      <div class="forms-stack">
        <section class="panel savings-panel" aria-labelledby="savings-title">
          <div class="section-heading"><span class="section-number gold-number">03</span><div><h2 id="savings-title">Something to look forward to</h2><p>Big dreams start with small amounts.</p></div></div>
          <div class="goal-switcher">
            <label for="goal-select">Your goals</label>
            <select id="goal-select" aria-describedby="goal-archived-note"></select>
            <button type="button" class="button secondary" id="archive-goal">Archive selected goal</button>
          </div>
          <div class="goal-display">
            <div class="goal-art" aria-hidden="true"><span class="goal-ring">◎</span><span class="spark spark-one">✧</span><span class="spark spark-two">✧</span></div>
            <h3 id="goal-name">Emergency fund</h3>
            <p class="goal-amount"><strong id="goal-saved">₱0.00</strong><span> of <span id="goal-target">₱5,000.00</span></span></p>
            <progress id="goal-progress" value="0" max="100" aria-label="Savings goal progress"></progress>
            <div class="progress-labels"><span id="goal-percent">0% saved</span><span id="goal-remaining">One peso at a time.</span></div>
          </div>
          <p class="goal-archived-note" id="goal-archived-note" hidden>This goal is archived. You can still withdraw its savings.</p>
          <p class="goal-total-note" id="goal-total-note">Total savings across all goals: ₱0.00</p>
          <p class="goal-projection" id="goal-projection" hidden></p>
          <form id="savings-form" class="allowance-form">
            <div class="form-field">
              <label for="savings-amount">Set aside (₱)</label>
              <input id="savings-amount" name="amount" type="number" inputmode="decimal" min="0.01" max="1000000" step="0.01" placeholder="e.g. 100" aria-describedby="savings-amount-error savings-amount-hint" required>
              <small class="field-error" id="savings-amount-error" hidden></small>
            </div>
            <button class="button primary" type="submit"><span aria-hidden="true">＋</span> Add savings</button>
            <p class="form-feedback" id="savings-form-feedback" role="status" hidden></p>
          </form>
          <p class="form-note" id="savings-amount-hint">Savings are set aside from your available balance.</p>
          <form id="withdrawal-form" class="allowance-form withdrawal-form">
            <div class="form-field">
              <label for="withdrawal-amount">Withdraw from this goal (₱)</label>
              <input id="withdrawal-amount" name="amount" type="number" inputmode="decimal" min="0.01" max="1000000" step="0.01" placeholder="e.g. 25" aria-describedby="withdrawal-amount-error withdrawal-amount-hint" required>
              <small class="field-error" id="withdrawal-amount-error" hidden></small>
              <small class="field-hint" id="withdrawal-amount-hint">Move saved money back to your available balance.</small>
            </div>
            <button class="button secondary" type="submit">Withdraw</button>
            <p class="form-feedback" id="withdrawal-form-feedback" role="status" hidden></p>
          </form>
          <details class="edit-goal">
            <summary>Rename or retarget this goal <span aria-hidden="true">↗</span></summary>
            <form id="goal-form">
              <div class="form-field">
                <label for="goal-name-input">Goal name</label>
                <input id="goal-name-input" name="name" type="text" maxlength="50" value="Emergency fund" aria-describedby="goal-name-input-hint goal-name-input-error" required>
                <small class="field-hint" id="goal-name-input-hint">Give your goal a name, up to 50 characters.</small>
                <small class="field-error" id="goal-name-input-error" hidden></small>
              </div>
              <div class="form-field">
                <label for="goal-target-input">Target amount (₱)</label>
                <input id="goal-target-input" name="target" type="number" inputmode="decimal" min="0.01" max="1000000" step="0.01" value="5000" aria-describedby="goal-target-input-error" required>
                <small class="field-error" id="goal-target-input-error" hidden></small>
              </div>
              <button class="button secondary full-width" type="submit">Save selected goal</button>
              <p class="form-feedback" id="goal-form-feedback" role="status" hidden></p>
              <p class="form-note">Updating your goal keeps the money you've already saved.</p>
            </form>
          </details>
          <details class="add-goal">
            <summary>Add another savings goal</summary>
            <form id="add-goal-form">
              <div class="form-field">
                <label for="new-goal-name">Goal name</label>
                <input id="new-goal-name" name="name" type="text" maxlength="50" aria-describedby="new-goal-name-error" required>
                <small class="field-error" id="new-goal-name-error" hidden></small>
              </div>
              <div class="form-field">
                <label for="new-goal-target">Target amount (₱)</label>
                <input id="new-goal-target" name="target" type="number" inputmode="decimal" min="0.01" max="1000000" step="0.01" aria-describedby="new-goal-target-error" required>
                <small class="field-error" id="new-goal-target-error" hidden></small>
              </div>
              <button class="button secondary full-width" type="submit">Add goal</button>
              <p class="form-feedback" id="add-goal-form-feedback" role="status" hidden></p>
            </form>
          </details>
        </section>
        <aside class="tip-card"><span class="tip-icon" aria-hidden="true">✧</span><div><h3>A small money thought</h3><p id="money-thought-text">Start with what you have. Even ₱20 a day is a step toward something bigger.</p></div></aside>
      </div>
    </div>

    <!-- Expense insights sit between the forms and the activity list. -->
    <section class="panel insights-panel" id="insights" aria-labelledby="insights-title">
      <div class="insights-heading">
        <div>
          <p class="eyebrow">A CLEARER PICTURE</p>
          <h2 id="insights-title">Where your money went</h2>
          <p>See how your expenses are shared across categories.</p>
        </div>
        <div class="insights-period">
          <label for="insights-period-filter">Period</label>
          <select id="insights-period-filter">
            <option value="week">This week</option>
            <option value="month" selected>This month</option>
            <option value="all">All time</option>
          </select>
        </div>
      </div>
      <p class="biggest-category" id="biggest-category" aria-live="polite"></p>
      <p class="insights-empty" id="insights-empty" role="status" hidden>No expenses in this period yet. They will show up here when you add them.</p>
      <div class="insights-content" id="insights-content">
        <div class="chart-scroll">
          <svg id="expense-chart" class="expense-chart" role="img" aria-labelledby="expense-chart-title expense-chart-description" viewBox="0 0 620 48">
            <title id="expense-chart-title">Expenses by category</title>
            <desc id="expense-chart-description">A horizontal bar chart of expenses by category.</desc>
          </svg>
        </div>
        <div class="insights-table-scroll">
          <table class="insights-table">
            <caption class="sr-only">Expense amounts and share by category for the selected period</caption>
            <thead><tr><th scope="col">Category</th><th scope="col">Amount</th><th scope="col">Share</th></tr></thead>
            <tbody id="insights-legend"></tbody>
          </table>
        </div>
      </div>
      <p class="biggest-month" id="biggest-month"></p>
    </section>

    <section class="panel activity-panel" id="activity" aria-labelledby="activity-title">
      <div class="activity-heading"><div><p class="eyebrow">THE SMALL THINGS ADD UP</p><h2 id="activity-title" tabindex="-1">Your wallet story</h2><p>All your activity, from the very first peso.</p></div><span class="entry-count" id="entry-count">0 entries</span></div>
      <!-- Search, filters, export, and print apply to the activity below. -->
      <div class="activity-tools" id="activity-tools">
        <div class="activity-filters" role="group" aria-label="Filter activity">
          <label class="filter-control search-control" for="activity-search"><span>Search</span><input id="activity-search" type="search" placeholder="Description or category" autocomplete="off"></label>
          <label class="filter-control" for="activity-type-filter"><span>Type</span><select id="activity-type-filter"><option value="all">All types</option><option value="income">Allowance</option><option value="expense">Expenses</option><option value="saving">Savings</option><option value="withdrawal">Withdrawals</option></select></label>
          <label class="filter-control" for="activity-category-filter"><span>Category</span><select id="activity-category-filter"><option value="all">All categories</option><option>Allowance</option><option>Food &amp; drinks</option><option>Transport</option><option>Dorm &amp; bills</option><option>School</option><option>Personal</option><option>Others</option><option>Savings</option></select></label>
          <label class="filter-control" for="activity-date-filter"><span>Date</span><select id="activity-date-filter"><option value="all">All time</option><option value="week">This week</option><option value="month">This month</option></select></label>
        </div>
        <div class="activity-actions">
          <button type="button" class="button secondary" id="download-csv">Download CSV</button>
          <button type="button" class="button secondary" id="print-wallet">Print / Save as PDF</button>
        </div>
        <p class="filter-match-count" id="filter-match-count" aria-live="polite" hidden></p>
      </div>
      <div class="table-scroll">
        <table>
          <thead><tr><th scope="col">Transaction</th><th scope="col">Category</th><th scope="col">Date</th><th scope="col" class="amount-cell">Amount</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody id="transaction-list"></tbody>
        </table>
      </div>
      <div class="empty-state" id="empty-state"><img src="assets/logo.svg" width="45" height="45" alt=""><h3>A fresh start for your wallet</h3><p>Add your first allowance above. Your money story starts here.</p><a href="#allowance-title" class="text-link">Let's get started <span aria-hidden="true">→</span></a></div>
      <div class="filter-empty-state" id="filter-empty-state" role="status" hidden><p>No entries match your filters.</p><button type="button" class="button secondary" id="clear-filters">Clear filters</button></div>
      <div class="show-more-wrap"><button type="button" class="button secondary" id="show-more" hidden>Show more</button></div>
      <p class="activity-note">Edit allowance and expense details, or use × to remove an entry. You can undo a removal for a few seconds.</p>
    </section>

    <section class="panel settings-panel" id="settings" aria-labelledby="settings-title">
      <div class="section-heading"><span class="section-number">04</span><div><h2 id="settings-title">A little planning ahead</h2><p>Set an allowance date and gentle monthly limits.</p></div></div>
      <div class="settings-grid">
        <form id="settings-form">
          <h3>Next allowance</h3>
          <div class="form-field">
            <label for="next-allowance-date">Expected date <span class="optional-label">(optional)</span></label>
            <input id="next-allowance-date" name="next_allowance_date" type="date" min="<?= htmlspecialchars($walletToday, ENT_QUOTES, 'UTF-8') ?>" aria-describedby="next-allowance-date-hint next-allowance-date-error">
            <small class="field-hint" id="next-allowance-date-hint">Choose today or a future date to see a daily spending guide.</small>
            <small class="field-error" id="next-allowance-date-error" hidden></small>
          </div>
          <button type="submit" class="button secondary">Save date</button>
          <p class="form-feedback" id="settings-form-feedback" role="status" hidden></p>
        </form>
        <details class="monthly-budgets" id="monthly-budgets">
          <summary>Monthly budgets</summary>
          <p class="budget-intro">Set an optional limit for each expense category. Leave a field blank for no limit.</p>
          <form id="budgets-form">
            <div class="budget-fields">
              <?php foreach ($budgetCategories as $budgetCategory): ?>
              <div class="budget-item">
                <label for="budget-<?= htmlspecialchars($budgetCategory['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($budgetCategory['label'], ENT_QUOTES, 'UTF-8') ?> (₱)</label>
                <input id="budget-<?= htmlspecialchars($budgetCategory['id'], ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($budgetCategory['field'], ENT_QUOTES, 'UTF-8') ?>" type="number" inputmode="decimal" min="0.01" max="1000000" step="0.01" data-budget-input data-optional-budget="true" data-category="<?= htmlspecialchars($budgetCategory['label'], ENT_QUOTES, 'UTF-8') ?>" aria-describedby="budget-<?= htmlspecialchars($budgetCategory['id'], ENT_QUOTES, 'UTF-8') ?>-error">
                <small class="field-error" id="budget-<?= htmlspecialchars($budgetCategory['id'], ENT_QUOTES, 'UTF-8') ?>-error" hidden></small>
                <progress class="budget-progress" id="budget-progress-<?= htmlspecialchars($budgetCategory['id'], ENT_QUOTES, 'UTF-8') ?>" value="0" max="100" aria-label="<?= htmlspecialchars($budgetCategory['label'], ENT_QUOTES, 'UTF-8') ?> monthly budget usage" hidden></progress>
                <p class="budget-status" id="budget-status-<?= htmlspecialchars($budgetCategory['id'], ENT_QUOTES, 'UTF-8') ?>"></p>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="submit" class="button secondary">Save monthly budgets</button>
            <p class="form-feedback" id="budgets-form-feedback" role="status" hidden></p>
          </form>
        </details>
      </div>
    </section>

    <dialog id="edit-transaction-dialog" class="edit-dialog" aria-labelledby="edit-transaction-title">
      <form id="edit-transaction-form" method="dialog">
        <h2 id="edit-transaction-title">Edit this entry</h2>
        <p class="edit-dialog-note">Update the amount, description, category, or date.</p>
        <input id="edit-transaction-id" name="id" type="hidden">
        <div class="form-field">
          <label for="edit-description">Description</label>
          <input id="edit-description" name="description" type="text" maxlength="60" aria-describedby="edit-description-error" required>
          <small class="field-error" id="edit-description-error" hidden></small>
        </div>
        <div class="form-field">
          <label for="edit-amount">Amount (₱)</label>
          <input id="edit-amount" name="amount" type="number" inputmode="decimal" min="0.01" max="1000000" step="0.01" aria-describedby="edit-amount-error" required>
          <small class="field-error" id="edit-amount-error" hidden></small>
        </div>
        <div class="form-field" id="edit-category-field">
          <label for="edit-category">Category</label>
          <select id="edit-category" name="category" aria-describedby="edit-category-error" required>
            <option value="">Choose a category</option>
            <option>Food &amp; drinks</option><option>Transport</option><option>Dorm &amp; bills</option><option>School</option><option>Personal</option><option>Others</option>
          </select>
          <small class="field-error" id="edit-category-error" hidden></small>
        </div>
        <div class="form-field">
          <label for="edit-date">Date <span class="optional-label">(optional)</span></label>
          <input id="edit-date" name="date" type="date" min="2000-01-01" max="<?= htmlspecialchars($walletToday, ENT_QUOTES, 'UTF-8') ?>" aria-describedby="edit-date-hint edit-date-error">
          <small class="field-hint" id="edit-date-hint">Choose a date from January 1, 2000 through today.</small>
          <small class="field-error" id="edit-date-error" hidden></small>
        </div>
        <p class="form-feedback" id="edit-transaction-form-feedback" role="status" hidden></p>
        <div class="form-actions"><button type="button" class="button secondary" id="cancel-edit-transaction">Cancel</button><button type="submit" class="button primary">Save changes</button></div>
      </form>
    </dialog>

    <footer><span><img src="assets/logo.svg" width="20" height="20" alt="">A little clarity. A little confidence. Allo-Wallet.</span><span id="save-status" aria-live="polite">Reconnecting…</span></footer>
    <dialog id="change-password-dialog" class="edit-dialog" aria-labelledby="change-password-title">
      <form id="change-password-form" method="dialog">
        <h2 id="change-password-title">Change password</h2>
        <p class="edit-dialog-note">Choose a new password that is easy for you to remember.</p>
        <div class="form-field">
          <label for="current-password">Current password</label>
          <input id="current-password" name="current_password" type="password" autocomplete="current-password" maxlength="64" aria-describedby="current-password-error" required>
          <small class="field-error" id="current-password-error" hidden></small>
        </div>
        <div class="form-field">
          <label for="new-password">New password</label>
          <input id="new-password" name="new_password" type="password" autocomplete="new-password" maxlength="64" aria-describedby="new-password-error" required>
          <small class="field-error" id="new-password-error" hidden></small>
        </div>
        <div class="form-field">
          <label for="confirm-password">Confirm new password</label>
          <input id="confirm-password" name="confirmation" type="password" autocomplete="new-password" maxlength="64" aria-describedby="confirm-password-error" required>
          <small class="field-error" id="confirm-password-error" hidden></small>
        </div>
        <p class="form-feedback" id="change-password-form-feedback" role="status" hidden></p>
        <div class="form-actions"><button type="button" class="button secondary" id="cancel-change-password">Cancel</button><button type="submit" class="button primary">Save password</button></div>
      </form>
    </dialog>
  </main>
  <noscript><p class="noscript">Please enable JavaScript to use your wallet.</p></noscript>
</body>
</html>
