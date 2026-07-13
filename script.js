const STORAGE_KEYS = {
  visited: "worldExplorerVisited",
  wishlist: "worldExplorerWishlist"
};

const COUNTRY_DATA_ENDPOINTS = [
  "countries.php",
  "data/countries.json",
  "https://raw.githubusercontent.com/mledoze/countries/master/countries.json"
];

const TOPOLOGY_ENDPOINTS = [
  "https://unpkg.com/world-atlas@2/countries-110m.json",
  "https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json"
];

const elements = {
  globe: document.getElementById("globeViz"),
  searchForm: document.getElementById("searchForm"),
  search: document.getElementById("search"),
  suggestions: document.getElementById("countrySuggestions"),
  infoBox: document.getElementById("infoBox"),
  countryDetails: document.getElementById("countryDetails"),
  closeInfoBox: document.getElementById("closeInfoBox"),
  loading: document.getElementById("loadingSpinner"),
  installButton: document.getElementById("installButton"),
  visitedList: document.getElementById("visitedList"),
  wishlistList: document.getElementById("wishlistList"),
  visitedCount: document.getElementById("visitedCount"),
  wishlistCount: document.getElementById("wishlistCount"),
  toast: document.getElementById("toast")
};

const state = {
  allCountries: [],
  selectedCountry: null,
  visited: new Set(),
  wishlist: new Set(),
  installPrompt: null,
  clickTimer: null
};

let world;
document.addEventListener("DOMContentLoaded", initApp);

function initApp() {
  if (!window.Globe || !window.topojson) {
    showToast("Map libraries are still loading. Refresh if the globe does not appear.");
    return;
  }

  registerServiceWorker();
  setupInstallPrompt();
  setupEvents();
  initGlobe();
  loadSavedLists();
  loadCountryAtlas();
}

function setupUserMenu() {
  const menu = document.getElementById("userMenu");
  const button = document.getElementById("userMenuButton");
  const panel = document.getElementById("userMenuPanel");
  if (!menu || !button || !panel) return;

  const closeMenu = () => {
    menu.classList.remove("is-open");
    button.setAttribute("aria-expanded", "false");
  };

  button.addEventListener("click", event => {
    event.stopPropagation();
    const isOpen = menu.classList.toggle("is-open");
    button.setAttribute("aria-expanded", isOpen ? "true" : "false");
  });

  panel.addEventListener("click", event => event.stopPropagation());

  document.addEventListener("click", closeMenu);
  document.addEventListener("keydown", event => {
    if (event.key === "Escape") closeMenu();
  });
}

function setupEvents() {
  setupUserMenu();

  elements.searchForm.addEventListener("submit", event => {
    event.preventDefault();
    const query = elements.search.value.trim();
    if (!query) return;

    const country = findCountry(query);
    if (!country) {
      showToast("Country not found.");
      return;
    }

    showCountry(country);
    focusCountry(country);
  });
  
  elements.closeInfoBox.addEventListener("click", () => {
    elements.infoBox.classList.remove("is-visible");
  });


  elements.countryDetails.addEventListener("click", event => {
    const action = event.target.closest("[data-action]")?.dataset.action;
    if (!action || !state.selectedCountry) return;

    if (action === "visited") {
      saveCountry("save_country.php", state.visited, state.selectedCountry.name.common, "Visited");
    }

    if (action === "wishlist") {
      saveCountry("save_wishlist.php", state.wishlist, state.selectedCountry.name.common, "Wishlist");
    }

    if (action === "images") {
      loadCountryImages(state.selectedCountry.name.common);
    }
  });

  window.addEventListener("resize", () => {
    if (!world) return;
    world.width(window.innerWidth).height(window.innerHeight);
  });

  // On mobile, innerWidth/Height update is deferred after orientationchange.
  window.addEventListener("orientationchange", () => {
    setTimeout(() => {
      if (!world) return;
      world.width(window.innerWidth).height(window.innerHeight);
    }, 250);
  });
}

