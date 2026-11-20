const portalPanel = document.querySelector(".auth-form-panel[data-token]");
const portalToken = portalPanel ? portalPanel.dataset.token : "";
const portalFeedback = document.getElementById("portal-feedback");

function showPortalFeedback(message, isError = true) {
  portalFeedback.textContent = message;
  portalFeedback.classList.toggle("error", isError);
  portalFeedback.hidden = !message;
}

document.querySelectorAll("[data-portal-auth]").forEach(function (form) {
  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    if (!form.reportValidity()) return;
    const submitButton = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    payload.action = form.dataset.portalAuth;
    submitButton.disabled = true;
    showPortalFeedback("");
    try {
      const headers = { "Content-Type": "application/json" };
      if (payload.action === "register") headers["X-Allo-Token"] = portalToken;
      const response = await fetch("api/auth.php", {
        method: "POST",
        headers: headers,
        body: JSON.stringify(payload),
        credentials: "same-origin"
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Unable to continue. Please try again.");
      window.location.assign(result.home);
    } catch (error) {
      showPortalFeedback(error.message || "The portal is unavailable. Check that MySQL is running.");
      submitButton.disabled = false;
    }
  });
});

document.querySelectorAll(".password-toggle").forEach(function (toggle) {
  toggle.addEventListener("click", function () {
    const input = document.getElementById(toggle.getAttribute("aria-controls"));
    const show = input.type === "password";
    input.type = show ? "text" : "password";
    toggle.textContent = show ? "Hide" : "Show";
    toggle.setAttribute("aria-label", show ? "Hide password" : "Show password");
    toggle.setAttribute("aria-pressed", String(show));
  });
});
