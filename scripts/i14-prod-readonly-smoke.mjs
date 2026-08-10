/**
 * I14 production READ-ONLY acceptance smoke.
 * Uses SMOKE_BASE_URL + optional SMOKE_AUTH_* (same as post-deploy-smoke).
 * Never issues business writes after login (POST login only).
 *
 * Usage:
 *   SMOKE_BASE_URL=https://www.karmotors.com.tr SMOKE_APP_PREFIX=/personelmedisa \
 *   SMOKE_AUTH_USERNAME=... SMOKE_AUTH_PASSWORD=... node scripts/i14-prod-readonly-smoke.mjs
 */

const REQUEST_TIMEOUT_MS = 20_000;

const writeCounts = {
  PERSONEL_PUT: 0,
  SUREC_POST: 0,
  WAGE_WRITE: 0,
  DOCUMENT_WRITE: 0,
  ZIMMET_WRITE: 0,
  BORDRO_KAPSAM_WRITE: 0,
  REVIZYON_CREATE: 0,
  WEEKLY_CLOSE_FINAL_POST: 0,
  YONETIM_USER_WRITE: 0,
  OTHER_WRITE: 0
};

const evidence = {
  login: null,
  smokeRead: null,
  spaRoutes: {},
  apiGets: {},
  yonetimUsers: null,
  writeCounts,
  failures: []
};

function fail(step, detail) {
  evidence.failures.push({ step, detail });
  console.error(`[FAIL] ${step}`, detail);
}

function ok(step, detail) {
  console.log(`[OK] ${step}${detail ? ` — ${detail}` : ""}`);
}

function normalizeBaseUrl(raw) {
  return String(raw || "").trim().replace(/\/+$/, "");
}

function normalizeAppPrefix(raw) {
  const trimmed = (raw || "/personelmedisa").trim() || "/personelmedisa";
  const withLeading = trimmed.startsWith("/") ? trimmed : `/${trimmed}`;
  return withLeading.replace(/\/+$/, "") || "/";
}

function joinUrl(baseUrl, appPrefix, suffix = "") {
  const prefix = appPrefix === "/" ? "" : appPrefix;
  const path = `${prefix}${suffix}`;
  if (!path) return baseUrl;
  return `${baseUrl}${path.startsWith("/") ? path : `/${path}`}`;
}

async function fetchWithTimeout(url, options = {}) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  try {
    const response = await fetch(url, {
      ...options,
      signal: controller.signal,
      redirect: "manual"
    });
    const text = await response.text();
    return {
      status: response.status,
      contentType: response.headers.get("content-type") || "",
      location: response.headers.get("location") || "",
      body: text,
      ok: response.ok
    };
  } finally {
    clearTimeout(timeout);
  }
}