function initGlobe() {
  setLoading(true);

  world = Globe()
    .globeImageUrl("https://unpkg.com/three-globe/example/img/earth-blue-marble.jpg")
    .bumpImageUrl("https://unpkg.com/three-globe/example/img/earth-topology.png")
    .backgroundImageUrl("https://unpkg.com/three-globe/example/img/night-sky.png")
    .backgroundColor("#05070d")
    .showAtmosphere(true)
    .atmosphereColor("#2dd4bf")
    .atmosphereAltitude(0.25)
    .width(window.innerWidth)
    .height(window.innerHeight)
    (elements.globe);

  world.renderer().setPixelRatio(Math.min(window.devicePixelRatio, 2));
  world.controls().enableZoom = true;
  world.controls().enableRotate = true;
  world.controls().enablePan = false;
  world.controls().autoRotate = true;
  world.controls().autoRotateSpeed = 0.25;
  world.controls().update();

  world.pointOfView({
    lat: 18,
    lng: 24,
    altitude: 2.45
  }, 1200);
}

async function loadCountryAtlas() {
  try {
    const [worldData, countryData] = await Promise.all([
      fetchFirstJson(TOPOLOGY_ENDPOINTS),
      loadCountryData()
    ]);

    state.allCountries = normalizeCountryData(countryData);

    if (!state.allCountries.length) {
      state.allCountries = countriesFromAtlas(worldData);
      showToast("Loaded basic country names from the atlas.");
    }

    if (!state.allCountries.length) {
      throw new Error("No country records found");
    }

    state.allCountries.sort((a, b) =>
      a.name.common.localeCompare(b.name.common)
    );

    populateSearchSuggestions();
    renderCountryPolygons(worldData);
    renderCapitalMarkers();
  } catch (error) {
    console.error(error);
    showToast("Could not load country data.");
  } finally {
    setLoading(false);
  }
}

async function loadCountryData() {
  for (const endpoint of COUNTRY_DATA_ENDPOINTS) {
    try {
      const data = await fetchJsonStrict(endpoint);
      const countries = normalizeCountryData(data);
      if (countries.length) return countries;
    } catch (error) {
      console.warn(`Country data source failed: ${endpoint}`, error);
    }
  }

  return [];
}

async function fetchFirstJson(urls) {
  let lastError;

  for (const url of urls) {
    try {
      return await fetchJsonStrict(url);
    } catch (error) {
      lastError = error;
      console.warn(`Atlas source failed: ${url}`, error);
    }
  }

  throw lastError || new Error("No atlas sources configured");
}

async function fetchJsonStrict(url) {
  const response = await fetch(url, {
    headers: {
      "Accept": "application/json"
    }
  });

  if (!response.ok) {
    throw new Error(`Request failed with ${response.status}`);
  }

  return await response.json();
}

function normalizeCountryData(payload) {
  const records = Array.isArray(payload)
    ? payload
    : payload?.data?.objects;

  if (!Array.isArray(records)) return [];

  return records
    .map(normalizeCountryRecord)
    .filter(country => country.name.common);
}

function normalizeCountryRecord(record) {
  const commonName = readPath(record, "name.common")
    || readPath(record, "names.common")
    || record["names.common"]
    || "";
  const officialName = readPath(record, "name.official")
    || readPath(record, "names.official")
    || record["names.official"]
    || commonName;
  const capitalNames = Array.isArray(record.capital)
    ? record.capital
    : Array.isArray(record.capitals)
      ? record.capitals.map(capital => capital?.name).filter(Boolean)
      : [];
  const coordinates = normalizeLatLng(record);
  const areaValue = readPath(record, "area.kilometers") ?? record.area ?? 0;
  const populationValue = record.population ?? 0;
  const flags = record.flags || {
    svg: record.flag?.url_svg || record["flag.url_svg"] || "",
    png: record.flag?.url_png || record["flag.url_png"] || "",
    alt: record.flag?.description || record["flag.description"] || ""
  };

  return {
    ...record,
    name: {
      ...(record.name || {}),
      common: commonName,
      official: officialName
    },
    cca2: record.cca2 || readPath(record, "codes.alpha_2") || record["codes.alpha_2"] || "",
    cca3: record.cca3 || readPath(record, "codes.alpha_3") || record["codes.alpha_3"] || "",
    ccn3: record.ccn3 || readPath(record, "codes.ccn3") || record["codes.ccn3"] || "",
    capital: capitalNames,
    latlng: coordinates,
    flags,
    population: Number.isFinite(Number(populationValue)) ? Number(populationValue) : 0,
    populationYear: record.populationYear || "",
    area: Number.isFinite(Number(areaValue)) ? Number(areaValue) : 0,
    region: record.region || "",
    subregion: record.subregion || ""
  };
}

