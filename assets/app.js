// JavaScript handles the page. api.php handles saving and reading MySQL data.
// Amounts are stored in centavos: 100 means PHP 1.00.
const storageKey = "allo-wallet-beginner";
const categories = ["Food & drinks", "Transport", "Dorm & bills", "School", "Personal", "Others"];
let transactions = [];
let goals = [];
let selectedGoalId = "";
let goalName = "Emergency fund";
let goalTarget = 500000;
let databaseReady = false;
let requestPending = false;
let walletToken = "";
let canImport = false;
let legacyWallet = null;
let messageTimer;
let sectionObserver = null;
let visibleLimit = 10;
let printVisibleLimit = null;
let summaryPeriod = "month";
let nextAllowanceDate = null;
let categoryBudgets = {};
let moneyThoughtChosen = false;

const chartColors = {
  "Food & drinks": "#bd6547",
  "Transport": "#4f7d9b",
  "Dorm & bills": "#8a70a6",
  "School": "#c0912f",
  "Personal": "#c16c82",
  "Others": "#638d84"
};

// Turn centavos into a readable peso amount.
function formatMoney(centavos) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP"
  }).format(centavos / 100);
}

function getAmount(inputId) {
  return parsePesos(document.getElementById(inputId).value.trim());
}

function showMessage(text, isError = false) {
  const message = document.getElementById("message");
  const messageText = document.createElement("span");
  const undoWasFocused = document.activeElement && document.activeElement.id === "undo-button";
  messageText.textContent = text;
  message.replaceChildren(messageText);
  message.classList.toggle("error", isError);
  message.hidden = false;
  clearTimeout(messageTimer);
  messageTimer = setTimeout(function () {
    const undoWasFocused = document.activeElement && document.activeElement.id === "undo-button";
    message.hidden = true;
    if (undoWasFocused) focusActivityHeading();
  }, 5000);
  if (undoWasFocused) focusActivityHeading();
}

function focusActivityHeading() {
  document.getElementById("activity-title").focus();
}

// Use the calendar day the wallet shows when filtering dates.
function localDateKey(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return year + "-" + month + "-" + day;
}

function periodDateRange(period, offset = 0) {
  const today = new Date();
  const endOfToday = new Date(today.getFullYear(), today.getMonth(), today.getDate());
  let start;
  let end;
  if (period === "week") {
    start = new Date(endOfToday);
    start.setDate(start.getDate() - ((start.getDay() + 6) % 7) - (offset * 7));
    end = new Date(endOfToday);
    end.setDate(end.getDate() - (offset * 7));
  } else {
    start = new Date(today.getFullYear(), today.getMonth() - offset, 1);
    if (offset === 0) {
      end = endOfToday;
    } else {
      const lastDay = new Date(today.getFullYear(), today.getMonth() - offset + 1, 0).getDate();
      end = new Date(today.getFullYear(), today.getMonth() - offset, Math.min(today.getDate(), lastDay));
    }
  }
  return { start: localDateKey(start), end: localDateKey(end) };
}

function isInPeriod(transaction, period, offset = 0) {
  if (period === "all") return true;
  const day = localDateKey(transaction.date);
  const range = periodDateRange(period, offset);
  return day >= range.start && day <= range.end;
}

function currentActivityFilters() {
  return {
    search: document.getElementById("activity-search").value.trim().toLocaleLowerCase(),
    type: document.getElementById("activity-type-filter").value,
    category: document.getElementById("activity-category-filter").value,
    period: document.getElementById("activity-date-filter").value
  };
}

function getFilteredTransactions() {
  const filters = currentActivityFilters();
  return transactions.filter(function (transaction) {
    const searchable = (transaction.description + " " + transaction.category).toLocaleLowerCase();
    return (!filters.search || searchable.includes(filters.search))
      && (filters.type === "all" || transaction.type === filters.type)
      && (filters.category === "all" || transaction.category === filters.category)
      && isInPeriod(transaction, filters.period);
  }).slice().reverse();
}

function hasActivityFilters(filters) {
  return Boolean(filters.search) || filters.type !== "all"
    || filters.category !== "all" || filters.period !== "all";
}

function showStorageWarning(text) {
  const warning = document.getElementById("storage-warning");
  document.getElementById("warning-text").textContent = text;
  warning.hidden = false;
}

// Check saved values before using them. This protects against broken saved data.
function validAmount(amount) {
  return Number.isSafeInteger(amount) && amount > 0 && amount <= 100000000;
}

function validWallet(wallet) {
  if (!wallet || !Array.isArray(wallet.transactions) || !Array.isArray(wallet.goals) || wallet.goals.length === 0) return false;
  if (wallet.nextAllowanceDate !== null && (typeof wallet.nextAllowanceDate !== "string"
    || !/^\d{4}-\d{2}-\d{2}$/.test(wallet.nextAllowanceDate)
    || Number.isNaN(Date.parse(wallet.nextAllowanceDate))
    || new Date(wallet.nextAllowanceDate + "T00:00:00Z").toISOString().slice(0, 10) !== wallet.nextAllowanceDate)) return false;
  if (!wallet.budgets || typeof wallet.budgets !== "object" || Array.isArray(wallet.budgets)
    || Object.keys(wallet.budgets).length !== categories.length) return false;
  for (const category of categories) {
    if (!Object.prototype.hasOwnProperty.call(wallet.budgets, category)
      || (wallet.budgets[category] !== null && !validAmount(wallet.budgets[category]))) return false;
  }
  const goalIds = new Set();
  const goalTotals = new Map();
  for (const goal of wallet.goals) {
    if (!goal || typeof goal.id !== "string" || !/^\d+$/.test(goal.id) || goalIds.has(goal.id)
      || typeof goal.name !== "string" || !goal.name.trim() || Array.from(goal.name).length > 50
      || !validAmount(goal.target) || !Number.isSafeInteger(goal.saved) || goal.saved < 0
      || typeof goal.active !== "boolean") return false;
    goalIds.add(goal.id);
    goalTotals.set(goal.id, 0);
  }
  const ids = new Set();
  for (const transaction of wallet.transactions) {
    if (!transaction || typeof transaction.id !== "string" || !transaction.id || ids.has(transaction.id)) return false;
    ids.add(transaction.id);
    if (!["income", "expense", "saving", "withdrawal"].includes(transaction.type)) return false;
    if (!validAmount(transaction.amount)) return false;
    if (typeof transaction.description !== "string" || !transaction.description.trim() || Array.from(transaction.description).length > 60) return false;
    if (typeof transaction.date !== "string" || Number.isNaN(Date.parse(transaction.date))) return false;
    if (transaction.type === "expense" && !categories.includes(transaction.category)) return false;
    if (transaction.type === "income" && transaction.category !== "Allowance") return false;
    if (["saving", "withdrawal"].includes(transaction.type)) {
      if (transaction.category !== "Savings" || typeof transaction.goalId !== "string" || !goalIds.has(transaction.goalId)) return false;
      const current = goalTotals.get(transaction.goalId);
      goalTotals.set(transaction.goalId, current + (transaction.type === "saving" ? transaction.amount : -transaction.amount));
    } else if (transaction.goalId !== null) {
      return false;
    }
  }
  for (const goal of wallet.goals) {
    if (goalTotals.get(goal.id) !== goal.saved) return false;
  }
  if (wallet.selectedGoalId !== undefined && !goalIds.has(wallet.selectedGoalId)) return false;
  return true;
}

