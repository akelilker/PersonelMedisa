/**
 * Classify (method, normalizedPath) across client / demo / e2e / php layers.
 */

import { findAllowlistEntry } from "./allowlist.mjs";
import { normalizePath } from "./normalize.mjs";

/**
 * @typedef {"FULL_PARITY"|"CLIENT_ONLY"|"DEMO_MOCK_ONLY"|"E2E_MOCK_ONLY"|"MOCK_ONLY"|"PHP_ONLY"|"CLIENT_AND_MOCK_NO_PHP"|"CLIENT_AND_PHP_NO_DEMO"|"CLIENT_AND_PHP_NO_E2E"|"METHOD_DRIFT"|"PATH_DRIFT"|"ALLOWLISTED_DEFERRED"|"ALLOWLISTED_KNOWN_GAP"} Classification
 */

/**
 * @typedef {{
 *   method: string,
 *   normalizedPath: string,
 *   classification: Classification,
 *   severity: "fatal"|"warning"|"info",
 *   client?: object,
 *   demo?: object,
 *   e2e?: object,
 *   php?: object,
 *   allowlist?: object,
 *   detail?: string
 * }} ClassifiedEndpoint
 */

/**
 * Parametric path cover: `/raporlar/:id` covers `/raporlar/bildirim`.
 * @param {string} template
 * @param {string} concrete
 */
export function pathCovers(template, concrete) {
  const t = normalizePath(template).split("/").filter(Boolean);
  const c = normalizePath(concrete).split("/").filter(Boolean);
  if (t.length !== c.length) return false;
  for (let i = 0; i < t.length; i++) {
    if (t[i].startsWith(":")) continue;
    if (t[i] !== c[i]) return false;
  }
  return true;
}

/**
 * @param {{ method: string, normalizedPath: string }[]} records
 * @param {string} method
 * @param {string} path
 */
function findCovering(records, method, path) {
  return (
    records.find((r) => r.method === method && r.normalizedPath === path) ||
    // Only template→concrete: PHP `/raporlar/:id` covers client `/raporlar/bildirim`
    records.find((r) => r.method === method && pathCovers(r.normalizedPath, path))
  );
}

/**
 * @param {{
 *   client: { method: string, normalizedPath: string, file?: string, symbol?: string, line?: number }[],
 *   demo: { method: string, normalizedPath: string, file?: string, line?: number }[],
 *   e2e: { method: string, normalizedPath: string, file?: string, line?: number }[],
 *   php: { method: string, normalizedPath: string, file?: string, symbol?: string, line?: number }[],
 *   allowlist: import('./allowlist.mjs').AllowlistEntry[]
 * }} input
 */
