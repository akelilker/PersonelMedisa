/**
 * Post-deploy HTTP smoke checker for PersonelMedisa.
 * Requires SMOKE_BASE_URL; no credentials or live domain hardcoded.
 *
 * Usage:
 *   SMOKE_BASE_URL=https://example.invalid npm run smoke:live
 *   SMOKE_APP_PREFIX=/personelmedisa npm run smoke:live
 *
 * Optional authenticated read-only smoke (no production writes):
 *   SMOKE_AUTH_USERNAME=... SMOKE_AUTH_PASSWORD=... npm run smoke:live
 */

const REQUEST_TIMEOUT_MS = 15_000;
const HEAD_FALLBACK_STATUSES = new Set([403, 405, 501]);

const JS_ASSET_RE =
  /(?:src|href)=["']([^"']*\/assets\/index-[^"']+\.js)["']/gi;
const CSS_ASSET_RE =
  /(?:src|href)=["']([^"']*\/assets\/index-[^"']+\.css)["']/gi;

function printUsage() {
  console.error("PersonelMedisa post-deploy smoke checker");
  console.error("");
  console.error("Usage:");
  console.error("  SMOKE_BASE_URL=https://example.invalid npm run smoke:live");
  console.error("");
  console.error("Environment:");
  console.error("  SMOKE_BASE_URL      (required) Protocol + host, no trailing path slash");
  console.error("  SMOKE_APP_PREFIX    (optional) Default: /personelmedisa");
  console.error("  SMOKE_AUTH_USERNAME (optional) Enables authenticated read-only smoke");
  console.error("  SMOKE_AUTH_PASSWORD (optional) Required with SMOKE_AUTH_USERNAME");
}

function normalizeBaseUrl(raw) {
  const trimmed = raw.trim();
  if (!trimmed) {
    return "";
  }
  return trimmed.replace(/\/+$/, "");
}

function normalizeAppPrefix(raw) {
  const trimmed = raw.trim() || "/personelmedisa";
  const withLeading = trimmed.startsWith("/") ? trimmed : `/${trimmed}`;
  return withLeading.replace(/\/+$/, "") || "/";
}

function joinUrl(baseUrl, appPrefix, suffix = "") {
  const prefix = appPrefix === "/" ? "" : appPrefix;
  const path = `${prefix}${suffix}`;
  if (!path) {
    return baseUrl;
  }
  return `${baseUrl}${path.startsWith("/") ? path : `/${path}`}`;
}

function isJsonContentType(contentType) {
  if (!contentType) {
    return false;
  }
  return contentType.toLowerCase().includes("application/json");
}

function isHtmlContentType(contentType) {
  if (!contentType) {
    return false;
  }
  return contentType.toLowerCase().includes("text/html");
}

async function fetchWithTimeout(url, options = {}) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  try {
    const response = await fetch(url, {
      ...options,
      signal: controller.signal,
      redirect: "follow"
    });

    const contentType = response.headers.get("content-type") ?? "";
    let body = "";

    if (options.readBody !== false) {
      body = await response.text();
    }

    return {
      ok: response.ok,
      status: response.status,
      contentType,
      body,
      url: response.url,
      method: options.method ?? "GET"
    };
  } catch (error) {
    if (error instanceof Error && error.name === "AbortError") {
      throw new Error(`Request timed out after ${REQUEST_TIMEOUT_MS}ms`);
    }
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

function parseJsonBody(body) {
  if (!body || !body.trim()) {
    return null;
  }

  try {
    return JSON.parse(body);
  } catch {
    return null;
  }
}

function extractStatus(payload) {
  if (!payload || typeof payload !== "object") {
    return null;
  }

  if (payload.data && typeof payload.data === "object" && payload.data.status) {
    return payload.data.status;
  }

  if (typeof payload.status === "string") {
    return payload.status;
  }

  return null;
}

function extractService(payload) {
  if (!payload || typeof payload !== "object") {
    return null;
  }

  if (payload.data && typeof payload.data === "object" && payload.data.service) {
    return payload.data.service;
  }

  if (typeof payload.service === "string") {
    return payload.service;
  }

  return null;
}

function hasUnauthorizedCode(payload) {
  if (!payload || typeof payload !== "object") {
    return false;
  }

  const errors = payload.errors;
  if (!Array.isArray(errors)) {
    return false;
  }

  return errors.some(
    (entry) =>
      entry &&
      typeof entry === "object" &&
      typeof entry.code === "string" &&
      entry.code === "UNAUTHORIZED"
  );
}

function looksLikeHtml(body) {
  const sample = body.trim().slice(0, 2000).toLowerCase();
  return (
    sample.includes("<!doctype html") ||
    sample.includes("<html") ||
    sample.includes('id="root"') ||
    sample.includes("id='root'")
  );
}

function collectAssetPaths(body, pattern) {
  const paths = new Set();
  for (const match of body.matchAll(pattern)) {
    if (match[1]) {
      paths.add(match[1]);
    }
  }
  return [...paths];
}

function resolveAssetUrl(baseUrl, frontendUrl, assetPath) {
  if (/^https?:\/\//i.test(assetPath)) {
    return assetPath;
  }

  if (assetPath.startsWith("/")) {
    return `${baseUrl}${assetPath}`;
  }

  return new URL(assetPath, frontendUrl).toString();
}

function printFail(step, details) {
  console.error(`[FAIL] ${step}`);
  for (const [key, value] of Object.entries(details)) {
    console.error(`  ${key}: ${value}`);
  }
}

const failures = [];

function recordFailure(step, details) {
  failures.push(step);
  printFail(step, details);
}

async function checkApiHealth(baseUrl, appPrefix) {
  const step = "API health";
  const url = joinUrl(baseUrl, appPrefix, "/api/health");

  let result;
  try {
    result = await fetchWithTimeout(url, { method: "GET" });
  } catch (error) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 200 with JSON status ok",
      Got: error instanceof Error ? error.message : String(error),
      Hint: "API router veya network erisimi kontrol edin"
    });
    return;
  }

  const payload = parseJsonBody(result.body);
  const jsonLike = isJsonContentType(result.contentType) || payload !== null;

  if (result.status !== 200) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 200",
      Got: `HTTP ${result.status}, Content-Type: ${result.contentType || "(none)"}`,
      Hint: "api/.htaccess, api/public/index.php ve PHP error log kontrol edin"
    });
    return;
  }

  if (!jsonLike || payload === null) {
    recordFailure(step, {
      URL: url,
      Expected: "Parse edilebilir JSON",
      Got: `HTTP ${result.status}, Content-Type: ${result.contentType || "(none)"}`,
      Hint: "Health endpoint JSON donmeli"
    });
    return;
  }

  const status = extractStatus(payload);
  if (status !== "ok") {
    recordFailure(step, {
      URL: url,
      Expected: 'JSON status "ok"',
      Got: `status=${status ?? "(missing)"}`,
      Hint: "Health payload beklenen shape ile uyusmuyor"
    });
    return;
  }

  const service = extractService(payload);
  const serviceNote =
    service === "personelmedisa-api"
      ? ""
      : service
        ? ` (service=${service}, beklenen personelmedisa-api degil)`
        : " (service alani yok, status ok oldugu icin gecildi)";

  console.log(`[OK] ${step}${serviceNote}`);
}