// Earlier browser wallets do not have the newer settings and budget fields.
function validLegacyWallet(wallet) {
  if (!wallet || typeof wallet !== "object") return false;
  const compatibleWallet = Object.assign({}, wallet);
  if (!Array.isArray(wallet.transactions) || wallet.transactions.some(function (transaction) {
    return !transaction || typeof transaction !== "object";
  })) return false;
  compatibleWallet.transactions = wallet.transactions.map(function (transaction) {
    return Object.assign({ goalId: null }, transaction);
  });
  if (!Array.isArray(compatibleWallet.goals)) {
    if (typeof wallet.goalName !== "string" || !validAmount(wallet.goalTarget)) return false;
    let saved = 0;
    compatibleWallet.transactions.forEach(function (transaction) {
      if (transaction.type === "saving") {
        transaction.goalId = "1";
        saved += transaction.amount;
      }
    });
    compatibleWallet.goals = [{ id: "1", name: wallet.goalName, target: wallet.goalTarget, saved: saved, active: true }];
  }
  if (!Object.prototype.hasOwnProperty.call(compatibleWallet, "nextAllowanceDate")) {
    compatibleWallet.nextAllowanceDate = null;
  }
  if (!Object.prototype.hasOwnProperty.call(compatibleWallet, "budgets")) {
    compatibleWallet.budgets = {};
    categories.forEach(function (category) {
      compatibleWallet.budgets[category] = null;
    });
  }
  return validWallet(compatibleWallet);
}

function getSelectedGoal() {
  return goals.find(function (goal) { return goal.id === selectedGoalId; }) || null;
}

function setTheme(choice) {
  const systemDark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
  const dark = choice === "dark" || (choice === "system" && systemDark);
  document.documentElement.dataset.theme = dark ? "dark" : "light";
  try {
    localStorage.setItem("allo-wallet-theme", choice);
  } catch (error) {
    // Keep the selected theme for this page if browser storage is unavailable.
  }
}

function setupAccountMenu() {
  const menu = document.getElementById("account-menu-panel");
  const trigger = document.getElementById("account-menu-button");
  const themeSelect = document.getElementById("theme-select");
  const changePasswordDialog = document.getElementById("change-password-dialog");
  const media = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;
  let themeChoice = "system";
  try {
    const saved = localStorage.getItem("allo-wallet-theme");
    if (["light", "dark", "system"].includes(saved)) themeChoice = saved;
  } catch (error) {
    // System theme is the default when browser storage is unavailable.
  }
  themeSelect.value = themeChoice;
  setTheme(themeChoice);

  function closeMenu(returnFocus = false) {
    menu.hidden = true;
    trigger.setAttribute("aria-expanded", "false");
    if (returnFocus) trigger.focus();
  }

  trigger.addEventListener("click", function () {
    const opening = menu.hidden;
    menu.hidden = !opening;
    trigger.setAttribute("aria-expanded", String(opening));
    if (opening) menu.querySelector("a, button, select").focus();
  });
  document.addEventListener("click", function (event) {
    if (!document.querySelector(".account-menu").contains(event.target)) closeMenu();
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && !menu.hidden) closeMenu(true);
  });
  document.getElementById("account-settings-link").addEventListener("click", function () { closeMenu(); });
  document.getElementById("change-password-open").addEventListener("click", function () {
    closeMenu();
    document.getElementById("change-password-form").reset();
    changePasswordDialog.showModal();
  });
  changePasswordDialog.addEventListener("close", function () { trigger.focus(); });
  themeSelect.addEventListener("change", function () {
    themeChoice = themeSelect.value;
    setTheme(themeChoice);
  });
  if (media) {
    const updateSystemTheme = function () {
      if (themeChoice === "system") document.documentElement.dataset.theme = media.matches ? "dark" : "light";
    };
    if (media.addEventListener) media.addEventListener("change", updateSystemTheme);
    else if (media.addListener) media.addListener(updateSystemTheme);
  }
}

function stopSummaryLoading(showPlaceholder = false) {
  document.querySelectorAll(".summary-value").forEach(function (value) {
    value.classList.remove("loading-skeleton");
    value.removeAttribute("aria-label");
    if (showPlaceholder && !value.textContent.trim()) value.textContent = "—";
  });
}

// Disable changes while a request is running, or if the database is unavailable.
function updateControls() {
  const disabled = !databaseReady || requestPending;
  document.querySelectorAll(".forms-grid input, .forms-grid select, .forms-grid button, #edit-transaction-form input, #edit-transaction-form select, #edit-transaction-form button, #change-password-form input, #change-password-form button, #settings-form input, #settings-form button, #budgets-form input, #budgets-form button, #goal-select, #archive-goal, .edit-button, .remove-button, #import-wallet, #undo-button").forEach(function (control) {
    control.disabled = disabled;
  });
  const goal = getSelectedGoal();
  const activeGoals = goals.filter(function (item) { return item.active; }).length;
  document.querySelectorAll("#savings-form input, #savings-form button, #goal-form input, #goal-form button").forEach(function (control) {
    control.disabled = disabled || !goal || !goal.active;
  });
  document.getElementById("archive-goal").disabled = disabled || !goal || !goal.active || activeGoals <= 1;
  document.getElementById("withdrawal-form").querySelectorAll("input, button").forEach(function (control) {
    control.disabled = disabled || !goal;
  });
  document.getElementById("retry-connection").disabled = requestPending;
  document.querySelector(".logout-form button").disabled = requestPending;
  document.getElementById("account-menu-button").disabled = requestPending;
  document.getElementById("change-password-open").disabled = !databaseReady || requestPending;
  document.querySelector("main").setAttribute("aria-busy", String(requestPending));
  document.getElementById("save-status").textContent = requestPending
    ? "Reconnecting…"
    : databaseReady ? "All changes saved ✓" : "Changes paused";
}

