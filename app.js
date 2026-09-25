/**
 * Shared frontend runtime.
 *
 * The pages used to be rendered by PHP, so the browser learned the API origin
 * implicitly by sharing it. The frontend is now deployed separately, so every
 * request has to name the API and carry the session cookie explicitly.
 *
 * Load order matters: config.js must define window.WE_API_BASE before this file
 * runs.
 */

const API_BASE = String(window.WE_API_BASE || "").replace(/\/+$/, "");

/** Absolute URL for an API path. Relative when WE_API_BASE is empty. */
function apiUrl(path) {
  return API_BASE + path;
}

/**
 * fetch() against the API with credentials attached.
 *
 * credentials: "include" is the load-bearing option. The session cookie is
 * issued SameSite=None so the browser will send it on a cross-site request,
 * but it is only actually sent if the request opts into credentials. Omit this
 * and every authenticated call returns 401 with no obvious cause.
 */
function apiFetch(path, options = {}) {
  const opts = { credentials: "include", ...options };
  opts.headers = { Accept: "application/json", ...(options.headers || {}) };
  return fetch(apiUrl(path), opts);
}

/** apiFetch for a JSON POST. */
function apiPost(path, body) {
  return apiFetch(path, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
}

/** Read a JSON response, tolerating a non-JSON error page from a proxy. */
async function readJson(response) {
  try {
    return await response.json();
  } catch (error) {
    return {};
  }
}

function applyTheme(theme) {
  const resolved = theme === "light" || theme === "dark" || theme === "auto" ? theme : "dark";
  document.body.dataset.theme = resolved;
}

/**
 * Fetch the signed-in user, settings and CSRF token.
 *
 * Resolves to null when nobody is signed in. me.php answers 200 with
 * authenticated:false rather than 401, so an anonymous visit does not log an
 * error in the console.
 */
async function loadSession() {
  try {
    const response = await apiFetch("me.php");
    if (!response.ok) return null;
    const data = await readJson(response);
    return data.authenticated ? data : null;
  } catch (error) {
    return null;
  }
}

/**
 * Guard for a page that requires authentication.
 *
 * Returns the session, or null after bouncing to the login page. Pages call
 * this instead of relying on a server-side redirect, which no longer exists
 * now that they are static files.
 */
async function requireSession() {
  const session = await loadSession();

  if (!session) {
    // Relative, because login.html lives on this same origin. Routing through
    // the API instead would only add a redirect hop, and login.php does not
    // accept a return path.
    window.location.replace("login.html");
    return null;
  }

  applyTheme(session.settings?.theme);
  return session;
}

/**
 * Show a transient message in the page toast, if the page has one.
 */
let toastTimer;
function showToast(message) {
  const toast = document.getElementById("toast");
  if (!toast) return;

  toast.textContent = message;
  toast.classList.add("is-visible");
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove("is-visible"), 2800);
}

/** Render a status line into an optional element, used for page errors. */
function setPageMessage(id, message) {
  const el = document.getElementById(id);
  if (!el) return;

  if (message) {
    el.textContent = message;
    el.hidden = false;
  } else {
    el.hidden = true;
  }
}

/** Escape text for interpolation into innerHTML. */
function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