async function checkApiAuthGuard(baseUrl, appPrefix) {
  const step = "API auth guard";
  const url = joinUrl(baseUrl, appPrefix, "/api/personeller");

  let result;
  try {
    result = await fetchWithTimeout(url, { method: "GET" });
  } catch (error) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 401 JSON UNAUTHORIZED",
      Got: error instanceof Error ? error.message : String(error),
      Hint: "API erisimi veya network kontrol edin"
    });
    return;
  }

  const payload = parseJsonBody(result.body);
  const jsonLike = isJsonContentType(result.contentType) || payload !== null;
  const htmlLike = isHtmlContentType(result.contentType) || looksLikeHtml(result.body);

  if (result.status === 200 && htmlLike) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 401 JSON UNAUTHORIZED",
      Got: `HTTP 200, Content-Type: ${result.contentType || "text/html"}`,
      Hint: "Kritik: API route SPA fallback'e dusuyor olabilir"
    });
    return;
  }

  if (result.status === 200 && jsonLike) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 401 JSON UNAUTHORIZED",
      Got: `HTTP 200 JSON`,
      Hint: "Kritik: auth guard calismiyor olabilir"
    });
    return;
  }

  if (result.status === 404) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 401 JSON UNAUTHORIZED",
      Got: `HTTP 404, Content-Type: ${result.contentType || "(none)"}`,
      Hint: "Route veya base path problemi olabilir"
    });
    return;
  }

  if (result.status === 500) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 401 JSON UNAUTHORIZED",
      Got: `HTTP 500, Content-Type: ${result.contentType || "(none)"}`,
      Hint: "API/config/PHP problemi olabilir"
    });
    return;
  }

  if (result.status !== 401) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 401 JSON UNAUTHORIZED",
      Got: `HTTP ${result.status}, Content-Type: ${result.contentType || "(none)"}`,
      Hint: "Token olmadan korumali endpoint 401 donmeli"
    });
    return;
  }

  if (!jsonLike || payload === null) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 401 with parse edilebilir JSON",
      Got: `HTTP 401, Content-Type: ${result.contentType || "(none)"}`,
      Hint: "Auth guard JSON hata donmeli"
    });
    return;
  }

  if (!hasUnauthorizedCode(payload)) {
    recordFailure(step, {
      URL: url,
      Expected: 'errors[].code === "UNAUTHORIZED"',
      Got: `HTTP 401 JSON without UNAUTHORIZED code`,
      Hint: "Auth guard response shape kontrol edin"
    });
    return;
  }

  console.log(`[OK] ${step}`);
}