function normalizeLatLng(record) {
  if (Array.isArray(record.latlng) && record.latlng.length >= 2) {
    return [Number(record.latlng[0]), Number(record.latlng[1])];
  }

  const coordinates = record.coordinates;
  if (coordinates && Number.isFinite(Number(coordinates.lat)) && Number.isFinite(Number(coordinates.lng))) {
    return [Number(coordinates.lat), Number(coordinates.lng)];
  }

  return [];
}

function readPath(source, path) {
  return path.split(".").reduce((value, key) => value?.[key], source);
}

function countriesFromAtlas(worldData) {
  const features = topojson.feature(worldData, worldData.objects.countries).features;
  return features
    .map(feature => {
      const name = getPolygonName(feature.properties);
      return {
        name: {
          common: name,
          official: name
        },
        cca2: "",
        cca3: "",
        ccn3: String(feature.id || "").padStart(3, "0"),
        capital: [],
        latlng: [],
        flags: {},
        population: 0,
        area: 0,
        region: "",
        subregion: ""
      };
    })
    .filter(country => country.name.common && country.name.common !== "Unknown");
}

function renderCountryPolygons(worldData) {
  const countries = topojson.feature(worldData, worldData.objects.countries).features;

  world
    .polygonsData(countries)
    .polygonAltitude(0.012)
    .polygonCapColor(country => isSaved(country) ? "rgba(244, 114, 87, 0.72)" : "rgba(45, 212, 191, 0.48)")
    .polygonSideColor(() => "rgba(15, 118, 110, 0.22)")
    .polygonStrokeColor(() => "rgba(255, 255, 255, 0.78)")
    .polygonsTransitionDuration(260)
    .polygonLabel(({ properties }) => `<strong>${getPolygonName(properties)}</strong>`)
    .onPolygonHover(country => {
      elements.globe.classList.toggle("is-hovering", Boolean(country));
    })
    .onPolygonClick(country => {
      if (!country) return;

      if (state.clickTimer) {
        clearTimeout(state.clickTimer);
        state.clickTimer = null;
        openPolygonCountry(country, true);
        return;
      }

      state.clickTimer = setTimeout(() => {
        openPolygonCountry(country, false);
        state.clickTimer = null;
      }, 260);
    });
}

async function openPolygonCountry(country, withImages) {
  const code = String(country.id || "").padStart(3, "0");
  const fallbackName = getPolygonName(country.properties);
  const countryData = findCountryByCode(code) || findCountry(fallbackName);

  if (countryData) {
    showCountry(countryData);
    focusCountry(countryData);
    if (withImages) loadCountryImages(countryData.name.common);
    return;
  }

  try {
    setLoading(true);
    const response = await fetch(`countries.php?code=${encodeURIComponent(code)}`);
    if (!response.ok) throw new Error("Country lookup failed");
    const data = normalizeCountryData(await response.json());
    if (!data[0]) throw new Error("Country not found");

    showCountry(data[0]);
    focusCountry(data[0]);
    if (withImages) loadCountryImages(data[0].name.common);
  } catch (error) {
    showToast("Country details unavailable.");
  } finally {
    setLoading(false);
  }
}

function renderCapitalMarkers() {
  const markers = state.allCountries
    .filter(country => Array.isArray(country.latlng))
    .map(country => ({
      lat: country.latlng[0],
      lng: country.latlng[1],
      country: country.name.common,
      capital: country.capital?.[0] || "N/A"
    }));

  world
    .pointsData(markers)
    .pointLat(marker => marker.lat)
    .pointLng(marker => marker.lng)
    .pointAltitude(0.025)
    .pointColor(() => "#f97316")
    .pointRadius(0.42)
    .pointLabel(marker => `<strong>${marker.capital}</strong><br>${marker.country}`);
}

