/**
 * Deterministic path normalization for API parity comparisons.
 */

const HTTP_METHODS = new Set(["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "HEAD"]);

/** Named semantic param aliases → canonical segment token */
const NAMED_PARAM_ALIASES = new Map([
  ["id", ":id"],
  ["surecId", ":id"],
  ["surec_id", ":id"],
  ["bildirimId", ":bildirimId"],
  ["bildirim_id", ":bildirimId"],
  ["personelId", ":personelId"],
  ["personel_id", ":personelId"],
  ["ucretId", ":id"],
  ["ucret_id", ":id"],
  ["talepId", ":id"],
  ["talep_id", ":id"],
  ["kapanisId", ":id"],
  ["kapanis_id", ":id"],
  ["subeId", ":id"],
  ["sube_id", ":id"],
  ["kullaniciId", ":id"],
  ["kullanici_id", ":id"],
  ["donem", ":donem"],
  ["tarih", ":tarih"],
  ["tip", ":tip"],
  ["yil", ":period"],
  ["ay", ":period"]
]);

/**
 * @param {string} method
 * @returns {string}
 */
export function normalizeMethod(method) {
  const upper = String(method ?? "GET").trim().toUpperCase();
  return HTTP_METHODS.has(upper) ? upper : upper || "GET";
}

/**
 * Strip query, base URL, /api prefix, trailing/double slashes.
 * @param {string} rawPath
 * @returns {string}
 */
export function stripPathNoise(rawPath) {
  let path = String(rawPath ?? "").trim();
  if (!path) {
    return "/";
  }

  // Absolute URL → pathname
  if (/^https?:\/\//i.test(path)) {
    try {
      path = new URL(path).pathname;
    } catch {
      // keep raw
    }
  }

  // Drop query/hash
  const q = path.indexOf("?");
  if (q >= 0) {
    path = path.slice(0, q);
  }
  const h = path.indexOf("#");
  if (h >= 0) {
    path = path.slice(0, h);
  }

  // Known app/API prefixes
  path = path.replace(/^\/personelmedisa\/api(?=\/|$)/i, "");
  path = path.replace(/^\/api(?=\/|$)/i, "");

  if (!path.startsWith("/")) {
    path = `/${path}`;
  }

  path = path.replace(/\/{2,}/g, "/");
  if (path.length > 1 && path.endsWith("/")) {
    path = path.slice(0, -1);
  }

  return path || "/";
}

/**
 * Map a single path segment to a normalized param token when dynamic.
 * @param {string} segment
 * @returns {string}
 */
export function normalizeSegment(segment) {
  if (!segment) {
    return segment;
  }

  // Express / OpenAPI style
  if (segment.startsWith(":")) {
    const name = segment.slice(1);
    return NAMED_PARAM_ALIASES.get(name) ?? (name === "date" ? ":tarih" : `:${name}`);
  }

  // Brace style {id}
  const brace = segment.match(/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/);
  if (brace) {
    return NAMED_PARAM_ALIASES.get(brace[1]) ?? `:${brace[1]}`;
  }

  // Template literal residue ${id} or encodeURIComponent(...)
  const tmpl = segment.match(/^\$\{([A-Za-z_][A-Za-z0-9_]*)\}$/);
  if (tmpl) {
    return NAMED_PARAM_ALIASES.get(tmpl[1]) ?? `:${tmpl[1]}`;
  }

  const enc = segment.match(/^\$\{encodeURIComponent\(([A-Za-z_][A-Za-z0-9_]*)\)\}$/);
  if (enc) {
    return NAMED_PARAM_ALIASES.get(enc[1]) ?? `:${enc[1]}`;
  }

  // Numeric literal id
  if (/^\d+$/.test(segment)) {
    return ":id";
  }

  // ISO date
  if (/^\d{4}-\d{2}-\d{2}$/.test(segment)) {
    return ":tarih";
  }

  // YYYY-MM period
  if (/^\d{4}-\d{2}$/.test(segment)) {
    return ":donem";
  }

  return segment;
}

/**
 * Convert a PHP/JS regex path fragment into normalized path segments.
 * Examples:
 *   /surecler/(\d+)           → /surecler/:id
 *   /gunluk-puantaj/(\d+)/([^/]+) → /gunluk-puantaj/:personelId/:tarih
 * @param {string} pattern
 * @returns {string}
 */
export function normalizeRegexPath(pattern) {
  let p = String(pattern ?? "");
  // Strip anchors and delimiters leftovers
  p = p.replace(/^\^/, "").replace(/\$$/, "");
  p = p.replace(/\\([./?+*()|[\]{}\\])/g, "$1");

  // Optional group ucret(?:ler)? → ucretler (canonical FE form)
  p = p.replace(/ucret\(\?:ler\)\?/g, "ucretler");

  // Alternation action groups → keep as-is for expansion elsewhere; here replace with :tip
  p = p.replace(/\((?:[A-Za-z0-9_-]+\|)+[A-Za-z0-9_-]+\)/g, ":tip");

  // Capture groups
  p = p.replace(/\(\\d\+\)/g, ":id");
  p = p.replace(/\[0-9\]\+/g, ":id");
  p = p.replace(/\\d\+/g, ":id");

  // Date-ish or free segment after personel context is handled segment-wise below
  p = p.replace(/\(\[\^\/\]\+\)/g, ":seg");
  p = p.replace(/\(\.\+\)/g, ":seg");
  p = p.replace(/\([^)]+\)/g, ":seg");

  return normalizePath(p);
}

/**
 * Normalize any path form to a canonical comparison key path.
 * @param {string} rawPath
 * @returns {string}
 */
export function normalizePath(rawPath) {
  const stripped = stripPathNoise(rawPath);
  const segments = stripped.split("/").filter((s, i) => (i === 0 ? false : true) || s.length > 0);
  // split("/") on "/a/b" → ["", "a", "b"]; drop empties
  const parts = stripped === "/" ? [] : stripped.split("/").filter(Boolean);

  const normalized = parts.map((seg, index) => {
    // Contextual: second segment of gunluk-puantaj is date; first dynamic is personelId
    if (parts[0] === "gunluk-puantaj") {
      if (index === 0) return "gunluk-puantaj";
      if (index === 1) {
        if (seg === ":seg" || seg === ":id" || /^\d+$/.test(seg) || seg.startsWith(":")) {
          return ":personelId";
        }
      }
      if (index === 2) {
        if (seg === ":seg" || seg === ":id" || seg.startsWith(":") || seg.includes("${")) {
          return ":tarih";
        }
        return normalizeSegment(seg) === ":id" ? ":tarih" : normalizeSegment(seg);
      }
    }

    // personeller/:personelId/...
    if (parts[0] === "personeller" && index === 1) {
      const n = normalizeSegment(seg);
      if (n === ":id" || n === ":seg") return ":personelId";
      return n;
    }

    // bildirimler/:bildirimId/...
    if (parts[0] === "bildirimler" && index === 1) {
      const n = normalizeSegment(seg);
      if (n === ":id" || n === ":seg") return ":bildirimId";
      return n;
    }

    if (seg === ":seg") {
      // Prefer :id for trailing resource ids
      return ":id";
    }

    return normalizeSegment(seg);
  });

  return `/${normalized.join("/")}`.replace(/\/{2,}/g, "/") || "/";
}

/**
 * Normalize a template-literal path from endpoints.ts / api.ts.
 * @param {string} template
 * @returns {string}
 */
export function normalizeTemplatePath(template) {
  let t = String(template ?? "");
  t = t.replace(/\$\{encodeURIComponent\(([A-Za-z_][A-Za-z0-9_]*)\)\}/g, (_, name) => `\${${name}}`);
  t = t.replace(/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/g, (_, name) => {
    return NAMED_PARAM_ALIASES.get(name) ?? `:${name}`;
  });
  return normalizePath(t);
}

/**
 * @param {string} method
 * @param {string} path
 * @returns {string}
 */
export function parityKey(method, path) {
  return `${normalizeMethod(method)} ${normalizePath(path)}`;
}

export { HTTP_METHODS, NAMED_PARAM_ALIASES };
