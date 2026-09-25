/**
 * Settings page behaviour.
 *
 * settings.php rendered the form, validated the post and deleted the account in
 * one request. The page is static now, so saving and deleting are two separate
 * JSON calls, both gated on the CSRF token that me.php hands back.
 *
 * Expects app.js to have loaded first.
 */
(function () {
  const settingsForm = document.getElementById("settingsForm");
  const deleteForm = document.getElementById("deleteForm");

  let csrfToken = "";

  function showError(message) {
    setPageMessage("pageError", message);
    setPageMessage("pageSuccess", "");
  }

  function showSuccess(message) {
    setPageMessage("pageSuccess", message);
    setPageMessage("pageError", "");
  }

  function selectedTheme() {
    const checked = document.querySelector('input[name="theme"]:checked');
    return checked ? checked.value : "dark";
  }

  function selectTheme(theme) {
    const input = document.querySelector(`input[name="theme"][value="${theme}"]`);
    if (input) input.checked = true;
  }

  settingsForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    showError("");

    const submit = settingsForm.querySelector('button[type="submit"]');
    const newPassword = document.getElementById("new_password").value;

    // Mirror the server rule locally so an obviously short password does not
    // cost a round trip.
    if (newPassword !== "" && newPassword.length < 6) {
      showError("New password must be at least 6 characters.");
      return;
    }

    submit.disabled = true;

    try {
      const response = await apiPost("update_settings.php", {
        csrf_token: csrfToken,
        email: document.getElementById("email").value,
        theme: selectedTheme(),
        // The language select is commented out in the markup, so the stored
        // value is round-tripped unchanged rather than reset to English.
        language: window.WE_LANGUAGE || "en",
        notifications: document.getElementById("notifications").checked,
        current_password: document.getElementById("current_password").value,
        new_password: newPassword,
      });

      const data = await readJson(response);

      if (!response.ok || data.status !== "success") {
        showError(data.message || "Unable to save settings right now.");
        return;
      }

      applyTheme(data.settings.theme);
      document.getElementById("new_password").value = "";
      document.getElementById("current_password").value = "";
      showSuccess(data.message || "Settings saved.");
    } catch (error) {
      showError("Network error while saving settings. Try again.");
    } finally {
      submit.disabled = false;
    }
  });

  deleteForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    showError("");

    const confirmation = document.getElementById("confirm_delete").value.trim();
    const password = document.getElementById("delete_password").value;

    if (confirmation !== "DELETE") {
      showError("Type DELETE to confirm account removal.");
      return;
    }

    if (!window.confirm("This permanently deletes your account and all saved countries. Continue?")) {
      return;
    }

    const submit = deleteForm.querySelector('button[type="submit"]');
    submit.disabled = true;

    try {
      const response = await apiPost("delete_account.php", {
        csrf_token: csrfToken,
        current_password: password,
        confirm_delete: confirmation,
      });

      const data = await readJson(response);

      if (!response.ok || data.status !== "success") {
        showError(data.message || "Unable to delete the account right now.");
        submit.disabled = false;
        return;
      }

      // The server rows and the session are gone; drop the browser copies too,
      // otherwise a deleted user's travel history stays on disk.
      try {
        localStorage.removeItem("worldExplorerVisited");
        localStorage.removeItem("worldExplorerWishlist");
      } catch (error) {
        // localStorage can be unavailable in private mode; the redirect still
        // happens, only the notice on the login page is skipped.
      }

      window.location.replace("login.html?deleted=1");
    } catch (error) {
      showError("Network error while deleting the account. Try again.");
      submit.disabled = false;
    }
  });

  (async function init() {
    const session = await requireSession();
    if (!session) return;

    csrfToken = session.csrfToken;
    document.getElementById("csrfToken").value = csrfToken;
    document.getElementById("deleteCsrfToken").value = csrfToken;

    document.getElementById("email").value = session.user.email;
    document.getElementById("notifications").checked = Boolean(session.settings.notifications);
    selectTheme(session.settings.theme);

    // The language field is hidden in the markup, so its stored value has to
    // survive a save that never touched it.
    window.WE_LANGUAGE = session.settings.language || "en";
  })();
})();