// fetch() sends a request to PHP and reads the JSON response.
async function requestWallet(data = null) {
  if (location.protocol === "file:") {
    throw new Error("Start Apache and MySQL in XAMPP, then open http://localhost/ALLOWALLET/.");
  }
  const controller = new AbortController();
  const timeout = setTimeout(function () { controller.abort(); }, 15000);
  try {
    const options = { credentials: "same-origin", cache: "no-store", signal: controller.signal };
    if (data !== null) {
      options.method = "POST";
      options.headers = { "Content-Type": "application/json", "X-Wallet-Token": walletToken };
      options.body = JSON.stringify(data);
    }
    const response = await fetch("api.php", options);
    const result = await response.json();
    if (!response.ok) {
      const error = new Error(result.error || "The wallet request failed.");
      error.validationError = response.status >= 400 && response.status < 500 && response.status !== 403;
      error.fields = result.fields || {};
      if (response.status === 401) location.replace("login.php");
      throw error;
    }
    if (!validWallet(result.wallet) || typeof result.token !== "string") {
      throw new Error("The server returned an unexpected response.");
    }
    return result;
  } finally {
    clearTimeout(timeout);
  }
}

function applyWallet(result) {
  transactions = result.wallet.transactions;
  goals = result.wallet.goals;
  if (result.wallet.selectedGoalId) selectedGoalId = result.wallet.selectedGoalId;
  if (!goals.some(function (goal) { return goal.id === selectedGoalId; })) {
    const firstActiveGoal = goals.find(function (goal) { return goal.active; });
    selectedGoalId = firstActiveGoal ? firstActiveGoal.id : goals[0].id;
  }
  nextAllowanceDate = result.wallet.nextAllowanceDate;
  categoryBudgets = result.wallet.budgets;
  canImport = result.wallet.canImport;
  walletToken = result.token;
  document.querySelector(".logout-form input[name=token]").value = walletToken;
  document.getElementById("next-allowance-date").value = nextAllowanceDate || "";
  document.querySelectorAll("[data-budget-input]").forEach(function (input) {
    const limit = categoryBudgets[input.dataset.category];
    input.value = limit === null ? "" : (limit / 100).toFixed(2);
  });
  updateWallet();
  stopSummaryLoading();
  showLegacyImport();
}

// Old browser records stay untouched. Importing them is optional.
function showLegacyImport() {
  legacyWallet = null;
  try {
    const saved = JSON.parse(localStorage.getItem(storageKey));
    if (canImport && validLegacyWallet(saved)) legacyWallet = saved;
  } catch (error) {
    // A browser storage problem does not stop the database wallet.
  }
  document.getElementById("import-banner").hidden = legacyWallet === null;
}

async function loadWallet() {
  if (requestPending) return;
  requestPending = true;
  updateControls();
  try {
    applyWallet(await requestWallet());
    databaseReady = true;
    document.getElementById("storage-warning").hidden = true;
  } catch (error) {
    databaseReady = false;
    stopSummaryLoading(true);
    showStorageWarning(error.message === "Failed to fetch" || error.name === "AbortError"
      ? "Cannot connect. Check Apache and MySQL in XAMPP, then try again."
      : error instanceof SyntaxError ? "PHP is not responding correctly. Open the app through XAMPP and check the setup in README.md." : error.message);
  } finally {
    requestPending = false;
    updateControls();
  }
}

// Update the screen only AFTER MySQL confirms the change.
async function saveAction(data, form = null) {
  if (!databaseReady || requestPending) return false;
  requestPending = true;
  updateControls();
  try {
    applyWallet(await requestWallet(data));
    if (form) clearFormErrors(form);
    return true;
  } catch (error) {
    if (error.validationError) {
      if (form) showServerErrors(form, error.fields, error.message);
      else showMessage(error.message, true);
    } else {
      databaseReady = false;
      showStorageWarning("We could not confirm the change. Check XAMPP, then use Try again to reload your wallet before submitting again.");
    }
    return false;
  } finally {
    requestPending = false;
    updateControls();
    if (form) {
      const firstError = form.querySelector('[aria-invalid="true"]');
      if (firstError) firstError.focus();
    }
  }
}

// Add up one period while keeping the available balance all-time.
function getTotals(period = "all", offset = 0) {
  let allowance = 0;
  let expenses = 0;
  let savings = 0;
  let allAllowance = 0;
  let allExpenses = 0;
  let allSavings = 0;
  let allWithdrawals = 0;
  for (const transaction of transactions) {
    if (transaction.type === "income") allAllowance += transaction.amount;
    if (transaction.type === "expense") allExpenses += transaction.amount;
    if (transaction.type === "saving") allSavings += transaction.amount;
    if (transaction.type === "withdrawal") allWithdrawals += transaction.amount;
    if (isInPeriod(transaction, period, offset)) {
      if (transaction.type === "income") allowance += transaction.amount;
      if (transaction.type === "expense") expenses += transaction.amount;
      if (transaction.type === "saving") savings += transaction.amount;
      if (transaction.type === "withdrawal") savings -= transaction.amount;
    }
  }
  return {
    allowance: allowance,
    expenses: expenses,
    savings: savings,
    balance: allAllowance - allExpenses - allSavings + allWithdrawals
  };
}

function getGoalSavings(goalId, period = "all") {
  return transactions.reduce(function (total, transaction) {
    if (transaction.goalId !== goalId || !["saving", "withdrawal"].includes(transaction.type)
      || !isInPeriod(transaction, period)) return total;
    return total + (transaction.type === "saving" ? transaction.amount : -transaction.amount);
  }, 0);
}