async function checkFrontendRoot(baseUrl, appPrefix) {
  const step = "Frontend root";
  const url = joinUrl(baseUrl, appPrefix, "/");

  let result;
  try {
    result = await fetchWithTimeout(url, { method: "GET" });
  } catch (error) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 200 HTML shell",
      Got: error instanceof Error ? error.message : String(error),
      Hint: "Frontend deploy path ve .htaccess kontrol edin"
    });
    return { html: "" };
  }

  const bodyLower = result.body.toLowerCase();
  const hasHtmlMarker =
    bodyLower.includes("<!doctype html") ||
    bodyLower.includes("<div id=\"root\">") ||
    bodyLower.includes("<div id='root'>") ||
    bodyLower.includes("id=\"root\"") ||
    bodyLower.includes("id='root'");

  if (result.status !== 200) {
    recordFailure(step, {
      URL: url,
      Expected: "HTTP 200 HTML",
      Got: `HTTP ${result.status}, Content-Type: ${result.contentType || "(none)"}`,
      Hint: "public/.htaccess, VITE_APP_BASE_PATH ve FTP hedef klasor kontrol edin"
    });
    return { html: result.body };
  }

  if (!hasHtmlMarker) {
    recordFailure(step, {
      URL: url,
      Expected: "HTML body with doctype or #root",
      Got: `HTTP 200, Content-Type: ${result.contentType || "(none)"}`,
      Hint: "index.html deploy edilmemis veya yanlis dosya donuyor olabilir"
    });
    return { html: result.body };
  }

  const contentTypeNote = isHtmlContentType(result.contentType)
    ? ""
    : ` (Content-Type: ${result.contentType || "(none)"}, HTML marker bulundu)`;

  console.log(`[OK] ${step}${contentTypeNote}`);
  return { html: result.body, frontendUrl: url };
}

async function checkAssetUrl(assetUrl, displayPath) {
  const step = `Asset: ${displayPath}`;

  let headResult;
  try {
    headResult = await fetchWithTimeout(assetUrl, {
      method: "HEAD",
      readBody: false
    });
  } catch {
    headResult = null;
  }

  const shouldFallback =
    !headResult ||
    HEAD_FALLBACK_STATUSES.has(headResult.status) ||
    headResult.status < 200 ||
    headResult.status >= 400;

  if (!shouldFallback && headResult.status === 200) {
    console.log(
      `[OK] ${step} (HEAD 200, Content-Type: ${headResult.contentType || "(none)"})`
    );
    return;
  }

  let getResult;
  try {
    getResult = await fetchWithTimeout(assetUrl, { method: "GET" });
  } catch (error) {
    recordFailure(step, {
      URL: assetUrl,
      Expected: "HTTP 200",
      Got: error instanceof Error ? error.message : String(error),
      Hint: "Partial FTP upload veya yanlis asset path olabilir"
    });
    return;
  }

  if (getResult.status !== 200) {
    const methodUsed = headResult ? `HEAD ${headResult.status}, GET ${getResult.status}` : `GET ${getResult.status}`;
    recordFailure(step, {
      URL: assetUrl,
      Expected: "HTTP 200",
      Got: methodUsed,
      Hint: "index.html asset path ile canli dosya uyusmuyor olabilir"
    });
    return;
  }

  const fallbackNote = headResult ? " (HEAD fallback -> GET)" : "";
  console.log(
    `[OK] ${step}${fallbackNote} (GET 200, Content-Type: ${getResult.contentType || "(none)"})`
  );
}

async function checkBundleAssets(baseUrl, frontendUrl, html) {
  const step = "Bundle assets";

  const jsAssets = collectAssetPaths(html, JS_ASSET_RE);
  const cssAssets = collectAssetPaths(html, CSS_ASSET_RE);

  if (jsAssets.length === 0) {
    recordFailure(step, {
      URL: frontendUrl,
      Expected: "En az 1 /assets/index-*.js path",
      Got: "JS asset bulunamadi",
      Hint: "index.html Vite build ciktisi ile uyusmuyor olabilir"
    });
    return;
  }

  for (const assetPath of jsAssets) {
    const assetUrl = resolveAssetUrl(baseUrl, frontendUrl, assetPath);
    await checkAssetUrl(assetUrl, assetPath);
  }

  for (const assetPath of cssAssets) {
    const assetUrl = resolveAssetUrl(baseUrl, frontendUrl, assetPath);
    await checkAssetUrl(assetUrl, assetPath);
  }
}