export function classifyParity(input) {
  const clientList = input.client;
  const demoList = input.demo.filter((r) => !r.normalizedPath.endsWith("/*"));
  const e2eList = input.e2e.filter((r) => !r.normalizedPath.endsWith("/*"));
  const phpList = input.php;

  /** Build universe of comparison keys from all concrete records */
  const keySet = new Set();
  for (const r of [...clientList, ...demoList, ...e2eList, ...phpList]) {
    keySet.add(`${r.method} ${r.normalizedPath}`);
  }

  /** @type {ClassifiedEndpoint[]} */
  const results = [];

  for (const key of [...keySet].sort()) {
    const [method, ...pathParts] = key.split(" ");
    const normalizedPath = pathParts.join(" ");

    const client =
      clientList.find((r) => r.method === method && r.normalizedPath === normalizedPath) ||
      findCovering(clientList, method, normalizedPath);
    const demo =
      demoList.find((r) => r.method === method && r.normalizedPath === normalizedPath) ||
      findCovering(demoList, method, normalizedPath);
    const e2e =
      e2eList.find((r) => r.method === method && r.normalizedPath === normalizedPath) ||
      findCovering(e2eList, method, normalizedPath);
    const php =
      phpList.find((r) => r.method === method && r.normalizedPath === normalizedPath) ||
      findCovering(phpList, method, normalizedPath);

    // Skip redundant parametric-only keys when a concrete client key already represents them
    // (e.g. PHP GET /raporlar/:id when client has GET /raporlar/bildirim)
    const isParametric = normalizedPath.split("/").some((s) => s.startsWith(":"));
    const hasConcreteClientSibling =
      isParametric &&
      !clientList.some((r) => r.method === method && r.normalizedPath === normalizedPath) &&
      clientList.some(
        (r) => r.method === method && pathCovers(normalizedPath, r.normalizedPath)
      );
    if (hasConcreteClientSibling && !clientList.some((r) => r.normalizedPath === normalizedPath)) {
      // Still emit PHP_ONLY only if nothing else — skip to avoid double-count
      continue;
    }

    const exactClient = clientList.some(
      (r) => r.method === method && r.normalizedPath === normalizedPath
    );
    const exactDemo = demoList.some(
      (r) => r.method === method && r.normalizedPath === normalizedPath
    );
    const exactE2e = e2eList.some(
      (r) => r.method === method && r.normalizedPath === normalizedPath
    );
    const exactPhp = phpList.some(
      (r) => r.method === method && r.normalizedPath === normalizedPath
    );

    // Presence for classification: covering counts
    const hasClient = Boolean(client);
    const hasDemo = Boolean(demo);
    const hasE2e = Boolean(e2e);
    const hasMock = hasDemo || hasE2e;
    const hasPhp = Boolean(php);
    const allow = findAllowlistEntry(input.allowlist, method, normalizedPath);

    /** @type {ClassifiedEndpoint} */
    let row = {
      method,
      normalizedPath,
      classification: "FULL_PARITY",
      severity: "info",
      client: exactClient
        ? clientList.find((r) => r.method === method && r.normalizedPath === normalizedPath)
        : client,
      demo: exactDemo
        ? demoList.find((r) => r.method === method && r.normalizedPath === normalizedPath)
        : demo,
      e2e: exactE2e
        ? e2eList.find((r) => r.method === method && r.normalizedPath === normalizedPath)
        : e2e,
      php: exactPhp
        ? phpList.find((r) => r.method === method && r.normalizedPath === normalizedPath)
        : php,
      allowlist: allow
    };

    if (hasClient && hasMock && hasPhp) {
      row.classification = "FULL_PARITY";
      row.severity = "info";
    } else if (hasClient && hasMock && !hasPhp) {
      row.classification = "CLIENT_AND_MOCK_NO_PHP";
      row.severity = "fatal";
    } else if (hasClient && hasPhp && !hasDemo && !hasE2e) {
      row.classification = "CLIENT_AND_PHP_NO_DEMO";
      row.severity = "warning";
      row.detail = "demo and e2e mock missing";
    } else if (hasClient && hasPhp && !hasDemo && hasE2e) {
      row.classification = "CLIENT_AND_PHP_NO_DEMO";
      row.severity = "warning";
    } else if (hasClient && hasPhp && hasDemo && !hasE2e) {
      row.classification = "CLIENT_AND_PHP_NO_E2E";
      row.severity = "warning";
    } else if (hasClient && !hasMock && !hasPhp) {
      row.classification = "CLIENT_ONLY";
      row.severity = "fatal";
    } else if (!hasClient && hasDemo && hasE2e && !hasPhp) {
      row.classification = "MOCK_ONLY";
      row.severity = "fatal";
    } else if (!hasClient && hasDemo && !hasE2e && !hasPhp) {
      row.classification = "DEMO_MOCK_ONLY";
      row.severity = "fatal";
    } else if (!hasClient && !hasDemo && hasE2e && !hasPhp) {
      row.classification = "E2E_MOCK_ONLY";
      row.severity = "fatal";
    } else if (!hasClient && !hasMock && hasPhp) {
      row.classification = "PHP_ONLY";
      row.severity = "warning";
    } else if (!hasClient && hasMock && hasPhp) {
      row.classification = "PHP_ONLY";
      row.severity = "warning";
      row.detail = "php+mock without client";
    } else if (hasClient && !hasPhp && !hasMock) {
      row.classification = "CLIENT_ONLY";
      row.severity = "fatal";
    }

    if (allow) {
      if (allow.category === "known_active_gap") {
        row.classification = "ALLOWLISTED_KNOWN_GAP";
        row.severity = "info";
      } else if (allow.category === "approved_deferred") {
        row.classification = "ALLOWLISTED_DEFERRED";
        row.severity = "info";
      } else {
        row.severity = row.classification === "PHP_ONLY" ? "warning" : "info";
        row.detail = `allowlisted:${allow.category}`;
      }
    }

    results.push(row);
  }

  // Client↔PHP method drift: same path, both sides have exclusive methods
  const pathMethods = new Map();
  for (const r of clientList) {
    addMethod(pathMethods, r.normalizedPath, "client", r.method);
  }
  for (const r of phpList) {
    // Expand php parametric onto client concrete paths for drift analysis
    const concretes = clientList
      .filter((c) => pathCovers(r.normalizedPath, c.normalizedPath))
      .map((c) => c.normalizedPath);
    const paths = concretes.length > 0 ? concretes : [r.normalizedPath];
    for (const p of paths) {
      addMethod(pathMethods, p, "php", r.method);
    }
  }

  for (const [path, sides] of pathMethods) {
    if (!sides.client?.size || !sides.php?.size) continue;
    const clientOnly = [...sides.client].filter((m) => !sides.php.has(m));
    const phpOnly = [...sides.php].filter((m) => !sides.client.has(m));
    if (clientOnly.length > 0 && phpOnly.length > 0) {
      const allow = findAllowlistEntry(input.allowlist, clientOnly[0], path);
      results.push({
        method: "*",
        normalizedPath: path,
        classification: "METHOD_DRIFT",
        severity: allow ? "info" : "fatal",
        detail: `client=[${[...sides.client].join(",")}] php=[${[...sides.php].join(",")}]`,
        allowlist: allow
      });
    }
  }

  // Stale allowlist detection
  const staleAllowlist = [];
  for (const entry of input.allowlist) {
    const path = normalizePath(entry.path);
    const method = entry.method.toUpperCase();
    const client = findCovering(clientList, method, path);
    const demo = findCovering(demoList, method, path);
    const e2e = findCovering(e2eList, method, path);
    const php = findCovering(phpList, method, path);
    const hasMock = Boolean(demo || e2e);
    const stillNeeded = isGapStillPresent({
      client,
      demo,
      e2e,
      php,
      hasMock,
      category: entry.category
    });
    if (!stillNeeded) {
      staleAllowlist.push(entry);
    }
  }

  const fatals = [
    ...results.filter((r) => r.severity === "fatal"),
    ...staleAllowlist.map((s) => ({
      method: s.method,
      normalizedPath: s.path,
      classification: /** @type {Classification} */ ("CLIENT_ONLY"),
      severity: /** @type {"fatal"} */ ("fatal"),
      detail: `stale allowlist entry (${s.category}${s.gapId ? ":" + s.gapId : ""}) no longer needed`,
      allowlist: s
    }))
  ];
  const warnings = results.filter((r) => r.severity === "warning");

  return { results, fatals, warnings, staleAllowlist };
}

function isGapStillPresent(p) {
  const { client, demo, e2e, php, hasMock, category } = p;
  if (category === "known_active_gap" || category === "approved_deferred") {
    if (client && !php) return true;
    if (!client && hasMock && !php) return true;
    return false;
  }
  if (category === "php_external_or_ops" || category === "legacy_active") {
    return Boolean(php && !client);
  }
  if (category === "demo_only") {
    return Boolean(demo && !client);
  }
  if (category === "test_only") {
    return Boolean(e2e && !client);
  }
  return Boolean(client && !php) || Boolean(!client && hasMock);
}

function addMethod(map, path, side, method) {
  if (!map.has(path)) map.set(path, { client: new Set(), php: new Set() });
  map.get(path)[side].add(method);
}