function periodComparison(type, period, fallback) {
  if (period === "all") return fallback;
  const current = transactions.filter(function (transaction) {
    return (transaction.type === type || (type === "saving" && transaction.type === "withdrawal"))
      && isInPeriod(transaction, period);
  });
  const previous = transactions.filter(function (transaction) {
    return (transaction.type === type || (type === "saving" && transaction.type === "withdrawal"))
      && isInPeriod(transaction, period, 1);
  });
  if (!current.length || !previous.length) return fallback;
  const totalFor = function (entries) {
    return entries.reduce(function (sum, transaction) {
      return sum + (type === "saving" && transaction.type === "withdrawal" ? -transaction.amount : transaction.amount);
    }, 0);
  };
  const difference = totalFor(current) - totalFor(previous);
  const previousLabel = period === "week" ? "last week" : "last month";
  if (difference === 0) return "Same as " + previousLabel;
  return (difference > 0 ? "↑ " : "↓ ") + formatMoney(Math.abs(difference)) + " vs " + previousLabel;
}

function renderSummary() {
  const periodNames = { week: "This week", month: "This month", all: "All time" };
  const totals = getTotals(summaryPeriod);
  document.getElementById("balance").textContent = formatMoney(totals.balance);
  document.getElementById("total-allowance").textContent = formatMoney(totals.allowance);
  document.getElementById("total-expenses").textContent = formatMoney(totals.expenses);
  document.getElementById("total-savings").textContent = formatMoney(totals.savings);
  document.getElementById("allowance-total-label").textContent = "Allowance · " + periodNames[summaryPeriod];
  document.getElementById("expenses-total-label").textContent = "Expenses · " + periodNames[summaryPeriod];
  document.getElementById("savings-total-label").textContent = "Savings across goals · " + periodNames[summaryPeriod];
  document.getElementById("allowance-summary-note").textContent = periodComparison("income", summaryPeriod, "Every little top-up counts");
  document.getElementById("expenses-summary-note").textContent = periodComparison("expense", summaryPeriod, "For the everyday essentials");
  document.getElementById("savings-summary-note").textContent = periodComparison("saving", summaryPeriod, "Every little amount adds up");
  document.querySelectorAll("[data-summary-period]").forEach(function (button) {
    button.setAttribute("aria-pressed", String(button.dataset.summaryPeriod === summaryPeriod));
  });
}

function updateSafeToSpend(balance) {
  const message = document.getElementById("safe-to-spend");
  if (balance <= 0) {
    message.textContent = "Your available balance is at or below zero. Take a little care and add an allowance when you can.";
    message.classList.add("safe-to-spend-warning");
    return;
  }
  message.classList.remove("safe-to-spend-warning");
  if (!nextAllowanceDate) {
    message.textContent = "Set your next allowance date in Settings to see a daily spending guide.";
    return;
  }
  const today = new Date();
  const todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
  const nextDate = new Date(nextAllowanceDate + "T00:00:00");
  const days = Math.max(1, Math.ceil((nextDate - todayStart) / 86400000));
  const dateText = nextDate.toLocaleDateString("en-PH", { month: "long", day: "numeric", year: "numeric" });
  message.textContent = "You can spend about " + formatMoney(Math.max(0, balance) / days) + " per day until " + dateText + ".";
}

function renderBudgets() {
  const monthlySpending = new Map();
  transactions.filter(function (transaction) {
    return transaction.type === "expense" && isInPeriod(transaction, "month");
  }).forEach(function (transaction) {
    monthlySpending.set(transaction.category, (monthlySpending.get(transaction.category) || 0) + transaction.amount);
  });
  document.querySelectorAll("[data-budget-input]").forEach(function (input) {
    const category = input.dataset.category;
    const spent = monthlySpending.get(category) || 0;
    const limit = categoryBudgets[category];
    const idPart = input.id.replace("budget-", "");
    const progress = document.getElementById("budget-progress-" + idPart);
    const status = document.getElementById("budget-status-" + idPart);
    if (limit === null) {
      progress.hidden = true;
      status.textContent = category + ": " + formatMoney(spent) + " spent this month · No limit set.";
      return;
    }
    const percentage = Math.floor(spent / limit * 100);
    progress.hidden = false;
    progress.value = Math.min(100, percentage);
    progress.classList.toggle("near-limit", percentage >= 80 && percentage < 100);
    progress.classList.toggle("over-limit", percentage >= 100);
    progress.setAttribute("aria-valuetext", percentage + "% used");
    status.textContent = category + ": " + formatMoney(spent) + " of " + formatMoney(limit)
      + " · " + percentage + "% used" + (percentage >= 100 ? " — over budget" : "");
  });
}

function rotateStaticTip() {
  const tips = [
    "Small plans can make a busy week feel a little easier.",
    "A quick note after a purchase helps future you.",
    "Saving a little at a time still counts as progress.",
    "Check your wallet before your next small treat.",
    "A plan can change. Your next small step still matters."
  ];
  let index = Math.floor(Math.random() * tips.length);
  try {
    const savedIndex = localStorage.getItem("allo-wallet-tip-index");
    const previousIndex = Number(savedIndex);
    index = savedIndex === null || !Number.isInteger(previousIndex) || previousIndex < 0 || previousIndex >= tips.length
      ? 0 : (previousIndex + 1) % tips.length;
    localStorage.setItem("allo-wallet-tip-index", String(index));
  } catch (error) {
    // A storage setting should not prevent the wallet from showing a tip.
  }
  return tips[index];
}

function chooseMoneyThought(balance, savings) {
  if (moneyThoughtChosen) return;
  const weekEntries = transactions.filter(function (transaction) { return isInPeriod(transaction, "week"); });
  const weekExpenses = weekEntries.filter(function (transaction) { return transaction.type === "expense"; });
  const categoryTotals = new Map();
  weekExpenses.forEach(function (transaction) {
    categoryTotals.set(transaction.category, (categoryTotals.get(transaction.category) || 0) + transaction.amount);
  });
  const biggestCategory = Array.from(categoryTotals.entries()).sort(function (first, second) { return second[1] - first[1]; })[0];
  let tip;
  if (!weekEntries.length) {
    tip = "No entries this week yet. A quick wallet check-in can help you feel ready for the next one.";
  } else if (balance <= 10000) {
    tip = "Your balance is getting low. Take a moment before your next little spend.";
  } else if (biggestCategory) {
    tip = biggestCategory[0] + " is your biggest category this week at " + formatMoney(biggestCategory[1]) + ". A small check-in can help keep it in balance.";
  } else if (savings > 0) {
    const percent = Math.min(100, Math.floor(savings / goalTarget * 100));
    tip = "You are " + percent + "% of the way to " + goalName + ". Every little amount gets you closer.";
  } else {
    tip = rotateStaticTip();
  }
  document.getElementById("money-thought-text").textContent = tip;
  moneyThoughtChosen = true;
}