function formatPopulation(country) {
  const value = Number(country?.population ?? 0);
  if (!Number.isFinite(value) || value <= 0) {
    return {
      label: "Current population",
      value: "Unavailable",
      note: ""
    };
  }

  const year = country.populationYear || new Date().getFullYear();
  return {
    label: "Current population",
    value: value.toLocaleString(),
    note: `(World Bank ${year} est.)`
  };
}

function showCountry(country) {
  state.selectedCountry = country;

  const name = country.name.common;
  const capital = country.capital?.[0] || "N/A";
  const population = formatPopulation(country);
  const area = country.area ? `${Number(country.area).toLocaleString()} km2` : "N/A";
  const flag = country.flags?.svg || country.flags?.png || "";
  const flagAlt = country.flags?.alt || `${name} flag`;
  const region = [country.region, country.subregion].filter(Boolean).join(" / ") || "N/A";
  const mapQuery = Array.isArray(country.latlng) ? country.latlng.join(",") : name;
  const mapUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(mapQuery)}`;
  const visitedLabel = state.visited.has(name) ? "Visited saved" : "Mark visited";
  const wishlistLabel = state.wishlist.has(name) ? "Wishlist saved" : "Add wishlist";

  elements.countryDetails.innerHTML = `
    <div class="country-title">
      ${flag ? `<img src="${escapeAttribute(flag)}" alt="${escapeAttribute(flagAlt)}">` : ""}
      <div>
        <p class="eyebrow">${escapeHtml(country.cca3 || "")}</p>
        <h2>${escapeHtml(name)}</h2>
      </div>
    </div>
    <dl class="country-facts">
      <div><dt>Capital</dt><dd>${escapeHtml(capital)}</dd></div>
      <div><dt>${escapeHtml(population.label)}</dt><dd>${population.value}${population.note ? ` <span class="fact-note">${escapeHtml(population.note)}</span>` : ""}</dd></div>
      <div><dt>Area</dt><dd>${area}</dd></div>
      <div><dt>Region</dt><dd>${escapeHtml(region)}</dd></div>
    </dl>
    <div class="country-actions">
      <button type="button" data-action="visited">${visitedLabel}</button>
      <button type="button" data-action="wishlist">${wishlistLabel}</button>
      <button type="button" data-action="images">Refresh photos</button>
      <a href="${escapeAttribute(mapUrl)}" target="_blank" rel="noreferrer">Map</a>
    </div>
    <section class="image-strip" id="imageStrip" aria-label="Recent street scenes">
      <h3 class="image-strip-heading">Recent street scenes</h3>
      <p class="muted">Loading street photos</p>
    </section>
  `;

  elements.infoBox.classList.add("is-visible");
  refreshPolygonColors();
  loadCountryImages(name);
}

async function loadCountryImages(countryName) {
  const imageStrip = document.getElementById("imageStrip");
  if (!imageStrip) return;

  const heading = imageStrip.querySelector(".image-strip-heading");
  imageStrip.innerHTML = heading
    ? heading.outerHTML + `<p class="muted">Loading street photos</p>`
    : `<h3 class="image-strip-heading">Recent street scenes</h3><p class="muted">Loading street photos</p>`;

  try {
    const country = state.selectedCountry;
    const capital = country?.capital?.[0] || "";
    const lat = Array.isArray(country?.latlng) ? country.latlng[0] : "";
    const lng = Array.isArray(country?.latlng) ? country.latlng[1] : "";
    const response = await fetch(
      `get_country_images.php?country=${encodeURIComponent(countryName)}&capital=${encodeURIComponent(capital)}&lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`
    );
    const data = await response.json();

    if (!response.ok || data.status !== "success") {
      throw new Error(data.message || "Image request failed");
    }

    const images = Array.isArray(data.images) ? data.images : [];
    const sourceNote = data.source === "wikimedia"
      ? "Recent street photos from Wikimedia Commons"
      : "Street map views from Mapbox";

    if (!images.length) {
      imageStrip.innerHTML = `
        <h3 class="image-strip-heading">Recent street scenes</h3>
        <p class="muted">No street photos found for this country.</p>
      `;
      return;
    }

    imageStrip.innerHTML = `
      <h3 class="image-strip-heading">Recent street scenes</h3>
      <p class="image-source-note">${escapeHtml(sourceNote)}</p>
      ${images.map(image => `
        <figure class="image-card">
          <img src="${escapeAttribute(image.src)}" alt="${escapeAttribute(image.alt || countryName)}" loading="lazy">
          <figcaption>${escapeHtml(image.caption || "Street scene")}</figcaption>
          ${image.attribution ? `<p class="image-attribution">${escapeHtml(image.attribution)}</p>` : ""}
        </figure>
      `).join("")}
    `;
  } catch (error) {
    imageStrip.innerHTML = `
      <h3 class="image-strip-heading">Recent street scenes</h3>
      <p class="muted">${escapeHtml(error.message || "Street photos unavailable.")}</p>
    `;
  }
}

async function loadSavedLists() {
  const [serverVisited, serverWishlist] = await Promise.all([
    fetchJson("get_countries.php"),
    fetchJson("get_wishlist.php")
  ]);

  const localVisited = readLocalList(STORAGE_KEYS.visited);
  const localWishlist = readLocalList(STORAGE_KEYS.wishlist);

  state.visited = new Set([...(Array.isArray(serverVisited) ? serverVisited : []), ...localVisited]);
  state.wishlist = new Set([...(Array.isArray(serverWishlist) ? serverWishlist : []), ...localWishlist]);
  renderSavedLists();
  refreshPolygonColors();
}

async function saveCountry(endpoint, collection, countryName, label) {
  if (collection.has(countryName)) {
    showToast(`${label} already saved.`);
    return;
  }

  try {
    const response = await fetch(endpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ country: countryName })
    });

    const result = await response.json();
    if (!response.ok || result.status === "error") {
      throw new Error(result.message || "Save failed");
    }

    collection.add(countryName);
    persistLocalList(collection === state.visited ? STORAGE_KEYS.visited : STORAGE_KEYS.wishlist, collection);
    renderSavedLists();
    showCountry(state.selectedCountry);
    showToast(`${label} saved.`);
  } catch (error) {
    collection.add(countryName);
    persistLocalList(collection === state.visited ? STORAGE_KEYS.visited : STORAGE_KEYS.wishlist, collection);
    renderSavedLists();
    showCountry(state.selectedCountry);
    showToast(error.message?.includes("Database unavailable") ? `${label} saved on this device.` : `Could not save ${label.toLowerCase()} to the server. Saved on this device.`);
  }
}

async function fetchJson(url) {
  try {
    const response = await fetch(url, {
      headers: {
        "Accept": "application/json"
      }
    });
    if (!response.ok) return [];
    return await response.json();
  } catch (error) {
    return [];
  }
}

function renderSavedLists() {
  if (elements.visitedList)  renderList(elements.visitedList,  [...state.visited].sort());
  if (elements.wishlistList) renderList(elements.wishlistList, [...state.wishlist].sort());
  if (elements.visitedCount)  elements.visitedCount.textContent  = state.visited.size;
  if (elements.wishlistCount) elements.wishlistCount.textContent = state.wishlist.size;
  refreshPolygonColors();
}

function renderList(list, countries) {
  if (!countries.length) {
    list.innerHTML = `<li class="empty-state">No countries saved</li>`;
    return;
  }

  list.innerHTML = countries.map(country => `
    <li class="saved-country">
      <button type="button" class="country-btn" data-country="${escapeAttribute(country)}">
        ${escapeHtml(country)}
      </button>
      <button type="button" class="remove-btn" data-country="${escapeAttribute(country)}" aria-label="Remove ${escapeAttribute(country)}">
        <span aria-hidden="true">&times;</span>
      </button>
    </li>
  `).join("");

  list.querySelectorAll(".country-btn").forEach(button => {
    button.addEventListener("click", () => {
      const country = findCountry(button.dataset.country);
      if (!country) return;
      showCountry(country);
      focusCountry(country);
    });
  });

  list.querySelectorAll(".remove-btn").forEach(button => {
    button.addEventListener("click", event => {
      event.stopPropagation();

      const countryName = button.dataset.country;
      const wasVisited = state.visited.has(countryName);
      const wasWishlisted = state.wishlist.has(countryName);

      state.visited.delete(countryName);
      state.wishlist.delete(countryName);

      persistLocalList(STORAGE_KEYS.visited, state.visited);
      persistLocalList(STORAGE_KEYS.wishlist, state.wishlist);

      renderSavedLists();
      showToast(`${countryName} removed.`);

      if (wasVisited) {
        fetch("remove_visited.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ country: countryName })
        }).catch(() => {});
      }
      if (wasWishlisted) {
        fetch("remove_wishlist.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ country: countryName })
        }).catch(() => {});
      }
    });
  });
}

function focusCountry(country) {
  if (!world || !Array.isArray(country.latlng)) return;

  world.controls().autoRotate = false;
  world.pointOfView({
    lat: country.latlng[0],
    lng: country.latlng[1],
    altitude: 1.55
  }, 1200);
}

function populateSearchSuggestions() {
  elements.suggestions.innerHTML = state.allCountries
    .map(country => `<option value="${escapeAttribute(country.name.common)}"></option>`)
    .join("");
}

function findCountry(query) {
  const needle = normalize(query);

  return state.allCountries.find(country => {
    const names = [
      country.name.common,
      country.name.official,
      country.cca2,
      country.cca3,
      country.ccn3
    ].filter(Boolean).map(normalize);

    return names.includes(needle) || names.some(name => name.startsWith(needle));
  });
}

function findCountryByCode(code) {
  const normalized = normalize(code);
  return state.allCountries.find(country => {
    return [country.cca2, country.cca3, country.ccn3]
      .filter(Boolean)
      .map(normalize)
      .includes(normalized);
  });
}

function getPolygonName(properties = {}) {
  return properties.name || properties.ADMIN || "Unknown";
}

function isSaved(country) {
  const countryData = findCountryByCode(String(country.id || "").padStart(3, "0")) || findCountry(getPolygonName(country.properties));
  if (!countryData) return false;
  return state.visited.has(countryData.name.common) || state.wishlist.has(countryData.name.common);
}

function refreshPolygonColors() {
  if (!world || !world.polygonsData().length) return;
  world.polygonCapColor(country => isSaved(country) ? "rgba(244, 114, 87, 0.72)" : "rgba(45, 212, 191, 0.48)");
}

function setupInstallPrompt() {
  window.addEventListener("beforeinstallprompt", event => {
    event.preventDefault();
    state.installPrompt = event;
    elements.installButton.hidden = false;
  });

  elements.installButton.addEventListener("click", async () => {
    if (!state.installPrompt) return;
    state.installPrompt.prompt();
    await state.installPrompt.userChoice;
    state.installPrompt = null;
    elements.installButton.hidden = true;
  });
}

function readLocalList(key) {
  try {
    const raw = window.localStorage.getItem(key);
    const list = JSON.parse(raw || "[]");
    return Array.isArray(list) ? list : [];
  } catch (error) {
    return [];
  }
}

function persistLocalList(key, collection) {
  try {
    window.localStorage.setItem(key, JSON.stringify([...collection].sort()));
  } catch (error) {
    // Ignore storage write failures and keep the in-memory state.
  }
}

function registerServiceWorker() {
  if (!("serviceWorker" in navigator)) return;

  window.addEventListener("load", () => {
    navigator.serviceWorker.register("service-worker.js").catch(() => {});
  });
}

function setLoading(isLoading) {
  elements.loading.classList.toggle("is-hidden", !isLoading);
}

function showToast(message) {
  elements.toast.textContent = message;
  elements.toast.classList.add("is-visible");
  window.clearTimeout(showToast.timeout);
  showToast.timeout = window.setTimeout(() => {
    elements.toast.classList.remove("is-visible");
  }, 2600);
}

function normalize(value) {
  return String(value || "").trim().toLowerCase();
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function escapeAttribute(value) {
  return escapeHtml(value);
}
