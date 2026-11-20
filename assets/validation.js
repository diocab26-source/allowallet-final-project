// Small, reusable form checks. PHP checks the same values before saving.

// Read pesos exactly: "85.50" becomes 8550 centavos.
function parsePesos(value) {
  if (!/^\d+(\.\d{1,2})?$/.test(value)) return NaN;
  const parts = value.split(".");
  return Number(parts[0]) * 100 + Number((parts[1] || "").padEnd(2, "0"));
}

function moneyError(input) {
  if (input.validity.badInput) return "Enter a number, such as 100 or 85.50.";
  const value = input.value.trim();
  if (value === "") return "Enter an amount.";
  if (Number(value) <= 0) return "The amount must be greater than ₱0.";
  if (!/^\d+(\.\d{1,2})?$/.test(value)) {
    return "Use numbers and up to 2 decimal places, without commas or exponents.";
  }
  const amount = parsePesos(value);
  if (!Number.isSafeInteger(amount) || amount > 100000000) {
    return "The maximum amount is ₱1,000,000.00.";
  }
  return "";
}

function fieldError(input) {
  if (input.type === "password") {
    const value = input.value;
    const characters = Array.from(value).length;
    const bytes = new TextEncoder().encode(value).length;
    if (input.id === "current-password") {
      if (value === "") return "Enter your current password.";
      if (value.includes("\0")) return "Remove the unsupported character from this password.";
      if (characters > 64 || bytes > 72) return "Use 64 characters or fewer. If you use emoji, keep the password shorter.";
      return "";
    }
    if (input.id === "new-password") {
      if (value.includes("\0")) return "Remove the unsupported character from this password.";
      if (characters < 8 || characters > 64 || bytes > 72) return "Use 8–64 characters. If you use emoji, keep the password shorter.";
      return "";
    }
    if (input.id === "confirm-password") {
      if (value === "") return "Enter the same password again.";
      return value === document.getElementById("new-password").value ? "" : "Enter the same password again.";
    }
  }
  if (input.type === "number") {
    if (input.dataset.optionalBudget === "true" && input.value.trim() === "") return "";
    const error = moneyError(input);
    if (error) return error;
    if (input.id === "savings-amount" && parsePesos(input.value) > getTotals().balance) {
      return "You can set aside up to " + formatMoney(Math.max(0, getTotals().balance)) + ". Add allowance if needed.";
    }
    if (input.id === "withdrawal-amount" && parsePesos(input.value) > getGoalSavings(selectedGoalId)) {
      return "You can withdraw up to " + formatMoney(Math.max(0, getGoalSavings(selectedGoalId))) + " from this goal.";
    }
    return "";
  }
  if (input.type === "date") {
    if (input.validity.badInput) return "Choose a valid date.";
    if (input.value && input.min && input.value < input.min) {
      return input.id === "next-allowance-date" ? "Choose today or a later date." : "Choose a date from January 1, 2000 through today.";
    }
    if (input.value && input.max && input.value > input.max) {
      return "Choose a date from January 1, 2000 through today.";
    }
    return "";
  }
  if (input.id === "expense-category" || input.id === "edit-category") {
    return categories.includes(input.value) ? "" : "Choose an expense category.";
  }
  const value = input.value.trim();
  if (input.id === "allowance-source") {
    return Array.from(value).length <= input.maxLength ? "" : "Use 40 characters or fewer.";
  }
  if (input.id === "expense-description" || input.id === "edit-description") {
    if (value === "") return input.id === "expense-description" ? "Describe what you spent on." : "Enter a description for this entry.";
    return Array.from(value).length <= input.maxLength ? "" : "Use 60 characters or fewer.";
  }
  if (value === "") {
    return "Enter a name for your savings goal.";
  }
  if (Array.from(value).length > input.maxLength) {
    return "Use " + input.maxLength + " characters or fewer.";
  }
  return "";
}

function setFieldError(input, message) {
  const error = document.getElementById(input.id + "-error");
  error.textContent = message;
  error.hidden = message === "";
  if (message) input.setAttribute("aria-invalid", "true");
  else input.removeAttribute("aria-invalid");
}

function updateFormFeedback(form, message = "") {
  const feedback = document.getElementById(form.id + "-feedback");
  const count = form.querySelectorAll('[aria-invalid="true"]').length;
  feedback.textContent = message || (count === 1
    ? "Please correct the highlighted field."
    : "Please correct the " + count + " highlighted fields.");
  feedback.hidden = count === 0 && message === "";
}

function validateForm(form) {
  const inputs = form.querySelectorAll('input:not([type="hidden"]):not(:disabled), select:not(:disabled)');
  for (const input of inputs) {
    input.dataset.touched = "true";
    setFieldError(input, fieldError(input));
  }
  updateFormFeedback(form);
  const firstError = form.querySelector('[aria-invalid="true"]');
  if (firstError) firstError.focus();
  return firstError === null;
}

function clearFormErrors(form) {
  for (const input of form.querySelectorAll('input:not([type="hidden"]), select')) {
    setFieldError(input, "");
    delete input.dataset.touched;
  }
  updateFormFeedback(form);
}

// PHP returns field names such as "amount" or "description".
function showServerErrors(form, fields, message) {
  clearFormErrors(form);
  for (const name of Object.keys(fields)) {
    const input = form.elements.namedItem(name);
    if (input && input.matches("input, select") && typeof fields[name] === "string") {
      setFieldError(input, fields[name]);
    }
  }
  updateFormFeedback(form, message);
}

function setupFormValidation() {
  for (const form of document.querySelectorAll(".forms-grid form, #edit-transaction-form, #change-password-form, #settings-form, #budgets-form")) {
    // Keep HTML required/min/max attributes as documentation and a native fallback.
    // JavaScript supplies inline messages when the app is running.
    form.noValidate = true;
    for (const input of form.querySelectorAll('input:not([type="hidden"]):not(:disabled), select:not(:disabled)')) {
      input.addEventListener("blur", function () {
        input.dataset.touched = "true";
        setFieldError(input, fieldError(input));
        updateFormFeedback(form);
      });
      input.addEventListener("input", function () {
        if (input.dataset.touched || input.hasAttribute("aria-invalid")) {
          setFieldError(input, fieldError(input));
          updateFormFeedback(form);
        }
        if (input.id === "new-password") {
          const confirmation = document.getElementById("confirm-password");
          if (confirmation.dataset.touched || confirmation.hasAttribute("aria-invalid")) {
            setFieldError(confirmation, fieldError(confirmation));
            updateFormFeedback(form);
          }
        }
      });
      input.addEventListener("change", function () {
        input.dataset.touched = "true";
        setFieldError(input, fieldError(input));
        updateFormFeedback(form);
      });
    }
    form.addEventListener("reset", function () { clearFormErrors(form); });
  }
}