function classifyWrite(method, path) {
  const m = method.toUpperCase();
  if (m === "GET" || m === "HEAD" || m === "OPTIONS") return null;
  // login POST is allowed once before counters start
  if (m === "POST" && /\/api\/auth\/login\/?$/.test(path)) return null;
  if (m === "PUT" && /\/api\/personeller\//.test(path)) return "PERSONEL_PUT";
  if (m === "POST" && /\/api\/surec/.test(path)) return "SUREC_POST";
  if (/\/api\/.*ucret/.test(path) && m !== "GET") return "WAGE_WRITE";
  if (/\/api\/.*belge/.test(path) && m !== "GET") return "DOCUMENT_WRITE";
  if (/\/api\/.*zimmet/.test(path) && m !== "GET") return "ZIMMET_WRITE";
  if (/\/api\/.*bordro.*kapsam|\/api\/bordro-hazirlik\/.*kapsam/.test(path) && m !== "GET") {
    return "BORDRO_KAPSAM_WRITE";
  }
  if (/\/api\/.*revizyon/.test(path) && (m === "POST" || m === "PUT" || m === "PATCH")) {
    return "REVIZYON_CREATE";
  }
  if (/\/api\/haftalik-kapanis/.test(path) && m === "POST") return "WEEKLY_CLOSE_FINAL_POST";
  if (/\/api\/yonetim\/kullanicilar/.test(path) && m !== "GET") return "YONETIM_USER_WRITE";
  return "OTHER_WRITE";
}

async function trackedFetch(url, options = {}) {
  const method = (options.method || "GET").toUpperCase();
  const path = (() => {
    try {
      return new URL(url).pathname;
    } catch {
      return url;
    }
  })();
  const bucket = classifyWrite(method, path);
  if (bucket) {
    writeCounts[bucket] += 1;
  }
  return fetchWithTimeout(url, options);
}

function extractToken(payload) {
  if (!payload || typeof payload !== "object") return null;
  const data = payload.data && typeof payload.data === "object" ? payload.data : payload;
  return typeof data.token === "string" && data.token.trim() ? data.token.trim() : null;
}

function parseJson(body) {
  try {
    return JSON.parse(body);
  } catch {
    return null;
  }
}

async function main() {
  const baseUrl = normalizeBaseUrl(process.env.SMOKE_BASE_URL);
  const appPrefix = normalizeAppPrefix(process.env.SMOKE_APP_PREFIX);
  const username = (process.env.SMOKE_AUTH_USERNAME || "").trim();
  const password = process.env.SMOKE_AUTH_PASSWORD || "";

  if (!baseUrl) {
    console.error("SMOKE_BASE_URL required");
    process.exit(1);
  }
  if (!username || !password) {
    console.error("SMOKE_AUTH_USERNAME and SMOKE_AUTH_PASSWORD required for I14 prod smoke");
    process.exit(1);
  }

  console.log("I14 production READ-ONLY smoke");
  console.log(`Base URL: ${baseUrl}`);
  console.log(`App prefix: ${appPrefix}`);
  console.log("");

  // Anonymous SPA shells
  const spaPaths = [
    "/",
    "/kayit",
    "/personeller/1",
    "/raporlar",
    "/raporlar?view=aylik-kapanis",
    "/raporlar?panel=donem-kapanis",
    "/raporlar?panel=etki-adayi",
    "/raporlar?panel=maas-hesaplama",
    "/raporlar?panel=bordro-hazirlik",
    "/raporlar?panel=bordro-hazirlik&tab=personel-kapsam&personelId=1",
    "/raporlar?panel=invalid-panel-xyz",
    "/revizyon-merkezi",
    "/haftalik-kapanis"
  ];

  for (const path of spaPaths) {
    const url = joinUrl(baseUrl, appPrefix, path);
    const res = await trackedFetch(url, { method: "GET", headers: { Accept: "text/html" } });
    const redirected =
      res.status >= 300 &&
      res.status < 400 &&
      /haftalik-kapanis\/revizyonlar/.test(res.location || "");
    const okStatus = res.status === 200 || redirected;
    evidence.spaRoutes[path] = {
      status: res.status,
      location: res.location || null,
      redirectedToRevizyon: redirected
    };
    if (!okStatus) {
      fail(`SPA ${path}`, `HTTP ${res.status}`);
    } else if (path === "/revizyon-merkezi" && !redirected && res.status === 200) {
      // SPA may serve index for all routes; accept 200 shell
      ok(`SPA ${path}`, "shell 200 (client-side redirect expected)");
    } else {
      ok(`SPA ${path}`, redirected ? `redirect ${res.location}` : `HTTP ${res.status}`);
    }
  }

  // Login (only allowed write-like call)
  const loginUrl = joinUrl(baseUrl, appPrefix, "/api/auth/login");
  const loginRes = await trackedFetch(loginUrl, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ username, password })
  });
  const loginJson = parseJson(loginRes.body);
  const token = extractToken(loginJson);
  evidence.login = { status: loginRes.status, tokenPresent: Boolean(token) };
  if (!loginRes.ok || !token) {
    fail("login", `HTTP ${loginRes.status}; token=${token ? "yes" : "no"}`);
    finish();
    return;
  }
  ok("login", "token present");

  const authHeaders = {
    Accept: "application/json",
    Authorization: `Bearer ${token}`
  };

  // smoke-read contract
  const smokeUrl = joinUrl(baseUrl, appPrefix, "/api/auth/smoke-read");
  const smokeRes = await trackedFetch(smokeUrl, { method: "GET", headers: authHeaders });
  const smokeJson = parseJson(smokeRes.body);
  const smokeData =
    smokeJson && typeof smokeJson === "object"
      ? smokeJson.data && typeof smokeJson.data === "object"
        ? smokeJson.data
        : smokeJson
      : null;
  evidence.smokeRead = {
    status: smokeRes.status,
    role: smokeData?.role ?? null,
    scope_type: smokeData?.scope_type ?? null,
    scope_count: smokeData?.scope_count ?? null,
    read_only: smokeData?.read_only ?? null
  };
  if (
    !smokeRes.ok ||
    smokeData?.role !== "AUTH_SMOKE_READONLY" ||
    smokeData?.scope_type !== "SINGLE_BRANCH" ||
    smokeData?.scope_count !== 1
  ) {
    fail("auth/smoke-read", JSON.stringify(evidence.smokeRead));
  } else {
    ok("auth/smoke-read", "AUTH_SMOKE_READONLY SINGLE_BRANCH");
  }

  // Personel IDs 1-4 GET (role may 403 — record, do not write)
  for (const id of [1, 2, 3, 4]) {
    const url = joinUrl(baseUrl, appPrefix, `/api/personeller/${id}`);
    const res = await trackedFetch(url, { method: "GET", headers: authHeaders });
    evidence.apiGets[`personeller/${id}`] = { status: res.status };
    if (res.status === 200 || res.status === 403 || res.status === 404) {
      ok(`GET personeller/${id}`, `HTTP ${res.status}`);
    } else {
      fail(`GET personeller/${id}`, `HTTP ${res.status}`);
    }
  }

  // Yönetim users GET — may be forbidden for smoke role
  {
    const url = joinUrl(baseUrl, appPrefix, "/api/yonetim/kullanicilar");
    const res = await trackedFetch(url, { method: "GET", headers: authHeaders });
    const json = parseJson(res.body);
    let hasVarsayilanField = false;
    if (res.status === 200 && json) {
      const rows = Array.isArray(json?.data)
        ? json.data
        : Array.isArray(json)
          ? json
          : Array.isArray(json?.data?.items)
            ? json.data.items
            : [];
      if (rows[0] && Object.prototype.hasOwnProperty.call(rows[0], "varsayilan_sube_id")) {
        hasVarsayilanField = true;
      }
      // Do not log usernames / PII
      evidence.yonetimUsers = {
        status: 200,
        rowCount: rows.length,
        varsayilan_sube_id_field: hasVarsayilanField,
        classification: "AUTHORIZED_READ"
      };
      ok(
        "GET yonetim/kullanicilar",
        `200 rows=${rows.length} varsayilan_field=${hasVarsayilanField}`
      );
    } else if (res.status === 401 || res.status === 403) {
      evidence.yonetimUsers = {
        status: res.status,
        classification: "DEFERRED_CREDENTIAL_UNAVAILABLE"
      };
      ok("GET yonetim/kullanicilar", `HTTP ${res.status} (smoke role; deferred)`);
    } else {
      evidence.yonetimUsers = { status: res.status, classification: "UNEXPECTED" };
      fail("GET yonetim/kullanicilar", `HTTP ${res.status}`);
    }
  }

  // Health after auth still ok
  {
    const url = joinUrl(baseUrl, appPrefix, "/api/health");
    const res = await trackedFetch(url, { method: "GET", headers: { Accept: "application/json" } });
    evidence.apiGets.health = { status: res.status };
    if (res.status === 200) ok("GET health", "200");
    else fail("GET health", `HTTP ${res.status}`);
  }

  finish();
}

function finish() {
  const totalWrites = Object.values(writeCounts).reduce((a, b) => a + b, 0);
  console.log("");
  console.log("WRITE_GUARD", JSON.stringify(writeCounts));
  console.log("EVIDENCE_SUMMARY", JSON.stringify({
    login: evidence.login,
    smokeRead: evidence.smokeRead,
    yonetimUsers: evidence.yonetimUsers,
    spaRouteCount: Object.keys(evidence.spaRoutes).length,
    apiGetCount: Object.keys(evidence.apiGets).length,
    failures: evidence.failures.length,
    totalBusinessWrites: totalWrites
  }));

  if (totalWrites !== 0) {
    fail("write-guard", `unexpected business writes: ${totalWrites}`);
  } else {
    ok("write-guard", "0 business writes");
  }

  if (evidence.failures.length > 0) {
    console.error(`I14 prod smoke: FAIL (${evidence.failures.length})`);
    process.exit(1);
  }
  console.log("I14 prod smoke: PASS");
  process.exit(0);
}

main().catch((err) => {
  console.error("[FAIL] unexpected", err instanceof Error ? err.message : String(err));
  process.exit(1);
});
