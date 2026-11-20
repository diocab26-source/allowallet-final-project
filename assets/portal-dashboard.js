const dashboard = document.querySelector(".dashboard-page");
const role = dashboard.dataset.role;
const requestToken = dashboard.dataset.token;
const accountId = Number(dashboard.dataset.accountId || 0);

function currency(cents) {
  return new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(cents / 100);
}

function pesosToCents(value) {
  const amount = Number(value);
  if (!Number.isFinite(amount) || amount <= 0 || amount > 1000000) return null;
  return Math.round(amount * 100);
}

function element(tag, text, className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}

async function apiRequest(path, method = "GET", data = null) {
  const headers = {};
  const options = { method: method, headers: headers, credentials: "same-origin" };
  if (data !== null) {
    headers["Content-Type"] = "application/json";
    headers["X-Allo-Token"] = requestToken;
    options.body = JSON.stringify(data);
  }
  const response = await fetch(path, options);
  const result = await response.json();
  if (response.status === 401) location.replace("portal.php");
  if (!response.ok) throw new Error(result.error || "The request could not be completed.");
  return result;
}

function feedback(id, message, isError = true) {
  const node = document.getElementById(id);
  if (!node) return;
  node.textContent = message;
  node.classList.toggle("error", isError);
  node.hidden = !message;
}

function makeButton(label, className, action) {
  const button = element("button", label, "button secondary table-action " + (className || ""));
  button.type = "button";
  button.addEventListener("click", action);
  return button;
}

async function loadAccounts() {
  const result = await apiRequest("api/accounts.php");
  const body = document.getElementById("accounts-table-body");
  body.replaceChildren();
  if (!result.accounts.length) {
    const row = element("tr");
    const cell = element("td", "No student or employee accounts yet.");
    cell.colSpan = 5;
    row.append(cell);
    body.append(row);
  }
  result.accounts.forEach(function (account) {
    const row = element("tr");
    const nameCell = element("td");
    const nameInput = element("input");
    nameInput.value = account.name;
    nameInput.setAttribute("aria-label", "Name for " + account.email);
    nameCell.append(nameInput);
    const emailCell = element("td");
    const emailInput = element("input");
    emailInput.type = "email";
    emailInput.value = account.email;
    emailInput.setAttribute("aria-label", "Email for " + account.name);
    emailCell.append(emailInput);
    const roleCell = element("td", account.role);
    const statusCell = element("td", account.status);
    const actionCell = element("td", undefined, "table-actions");
    actionCell.append(makeButton("Save", "", async function () {
      try {
        await apiRequest("api/accounts.php?id=" + account.id, "PUT", {
          name: nameInput.value,
          email: emailInput.value,
          status: account.status
        });
        feedback("accounts-feedback", "Account details saved.", false);
        await loadAccounts();
      } catch (error) { feedback("accounts-feedback", error.message); }
    }));
    actionCell.append(makeButton("Reset password", "", async function () {
      const password = window.prompt("Enter a temporary password with at least 8 characters.");
      if (password === null) return;
      try {
        await apiRequest("api/accounts.php?id=" + account.id, "PUT", {
          name: nameInput.value,
          email: emailInput.value,
          status: account.status,
          password: password
        });
        feedback("accounts-feedback", "Password reset. Share the temporary password with the account owner securely.", false);
      } catch (error) { feedback("accounts-feedback", error.message); }
    }));
    if (account.status === "active") {
      actionCell.append(makeButton("Disable", "danger-action", async function () {
        if (!window.confirm("Disable access for " + account.name + "? Their ledger history will remain.")) return;
        try {
          await apiRequest("api/accounts.php?id=" + account.id, "DELETE", { id: account.id });
          feedback("accounts-feedback", "Account disabled. Its history was kept.", false);
          await loadAccounts();
        } catch (error) { feedback("accounts-feedback", error.message); }
      }));
    } else {
      actionCell.append(makeButton("Enable", "", async function () {
        try {
          await apiRequest("api/accounts.php?id=" + account.id, "PUT", {
            name: nameInput.value,
            email: emailInput.value,
            status: "active"
          });
          feedback("accounts-feedback", "Account enabled.", false);
          await loadAccounts();
        } catch (error) { feedback("accounts-feedback", error.message); }
      }));
    }
    row.append(nameCell, emailCell, roleCell, statusCell, actionCell);
    body.append(row);
  });
  const active = result.accounts.filter(function (a) { return a.status === "active"; });
  document.getElementById("student-count").textContent = active.filter(function (a) { return a.role === "student"; }).length;
  document.getElementById("employee-count").textContent = active.filter(function (a) { return a.role === "employee"; }).length;
  document.getElementById("disabled-count").textContent = result.accounts.length - active.length;
}

const accountForm = document.getElementById("account-create-form");
if (accountForm) {
  accountForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    const values = new FormData(accountForm);
    const data = Object.fromEntries(values.entries());
    try {
      await apiRequest("api/accounts.php", "POST", data);
      accountForm.reset();
      feedback("account-form-feedback", "Account created.", false);
      await loadAccounts();
    } catch (error) { feedback("account-form-feedback", error.message); }
  });
  loadAccounts().catch(function (error) { feedback("accounts-feedback", error.message); });
}