function renderGoalSwitcher() {
  const select = document.getElementById("goal-select");
  select.replaceChildren();
  goals.forEach(function (goal) {
    const option = document.createElement("option");
    option.value = goal.id;
    option.textContent = goal.name + (goal.active ? "" : " (archived)");
    select.appendChild(option);
  });
  select.value = selectedGoalId;
  const goal = getSelectedGoal();
  if (!goal) return null;
  goalName = goal.name;
  goalTarget = goal.target;
  document.getElementById("goal-name-input").value = goal.name;
  document.getElementById("goal-target-input").value = (goal.target / 100).toFixed(2);
  document.getElementById("goal-archived-note").hidden = goal.active;
  document.getElementById("goal-total-note").textContent = "Total savings across all goals: " + formatMoney(getTotals().savings);
  return goal;
}

function renderGoalProjection(goal, saved) {
  const projection = document.getElementById("goal-projection");
  const today = new Date();
  const todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
  const start = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 27);
  const startKey = localDateKey(start);
  const todayKey = localDateKey(todayStart);
  const recentEntries = transactions.filter(function (transaction) {
    return transaction.goalId === goal.id && ["saving", "withdrawal"].includes(transaction.type)
      && localDateKey(transaction.date) >= startKey && localDateKey(transaction.date) <= todayKey;
  });
  if (!recentEntries.length) {
    projection.hidden = true;
    projection.textContent = "";
    return;
  }
  if (saved >= goal.target) {
    projection.textContent = "This goal has been reached.";
  } else {
    const fourWeekNet = recentEntries.reduce(function (total, transaction) {
      return total + (transaction.type === "saving" ? transaction.amount : -transaction.amount);
    }, 0);
    if (fourWeekNet <= 0) {
      projection.textContent = "Recent withdrawals are higher than savings. Add to this goal to see a projection.";
    } else {
      const averagePerWeek = fourWeekNet / 4;
      const daysUntilGoal = Math.max(1, Math.ceil((goal.target - saved) / averagePerWeek * 7));
      if (daysUntilGoal > 36500) {
        projection.textContent = "At your average of " + formatMoney(averagePerWeek) + "/week, reaching this goal is over 100 years away.";
      } else {
        const reachedDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() + daysUntilGoal);
        projection.textContent = "At your average of " + formatMoney(averagePerWeek) + "/week, you'll reach this by "
          + reachedDate.toLocaleDateString("en-PH", { month: "long", year: "numeric" }) + ".";
      }
    }
  }
  projection.hidden = false;
}

function updateWallet() {
  const totals = getTotals();
  const goal = renderGoalSwitcher();
  if (!goal) return;
  const goalSaved = getGoalSavings(goal.id);
  renderSummary();
  document.getElementById("goal-name").textContent = goalName;
  document.getElementById("goal-saved").textContent = formatMoney(goalSaved);
  document.getElementById("goal-target").textContent = formatMoney(goalTarget);
  const percentage = Math.floor((goalSaved / goalTarget) * 100);
  document.getElementById("goal-progress").value = Math.min(100, percentage);
  document.getElementById("goal-percent").textContent = goalSaved > goalTarget
    ? "Goal reached 🎉 (+" + formatMoney(goalSaved - goalTarget) + " extra)"
    : Math.min(100, percentage) + "% saved";
  document.getElementById("goal-remaining").textContent = goalSaved >= goalTarget
    ? "You did it! Goal reached."
    : formatMoney(goalTarget - goalSaved) + " to go";
  renderGoalProjection(goal, goalSaved);
  document.querySelector(".balance-card").classList.toggle("negative-balance", totals.balance < 0);
  document.querySelector(".balance-card .summary-note").textContent = totals.balance < 0
    ? "Your expenses and savings exceed your allowance"
    : "Yours to budget, yours to enjoy";
  updateSafeToSpend(totals.balance);
  renderBudgets();
  chooseMoneyThought(totals.balance, goalSaved);
  renderTransactions();
  renderInsights();
}

// Use textContent for user-entered text so it is displayed safely.
function makeCell(text, className = "") {
  const cell = document.createElement("td");
  cell.textContent = text;
  cell.className = className;
  return cell;
}

// Apply the activity filters before drawing the latest entries.
function renderTransactions() {
  const list = document.getElementById("transaction-list");
  list.replaceChildren();
  const filters = currentActivityFilters();
  const filtered = getFilteredTransactions();
  const shown = filtered.slice(0, visibleLimit);
  const filtersActive = hasActivityFilters(filters);
  const entryCount = document.getElementById("entry-count");
  const emptyState = document.getElementById("empty-state");
  const filterEmpty = document.getElementById("filter-empty-state");
  const filterMatchCount = document.getElementById("filter-match-count");
  emptyState.hidden = transactions.length > 0;
  filterEmpty.hidden = !filtersActive || filtered.length > 0 || transactions.length === 0;
  filterMatchCount.hidden = !filtersActive || filtered.length === 0;
  filterMatchCount.textContent = filtered.length + (filtered.length === 1 ? " entry matches" : " entries match") + " your filters.";
  document.getElementById("download-csv").disabled = filtered.length === 0;
  entryCount.textContent = transactions.length === 0
    ? "0 entries"
    : shown.length + " of " + transactions.length + (transactions.length === 1 ? " entry" : " entries");
  document.getElementById("show-more").hidden = shown.length >= filtered.length;

  for (const transaction of shown) {
    const row = document.createElement("tr");
    const descriptionCell = makeCell("");
    const description = document.createElement("div");
    description.className = "transaction-title";
    const symbol = document.createElement("span");
    symbol.className = "transaction-symbol " + transaction.type;
    symbol.textContent = transaction.type === "income" ? "↙"
      : transaction.type === "saving" ? "◎" : transaction.type === "withdrawal" ? "↩" : "↗";
    symbol.setAttribute("aria-hidden", "true");
    const title = document.createElement("span");
    title.textContent = transaction.description;
    description.append(symbol, title);
    descriptionCell.appendChild(description);
    const categoryCell = makeCell("");
    const category = document.createElement("span");
    category.className = "category";
    category.textContent = transaction.category;
    categoryCell.appendChild(category);
    const dateCell = makeCell(new Date(transaction.date).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }));
    const sign = ["income", "withdrawal"].includes(transaction.type) ? "+" : "−";
    const amountCell = makeCell(sign + formatMoney(transaction.amount), "amount-cell " + transaction.type);
    const actionCell = makeCell("");
    const actions = document.createElement("div");
    actions.className = "row-actions";
    if (!["saving", "withdrawal"].includes(transaction.type)) {
      const editButton = document.createElement("button");
      editButton.type = "button";
      editButton.className = "edit-button";
      editButton.textContent = "Edit";
      editButton.setAttribute("aria-label", "Edit " + transaction.description);
      editButton.addEventListener("click", function () { openEditDialog(transaction); });
      actions.appendChild(editButton);
    }
    const removeButton = document.createElement("button");
    removeButton.type = "button";
    removeButton.className = "remove-button";
    removeButton.textContent = "×";
    removeButton.setAttribute("aria-label", "Remove " + transaction.description);
    removeButton.title = "Remove this entry";
    removeButton.addEventListener("click", function () { removeTransaction(transaction.id); });
    actions.appendChild(removeButton);
    actionCell.appendChild(actions);
    row.append(descriptionCell, categoryCell, dateCell, amountCell, actionCell);
    list.appendChild(row);
  }
}