function extractToken(payload) {
  if (!payload || typeof payload !== "object") {
    return null;
  }
  const data = payload.data && typeof payload.data === "object" ? payload.data : payload;
  if (typeof data.token === "string" && data.token.trim()) {
    return data.token.trim();
  }
  return null;
}

async function checkAuthenticatedReadOnly(baseUrl, appPrefix) {
  const step = "Authenticated read-only smoke";
  const username = (process.env.SMOKE_AUTH_USERNAME ?? "").trim();
  const password = process.env.SMOKE_AUTH_PASSWORD ?? "";

  if (!username && !password) {
    console.log(`[SKIP] ${step} — SMOKE_AUTH_USERNAME/PASSWORD unset (read-only anonymous smoke only)`);
    return;
  }

  if (!username || !password) {
    recordFailure(step, {
      Expected: "Both SMOKE_AUTH_USERNAME and SMOKE_AUTH_PASSWORD",
      Got: username ? "password missing" : "username missing",
      Hint: "Authenticated smoke requires both env vars; never commit credentials"
    });
    return;
  }

  const loginUrl = joinUrl(baseUrl, appPrefix, "/api/auth/login");
  let loginResponse;
  try {
    loginResponse = await fetchWithTimeout(loginUrl, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ username, password })
    });
  } catch (error) {
    recordFailure(step, {
      URL: loginUrl,
      Expected: "HTTP 200 login JSON with token",
      Got: error instanceof Error ? error.message : String(error)
    });
    return;
  }

  const loginPayload = parseJsonBody(loginResponse.body);
  const token = extractToken(loginPayload);
  if (!loginResponse.ok || !token) {
    recordFailure(step, {
      URL: loginUrl,
      Expected: "HTTP 200 + data.token",
      Got: `HTTP ${loginResponse.status}; token=${token ? "present" : "missing"}`,
      Hint: "Use a dedicated non-prod-write test account; do not use personal credentials in logs"
    });
    return;
  }

  const personellerUrl = joinUrl(baseUrl, appPrefix, "/api/personeller?page=1&limit=5");
  let personellerResponse;
  try {
    personellerResponse = await fetchWithTimeout(personellerUrl, {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token}`
      }
    });
  } catch (error) {
    recordFailure(step, {
      URL: personellerUrl,
      Expected: "HTTP 200 authenticated personeller read",
      Got: error instanceof Error ? error.message : String(error)
    });
    return;
  }

  if (!personellerResponse.ok || !isJsonContentType(personellerResponse.contentType)) {
    recordFailure(step, {
      URL: personellerUrl,
      Expected: "HTTP 200 JSON personeller list/page",
      Got: `HTTP ${personellerResponse.status}; content-type=${personellerResponse.contentType || "n/a"}`
    });
    return;
  }

  // Explicit non-write assertion: this smoke never calls POST/PUT/PATCH/DELETE after login.
  console.log(`[OK] ${step} (login + GET /api/personeller; no write calls)`);
}

async function main() {
  const rawBaseUrl = process.env.SMOKE_BASE_URL;
  if (!rawBaseUrl || !rawBaseUrl.trim()) {
    printUsage();
    process.exit(1);
  }

  const baseUrl = normalizeBaseUrl(rawBaseUrl);
  const appPrefix = normalizeAppPrefix(process.env.SMOKE_APP_PREFIX ?? "/personelmedisa");

  console.log("PersonelMedisa post-deploy smoke");
  console.log(`Base URL: ${baseUrl}`);
  console.log(`App prefix: ${appPrefix}`);
  console.log("");

  await checkApiHealth(baseUrl, appPrefix);
  await checkApiAuthGuard(baseUrl, appPrefix);
  const frontend = await checkFrontendRoot(baseUrl, appPrefix);

  if (frontend.html) {
    await checkBundleAssets(baseUrl, frontend.frontendUrl ?? joinUrl(baseUrl, appPrefix, "/"), frontend.html);
  }

  await checkAuthenticatedReadOnly(baseUrl, appPrefix);

  console.log("");
  if (failures.length > 0) {
    console.error(`Smoke result: FAIL (${failures.length} check(s) failed)`);
    process.exit(1);
  }

  console.log("Smoke result: OK");
  process.exit(0);
}

main().catch((error) => {
  console.error("[FAIL] Unexpected error");
  console.error(`  Got: ${error instanceof Error ? error.message : String(error)}`);
  process.exit(1);
});
