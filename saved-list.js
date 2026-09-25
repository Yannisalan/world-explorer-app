/**
 * Renders a saved-country list page.
 *
 * wishlist.php and visited.php were near-identical server-rendered pages, each
 * carrying its own ~100 lines of inline script for loading, removing and
 * localStorage syncing. They are static now, so both pages share this file and
 * differ only in the data attributes on <body>.
 *
 * Expects app.js to have loaded first (for apiFetch, requireSession, showToast).
 */
(function () {
  const config = {
    listId: document.body.dataset.listId,
    countId: document.body.dataset.countId,
    emptyId: document.body.dataset.emptyId,
    get: document.body.dataset.endpointGet,
    save: document.body.dataset.endpointSave,
    remove: document.body.dataset.endpointRemove,
    storageKey: document.body.dataset.storageKey,
    // "Wishlist" or "Visited", used to phrase toasts.
    label: document.body.dataset.label || "Country",
  };

  const list = document.getElementById(config.listId);
  const countEl = document.getElementById(config.countId);
  if (!list || !config.get) return;

  const plural = config.label.toLowerCase() + (config.label === "Visited" ? " countries" : "s");

  function setCount(value) {
    if (countEl) countEl.textContent = String(Math.max(0, value));
  }

  function currentCount() {
    return parseInt(countEl?.textContent ?? "0", 10) || 0;
  }

  function makeItem(country) {
    const li = document.createElement("li");
    li.className = "saved-country";
    li.dataset.country = country;

    const label = escapeHtml(country);
    li.innerHTML = `
      <span class="country-btn" style="display:flex;align-items:center;padding:10px 12px;border-radius:8px;background:rgba(255,255,255,0.07);min-height:38px">${label}</span>
      <button class="remove-btn" type="button" aria-label="Remove ${label} from ${escapeHtml(config.label.toLowerCase())}" title="Remove">&#x2715;</button>`;

    return li;
  }

  function renderEmptyState() {
    list.innerHTML = `
      <li style="padding:16px;color:var(--muted);text-align:center">
        <strong style="display:block;margin-bottom:4px">Your ${escapeHtml(config.label.toLowerCase())} is empty</strong>
        <span style="font-size:14px">Click a country on the globe and add it to your ${escapeHtml(config.label.toLowerCase())}.</span>
      </li>`;
  }

  function addToLocalStorage(country) {
    try {
      const local = JSON.parse(localStorage.getItem(config.storageKey) || "[]");
      if (Array.isArray(local) && !local.includes(country)) {
        local.push(country);
        localStorage.setItem(config.storageKey, JSON.stringify(local));
      }
    } catch (error) {
      // localStorage can be unavailable in private mode; the server copy stands.
    }
  }

  function removeFromLocalStorage(country) {
    try {
      const local = JSON.parse(localStorage.getItem(config.storageKey) || "[]");
      localStorage.setItem(
        config.storageKey,
        JSON.stringify((Array.isArray(local) ? local : []).filter((c) => c !== country))
      );
    } catch (error) {
      // As above.
    }
  }

  async function load() {
    try {
      const response = await apiFetch(config.get);
      if (!response.ok) {
        renderEmptyState();
        setCount(0);
        return;
      }

      const countries = await response.json();
      if (!Array.isArray(countries) || countries.length === 0) {
        renderEmptyState();
        setCount(0);
        return;
      }

      list.innerHTML = "";
      countries.forEach((country) => list.appendChild(makeItem(country)));
      setCount(countries.length);
    } catch (error) {
      setPageMessage("pageError", "Unable to load your saved countries. Try again.");
    }
  }

  /**
   * Push anything saved to localStorage while signed out up to the account.
   *
   * The globe writes to localStorage first so a save survives a failed request,
   * which means a list can contain rows the account has never seen.
   */
  async function syncLocalStorage() {
    let local = [];
    try {
      local = JSON.parse(localStorage.getItem(config.storageKey) || "[]");
    } catch (error) {
      return;
    }

    if (!Array.isArray(local) || local.length === 0) return;

    const known = new Set(
      [...list.querySelectorAll(".saved-country")].map((el) => el.dataset.country)
    );
    const unsynced = local.filter((country) => !known.has(country));
    if (unsynced.length === 0) return;

    showToast(
      `Syncing ${unsynced.length} local ${plural} to your account…`
    );

    let synced = 0;
    for (const country of unsynced) {
      try {
        const response = await apiPost(config.save, { country });
        const data = await readJson(response);
        if (data.status === "success") {
          synced++;
          list.appendChild(makeItem(country));
          setCount(currentCount() + 1);
        }
      } catch (error) {
        // Leave it in localStorage; the next visit retries.
      }
    }

    if (synced > 0) {
      showToast(`${synced} ${plural} synced to your account.`);
    }
  }

  list.addEventListener("click", async function (event) {
    const button = event.target.closest(".remove-btn");
    if (!button) return;

    const item = button.closest(".saved-country");
    const country = item?.dataset.country;
    if (!country) return;

    button.disabled = true;

    try {
      const response = await apiPost(config.remove, { country });
      const data = await readJson(response);

      if (data.status !== "success") {
        showToast("Could not remove country. Try again.");
        button.disabled = false;
        return;
      }

      item.remove();
      setCount(currentCount() - 1);
      removeFromLocalStorage(country);

      if (!list.querySelector(".saved-country")) {
        renderEmptyState();
      }

      showToast(`${country} removed from ${config.label.toLowerCase()}.`);
    } catch (error) {
      showToast("Network error. Try again.");
      button.disabled = false;
    }
  });

  (async function init() {
    const session = await requireSession();
    if (!session) return;

    const heading = document.getElementById("userName");
    if (heading) heading.textContent = session.user.name;

    const avatar = document.getElementById("userAvatar");
    if (avatar) avatar.textContent = session.user.name.charAt(0).toUpperCase() || "E";

    await load();
    await syncLocalStorage();
  })();
})();