function svgElement(name, attributes = {}) {
  const element = document.createElementNS("http://www.w3.org/2000/svg", name);
  Object.keys(attributes).forEach(function (key) { element.setAttribute(key, attributes[key]); });
  return element;
}

// Group expenses into a chart and a matching text table.
function renderInsights() {
  const period = document.getElementById("insights-period-filter").value;
  const periodLabels = { week: "this week", month: "this month", all: "all time" };
  const entries = transactions.filter(function (transaction) {
    return transaction.type === "expense" && isInPeriod(transaction, period);
  });
  const totals = new Map();
  entries.forEach(function (transaction) {
    totals.set(transaction.category, (totals.get(transaction.category) || 0) + transaction.amount);
  });
  const rows = Array.from(totals.entries()).sort(function (first, second) { return second[1] - first[1]; });
  const total = rows.reduce(function (sum, row) { return sum + row[1]; }, 0);
  const chart = document.getElementById("expense-chart");
  const legend = document.getElementById("insights-legend");
  const empty = document.getElementById("insights-empty");
  const content = document.getElementById("insights-content");
  const title = document.getElementById("expense-chart-title");
  const description = document.getElementById("expense-chart-description");

  title.textContent = "Expenses by category for " + periodLabels[period];
  description.textContent = rows.length
    ? rows.map(function (row) { return row[0] + ": " + formatMoney(row[1]); }).join(". ")
    : "No expenses in this period.";
  legend.replaceChildren();
  while (chart.children.length > 2) chart.lastElementChild.remove();
  empty.hidden = rows.length > 0;
  content.hidden = rows.length === 0;

  const monthTotals = new Map();
  transactions.filter(function (transaction) {
    return transaction.type === "expense" && isInPeriod(transaction, "month");
  }).forEach(function (transaction) {
    monthTotals.set(transaction.category, (monthTotals.get(transaction.category) || 0) + transaction.amount);
  });
  const biggestMonth = Array.from(monthTotals.entries()).sort(function (first, second) { return second[1] - first[1]; })[0];
  document.getElementById("biggest-month").textContent = biggestMonth
    ? "Biggest category this month: " + biggestMonth[0] + " · " + formatMoney(biggestMonth[1])
    : "Biggest category this month: No expenses recorded yet.";

  if (!rows.length) {
    chart.setAttribute("viewBox", "0 0 620 48");
    chart.setAttribute("height", "48");
    return;
  }

  const rowHeight = 42;
  chart.setAttribute("viewBox", "0 0 620 " + (rows.length * rowHeight));
  chart.setAttribute("height", String(rows.length * rowHeight));
  rows.forEach(function (row, index) {
    const category = row[0];
    const amount = row[1];
    const y = index * rowHeight;
    const percent = amount / total * 100;
    const label = svgElement("text", { x: 0, y: y + 23, fill: "var(--chart-text)", "font-size": 18 });
    label.textContent = category;
    chart.appendChild(label);
    chart.appendChild(svgElement("rect", { x: 150, y: y + 7, width: 300, height: 18, rx: 9, fill: "var(--chart-track)" }));
    chart.appendChild(svgElement("rect", {
      x: 150, y: y + 7, width: Math.max(5, 300 * amount / rows[0][1]), height: 18, rx: 9,
      fill: chartColors[category] || "#638d84"
    }));
    const share = svgElement("text", { x: 468, y: y + 23, fill: "var(--chart-text)", "font-size": 18 });
    share.textContent = percent.toFixed(1) + "%";
    chart.appendChild(share);

    const tableRow = document.createElement("tr");
    const categoryCell = makeCell(category);
    categoryCell.className = "insight-category";
    categoryCell.style.setProperty("--category-color", chartColors[category] || "#638d84");
    tableRow.append(categoryCell, makeCell(formatMoney(amount)), makeCell(percent.toFixed(1) + "%"));
    legend.appendChild(tableRow);
  });
}

// Keep the navigation in step with the section near the top of the screen.
function setupSectionNavigation() {
  const links = document.querySelectorAll(".main-nav .nav-link");
  const sections = Array.from(links).map(function (link) {
    return document.getElementById(link.getAttribute("href").slice(1));
  }).filter(Boolean);
  if (!links.length || !sections.length) return;

  function setActiveLink(sectionId) {
    links.forEach(function (link) {
      if (link.getAttribute("href") === "#" + sectionId) {
        link.setAttribute("aria-current", "location");
      } else {
        link.removeAttribute("aria-current");
      }
    });
  }

  setActiveLink(sections[0].id);
  if (!("IntersectionObserver" in window)) return;

  sectionObserver = new IntersectionObserver(function (entries) {
    const visibleSections = entries.filter(function (entry) { return entry.isIntersecting; });
    if (!visibleSections.length) return;
    visibleSections.sort(function (first, second) {
      return Math.abs(first.boundingClientRect.top - window.innerHeight * 0.3)
        - Math.abs(second.boundingClientRect.top - window.innerHeight * 0.3);
    });
    setActiveLink(visibleSections[0].target.id);
  }, { rootMargin: "-25% 0px -65% 0px" });
  sections.forEach(function (section) { sectionObserver.observe(section); });
}