let selectedStudentId = "";
async function loadLedger(studentId) {
  const body = document.getElementById("ledger-table-body");
  const query = role === "employee" ? "?student_id=" + encodeURIComponent(studentId) : "";
  if (role === "employee" && !studentId) return;
  try {
    const result = await apiRequest("api/ledger.php" + query);
    body.replaceChildren();
    if (role === "employee") {
      document.getElementById("ledger-balance").textContent = currency(result.balance);
      const selected = document.getElementById("student-select").selectedOptions[0];
      document.getElementById("ledger-student-name").textContent = selected ? selected.textContent : "Student ledger";
    } else {
      document.getElementById("ledger-balance").textContent = currency(result.balance);
      document.getElementById("ledger-entry-count").textContent = result.entries.length + (result.entries.length === 1 ? " recorded entry" : " recorded entries");
    }
    if (!result.entries.length) {
      const row = element("tr");
      const cell = element("td", "No entries recorded yet.");
      cell.colSpan = 5;
      row.append(cell);
      body.append(row);
      return;
    }
    result.entries.forEach(function (entry) {
      const row = element("tr");
      row.append(element("td", entry.type), element("td", entry.description));
      if (role === "employee") row.append(element("td", entry.createdByName));
      else row.append(element("td", new Date(entry.createdAt.replace(" ", "T") + "Z").toLocaleDateString("en-PH")));
      row.append(element("td", currency(entry.amount), entry.type === "expense" ? "amount-negative" : "amount-positive"));
      const actionCell = element("td", undefined, "table-actions");
      const canEdit = role === "admin"
        || (role === "student" && entry.type === "expense")
        || (role === "employee" && entry.type === "allowance" && entry.createdByRole === "employee" && entry.createdByAccountId === accountId);
      if (canEdit) {
        actionCell.append(makeButton("Edit", "", async function () {
          const amountText = window.prompt("New amount in pesos", (entry.amount / 100).toFixed(2));
          if (amountText === null) return;
          const description = window.prompt("Description", entry.description);
          if (description === null) return;
          const amount = pesosToCents(amountText);
          if (!amount) { feedback("ledger-feedback", "Enter a valid amount in pesos."); return; }
          try {
            await apiRequest("api/ledger.php?id=" + entry.id, "PUT", {
              id: entry.id,
              amount: amount,
              description: description
            });
            feedback("ledger-feedback", "Entry updated.", false);
            await loadLedger(selectedStudentId);
          } catch (error) { feedback("ledger-feedback", error.message); }
        }));
        actionCell.append(makeButton("Delete", "danger-action", async function () {
          if (!window.confirm("Delete this " + entry.type + " entry?")) return;
          try {
            await apiRequest("api/ledger.php?id=" + entry.id, "DELETE", { id: entry.id });
            feedback("ledger-feedback", "Entry deleted.", false);
            await loadLedger(selectedStudentId);
          } catch (error) { feedback("ledger-feedback", error.message); }
        }));
      } else {
        actionCell.textContent = "—";
      }
      row.append(actionCell);
      body.append(row);
    });
  } catch (error) {
    feedback("ledger-feedback", error.message);
  }
}

const studentSelect = document.getElementById("student-select");
if (studentSelect) {
  apiRequest("api/accounts.php").then(function (result) {
    studentSelect.replaceChildren();
    result.accounts.forEach(function (student) {
      const option = element("option", student.name + " · " + student.email);
      option.value = student.id;
      studentSelect.append(option);
    });
    if (!result.accounts.length) {
      studentSelect.append(element("option", "No active students yet"));
      studentSelect.disabled = true;
      return;
    }
    selectedStudentId = studentSelect.value;
    loadLedger(selectedStudentId);
  }).catch(function (error) { feedback("ledger-feedback", error.message); });
  studentSelect.addEventListener("change", function () {
    selectedStudentId = studentSelect.value;
    loadLedger(selectedStudentId);
  });
}

const allowanceForm = document.getElementById("allowance-create-form");
if (allowanceForm) {
  allowanceForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    const values = new FormData(allowanceForm);
    const amount = pesosToCents(values.get("amount"));
    if (!amount) { feedback("ledger-form-feedback", "Enter a valid amount in pesos."); return; }
    try {
      await apiRequest("api/ledger.php", "POST", {
        type: "allowance",
        student_id: Number(values.get("student_id")),
        amount: amount,
        description: values.get("description")
      });
      allowanceForm.reset();
      selectedStudentId = studentSelect.value;
      feedback("ledger-form-feedback", "Allowance saved.", false);
      await loadLedger(selectedStudentId);
    } catch (error) { feedback("ledger-form-feedback", error.message); }
  });
}

const expenseForm = document.getElementById("expense-create-form");
if (expenseForm) {
  loadLedger("");
  expenseForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    const values = new FormData(expenseForm);
    const amount = pesosToCents(values.get("amount"));
    if (!amount) { feedback("ledger-form-feedback", "Enter a valid amount in pesos."); return; }
    try {
      await apiRequest("api/ledger.php", "POST", {
        type: "expense",
        amount: amount,
        description: values.get("description")
      });
      expenseForm.reset();
      feedback("ledger-form-feedback", "Expense added.", false);
      await loadLedger("");
    } catch (error) { feedback("ledger-form-feedback", error.message); }
  });
}
