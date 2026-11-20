// Login, recovery, and first-time account validation. PHP repeats these checks.
const authForm = document.getElementById("auth-form");

if (authForm) {
  const authFields = authForm.querySelectorAll('input:not([type="hidden"]), select');
  const authFeedback = document.getElementById("auth-feedback");
  authForm.noValidate = true;

  function authFieldError(input) {
    const value = input.value;
    if (input.name === "name") {
      return value.trim() && Array.from(value.trim()).length <= 50 ? "" : "Enter your name, using 50 characters or fewer.";
    }
    if (input.name === "email") {
      return value.trim() && !input.validity.typeMismatch && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim()) && value.length <= 254
        ? "" : "Enter a valid email address, such as student@example.com.";
    }
    if (input.name === "security_question") {
      return value ? "" : "Choose a recovery question.";
    }
    if (input.name === "security_answer" || input.name === "reset_answer") {
      const answer = value.trim();
      if (!answer || Array.from(answer).length > 100 || new TextEncoder().encode(answer).length > 72) {
        return "Enter an answer using up to 100 characters and 72 UTF-8 bytes.";
      }
    }
    if (input.name === "password" || input.name === "new_password") {
      if (!value && input.name === "password" && authForm.dataset.setup !== "true") return "Enter your password.";
      if (value.includes("\0")) return "Remove the unsupported character from your password.";
      const length = Array.from(value).length;
      const needsMinimum = input.name === "new_password" || authForm.dataset.setup === "true";
      if ((needsMinimum && length < 8) || length > 64 || new TextEncoder().encode(value).length > 72) {
        return "Use 8–64 characters. If you use emoji, keep the password shorter.";
      }
    }
    if (input.name === "confirmation" && (!value || value !== authForm.elements.password.value)) {
      return "Enter the same password again.";
    }
    if (input.name === "new_confirmation" && (!value || value !== authForm.elements.new_password.value)) {
      return "Enter the same password again.";
    }
    return "";
  }

  function displayAuthError(input) {
    const message = authFieldError(input);
    const error = document.getElementById(input.id + "-error");
    error.textContent = message;
    error.hidden = !message;
    if (message) input.setAttribute("aria-invalid", "true");
    else input.removeAttribute("aria-invalid");
  }

  function updateAuthFeedback() {
    const invalid = authForm.querySelector('[aria-invalid="true"]');
    authFeedback.textContent = invalid ? "Please correct the highlighted fields." : "";
    authFeedback.hidden = !invalid;
  }

  for (const input of authFields) {
    input.addEventListener("blur", function () {
      input.dataset.touched = "true";
      displayAuthError(input);
      updateAuthFeedback();
    });
    input.addEventListener(input.tagName === "SELECT" ? "change" : "input", function () {
      if (input.dataset.touched || input.hasAttribute("aria-invalid")) {
        displayAuthError(input);
        updateAuthFeedback();
      }
      const matchingConfirmation = input.name === "password"
        ? authForm.elements.confirmation
        : input.name === "new_password" ? authForm.elements.new_confirmation : null;
      if (matchingConfirmation && matchingConfirmation.dataset.touched) {
        displayAuthError(matchingConfirmation);
        updateAuthFeedback();
      }
    });
  }

  authForm.addEventListener("submit", function (event) {
    for (const input of authFields) {
      input.dataset.touched = "true";
      displayAuthError(input);
    }
    updateAuthFeedback();
    const firstError = authForm.querySelector('[aria-invalid="true"]');
    if (firstError) {
      event.preventDefault();
      firstError.focus();
    } else {
      // Submit a normal PHP form. Never store the password in browser storage.
      authForm.querySelector('button[type="submit"]').disabled = true;
    }
  });

  for (const toggle of document.querySelectorAll(".password-toggle")) {
    toggle.addEventListener("click", function () {
      const input = document.getElementById(toggle.getAttribute("aria-controls"));
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      toggle.textContent = show ? "Hide" : "Show";
      toggle.setAttribute("aria-label", show ? "Hide password" : "Show password");
      toggle.setAttribute("aria-pressed", String(show));
    });
  }

  const firstServerError = authForm.querySelector('[aria-invalid="true"]');
  if (firstServerError) firstServerError.focus();
}

// Browsers may restore a submitted page from their back/forward cache.
window.addEventListener("pageshow", function (event) {
  if (event.persisted) location.reload();
});