function setupActivityTools() {
  const search = document.getElementById("activity-search");
  const typeFilter = document.getElementById("activity-type-filter");
  const categoryFilter = document.getElementById("activity-category-filter");
  const dateFilter = document.getElementById("activity-date-filter");
  [search, typeFilter, categoryFilter, dateFilter].forEach(function (control) {
    control.addEventListener(control === search ? "input" : "change", function () {
      visibleLimit = 10;
      renderTransactions();
    });
  });
  document.getElementById("clear-filters").addEventListener("click", function () {
    search.value = "";
    typeFilter.value = "all";
    categoryFilter.value = "all";
    dateFilter.value = "all";
    visibleLimit = 10;
    renderTransactions();
    search.focus();
  });
  document.getElementById("show-more").addEventListener("click", function () {
    visibleLimit += 10;
    renderTransactions();
    if (this.hidden) {
      const lastAction = document.querySelector("#transaction-list tr:last-child button");
      if (lastAction) lastAction.focus();
    }
  });
  document.getElementById("insights-period-filter").addEventListener("change", renderInsights);
  document.getElementById("download-csv").addEventListener("click", downloadCsv);
  document.getElementById("print-wallet").addEventListener("click", function () { window.print(); });
}

function setupSummaryPeriodControls() {
  document.querySelectorAll("[data-summary-period]").forEach(function (button) {
    button.addEventListener("click", function () {
      summaryPeriod = button.dataset.summaryPeriod;
      renderSummary();
    });
  });
}

// Download every entry that matches the current activity filters.
function downloadCsv() {
  const rows = getFilteredTransactions();
  if (!rows.length) return;
  const csvCell = function (value) { return '"' + String(value).replace(/"/g, '""') + '"'; };
  const lines = [["Date", "Description", "Category", "Type", "Amount"]];
  rows.forEach(function (transaction) {
    const type = transaction.type === "income" ? "Allowance"
      : transaction.type === "expense" ? "Expense"
        : transaction.type === "withdrawal" ? "Withdrawal" : "Savings";
    lines.push([
      localDateKey(transaction.date),
      transaction.description,
      transaction.category,
      type,
      (["income", "withdrawal"].includes(transaction.type) ? "+" : "-") + (transaction.amount / 100).toFixed(2)
    ]);
  });
  const csv = "\uFEFF" + lines.map(function (line) {
    return line.map(csvCell).join(",");
  }).join("\r\n");
  const file = new Blob([csv], { type: "text/csv;charset=utf-8" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(file);
  link.download = "allo-wallet-" + localDateKey(new Date()) + ".csv";
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
  showMessage("Filtered entries downloaded as CSV.");
}

// Show every filtered entry while the browser builds its print view.
window.addEventListener("beforeprint", function () {
  if (printVisibleLimit === null) printVisibleLimit = visibleLimit;
  visibleLimit = Number.MAX_SAFE_INTEGER;
  renderTransactions();
});

window.addEventListener("afterprint", function () {
  if (printVisibleLimit === null) return;
  visibleLimit = printVisibleLimit;
  printVisibleLimit = null;
  renderTransactions();
});

async function addTransaction(type, amount, description, category, extras = {}) {
  if (!validAmount(amount)) {
    showMessage("Enter an amount from ₱0.01 to ₱1,000,000.00.", true);
    return false;
  }
  const formId = type === "income" ? "allowance-form"
    : type === "expense" ? "expense-form"
      : type === "withdrawal" ? "withdrawal-form" : "savings-form";
  return await saveAction(Object.assign({
    action: "add_transaction",
    type: type,
    amount: amount,
    description: description,
    category: category
  }, extras), document.getElementById(formId));
}

function openEditDialog(transaction) {
  const dialog = document.getElementById("edit-transaction-dialog");
  const form = document.getElementById("edit-transaction-form");
  const categorySelect = document.getElementById("edit-category");
  const categoryField = document.getElementById("edit-category-field");
  categorySelect.disabled = false;
  clearFormErrors(form);
  form.dataset.type = transaction.type;
  document.getElementById("edit-transaction-id").value = transaction.id;
  document.getElementById("edit-description").value = transaction.description;
  document.getElementById("edit-amount").value = (transaction.amount / 100).toFixed(2);
  document.getElementById("edit-date").value = transaction.date.slice(0, 10);
  categorySelect.value = transaction.category;
  categoryField.hidden = transaction.type !== "expense";
  categorySelect.disabled = transaction.type !== "expense";
  dialog.showModal();
  document.getElementById("edit-description").focus();
}

function showUndoMessage(transaction) {
  const message = document.getElementById("message");
  const label = document.createElement("span");
  label.textContent = "Entry removed —";
  const undoButton = document.createElement("button");
  undoButton.type = "button";
  undoButton.id = "undo-button";
  undoButton.className = "undo-button";
  undoButton.textContent = "Undo";
  undoButton.setAttribute("aria-label", "Undo removing " + transaction.description);
  undoButton.addEventListener("click", async function () {
    if (requestPending) return;
    const restored = await saveAction({
      action: "restore_transaction",
      transaction: {
        type: transaction.type,
        amount: transaction.amount,
        description: transaction.description,
        category: transaction.category,
        goal_id: transaction.goalId,
        date: transaction.date.slice(0, 10)
      }
    });
    if (restored) {
      showMessage("Entry restored. Your wallet is up to date.");
      focusActivityHeading();
    }
  });
  message.replaceChildren(label, undoButton);
  message.classList.remove("error");
  message.hidden = false;
  clearTimeout(messageTimer);
  messageTimer = setTimeout(function () {
    const undoWasFocused = document.activeElement === undoButton;
    message.hidden = true;
    if (undoWasFocused) focusActivityHeading();
  }, 6000);
  undoButton.focus();
}

async function removeTransaction(id) {
  if (!databaseReady || requestPending) return;
  const transaction = transactions.find(function (item) { return item.id === id; });
  if (!transaction) return;
  const removed = await saveAction({ action: "delete_transaction", id: id });
  if (removed) showUndoMessage(transaction);
}

// Each form has one job and one submit handler.
document.getElementById("allowance-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  if (await addTransaction("income", getAmount("allowance-amount"), "Allowance received", "Allowance", {
    source: this.elements.namedItem("source").value.trim(),
    date: this.elements.namedItem("date").value
  })) {
    this.reset();
    showMessage("Allowance added. A little more room in your wallet.");
  }
});

document.getElementById("expense-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  const description = document.getElementById("expense-description").value.trim();
  if (!description) {
    showMessage("Add a short description for your expense.", true);
    document.getElementById("expense-description").focus();
    return;
  }
  const category = document.getElementById("expense-category").value;
  if (await addTransaction("expense", getAmount("expense-amount"), description, category, {
    date: this.elements.namedItem("date").value
  })) {
    this.reset();
    showMessage(getTotals().balance < 0 ? "Expense added. Your balance is below zero; check your recorded allowance." : "Expense added. That's one less thing to remember.");
  }
});

document.getElementById("savings-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  const amount = getAmount("savings-amount");
  if (amount > getTotals().balance) {
    showMessage("You need enough available balance to set this amount aside.", true);
    return;
  }
  if (await addTransaction("saving", amount, "Saved for " + goalName, "Savings", { goal_id: selectedGoalId })) {
    this.reset();
    showMessage(getGoalSavings(selectedGoalId) >= goalTarget ? "You reached your savings goal. Well done!" : "Savings added. A little closer to your goal.");
  }
});

document.getElementById("withdrawal-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  if (await addTransaction("withdrawal", getAmount("withdrawal-amount"), "", "Savings", { goal_id: selectedGoalId })) {
    this.reset();
    showMessage("Money moved back to your available balance.");
  }
});

document.getElementById("goal-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  const name = document.getElementById("goal-name-input").value.trim();
  const target = getAmount("goal-target-input");
  if (!name || !validAmount(target)) {
    showMessage("Give your goal a name and a positive target amount.", true);
    return;
  }
  if (await saveAction({ action: "save_goal", goal_id: selectedGoalId, name: name, target: target }, this)) {
    document.querySelector(".edit-goal").open = false;
    showMessage("Your selected goal is updated.");
  }
});

document.getElementById("add-goal-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  if (await saveAction({
    action: "create_goal",
    name: this.elements.namedItem("name").value.trim(),
    target: getAmount("new-goal-target")
  }, this)) {
    this.reset();
    document.querySelector(".add-goal").open = false;
    showMessage("Your new savings goal is ready.");
  }
});

document.getElementById("goal-select").addEventListener("change", function () {
  selectedGoalId = this.value;
  updateWallet();
  updateControls();
});

document.getElementById("archive-goal").addEventListener("click", async function () {
  if (!databaseReady || requestPending) return;
  if (await saveAction({ action: "archive_goal", goal_id: selectedGoalId })) {
    showMessage("Goal archived. Its savings are still here if you need them.");
  }
});

document.getElementById("cancel-edit-transaction").addEventListener("click", function () {
  document.getElementById("edit-transaction-dialog").close();
});

document.getElementById("cancel-change-password").addEventListener("click", function () {
  document.getElementById("change-password-dialog").close();
});

document.getElementById("change-password-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  const changed = await saveAction({
    action: "change_password",
    current_password: this.elements.namedItem("current_password").value,
    new_password: this.elements.namedItem("new_password").value,
    confirmation: this.elements.namedItem("confirmation").value
  }, this);
  if (changed) {
    this.reset();
    document.getElementById("change-password-dialog").close();
    showMessage("Your password has been changed.");
    document.getElementById("account-menu-button").focus();
  }
});

document.getElementById("edit-transaction-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  const type = this.dataset.type;
  const category = type === "income" ? "Allowance" : this.elements.namedItem("category").value;
  const updated = await saveAction({
    action: "update_transaction",
    id: this.elements.namedItem("id").value,
    amount: getAmount("edit-amount"),
    description: document.getElementById("edit-description").value.trim(),
    category: category,
    date: this.elements.namedItem("date").value
  }, this);
  if (updated) {
    document.getElementById("edit-transaction-dialog").close();
    showMessage("Entry updated. Your wallet is up to date.");
    focusActivityHeading();
  }
});

document.getElementById("settings-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  const date = this.elements.namedItem("next_allowance_date").value;
  if (await saveAction({ action: "save_settings", next_allowance_date: date || null }, this)) {
    showMessage("Your next allowance date is saved.");
  }
});

document.getElementById("budgets-form").addEventListener("submit", async function (event) {
  event.preventDefault();
  if (!databaseReady || requestPending || !validateForm(this)) return;
  const budgets = {};
  this.querySelectorAll("[data-budget-input]").forEach(function (input) {
    budgets[input.dataset.category] = input.value.trim() === "" ? null : parsePesos(input.value.trim());
  });
  if (await saveAction({ action: "save_budgets", budgets: budgets }, this)) {
    showMessage("Your monthly budgets are saved.");
  }
});

document.getElementById("retry-connection").addEventListener("click", loadWallet);
document.getElementById("import-wallet").addEventListener("click", async function () {
  if (legacyWallet && await saveAction({ action: "import_wallet", wallet: legacyWallet })) {
    showMessage("Your earlier entries are now saved in MySQL. The browser copy is still there.");
  }
});

// Start the app.
setupFormValidation();
setupAccountMenu();
setupSectionNavigation();
setupActivityTools();
setupSummaryPeriodControls();
const dateDisplay = new Intl.DateTimeFormat("en-PH", {
  weekday: "long",
  month: "long",
  day: "numeric",
  year: "numeric",
  timeZone: "Asia/Manila"
});
const timeDisplay = new Intl.DateTimeFormat("en-PH", {
  hour: "numeric",
  minute: "2-digit",
  second: "2-digit",
  hour12: true,
  timeZone: "Asia/Manila"
});
function updateManilaDateTime() {
  const now = new Date();
  document.getElementById("today-date").textContent = dateDisplay.format(now);
  document.getElementById("live-clock").textContent = timeDisplay.format(now);
}
updateManilaDateTime();
window.setInterval(updateManilaDateTime, 1000);
loadWallet();

// After logout, Back must check the session again rather than show a saved page.
window.addEventListener("pageshow", function (event) {
  if (event.persisted) location.reload();
});
