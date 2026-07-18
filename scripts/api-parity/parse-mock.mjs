/**
 * Parse mock-demo.ts and e2e mock-api.ts handler declarations.
 */

import { normalizeMethod, normalizePath, normalizeRegexPath } from "./normalize.mjs";

/**
 * @typedef {{
 *   source: "demo_mock" | "e2e_mock",
 *   method: string,
 *   path: string,
 *   normalizedPath: string,
 *   file: string,
 *   symbol?: string,
 *   line?: number
 * }} EndpointRecord
 */

/**
 * Expand alternation like (gonder|onay|red|iptal) into concrete paths.
 * @param {string} pattern raw regex body without ^ $
 * @returns {string[]}
 */
export function expandAlternations(pattern) {
  const match = pattern.match(/^(.*)(\((?:[A-Za-z0-9_-]+\|)+[A-Za-z0-9_-]+\))(.*)$/);
  if (!match) {
    return [pattern];
  }
  const [, prefix, group, suffix] = match;
  const options = group.slice(1, -1).split("|");
  return options.flatMap((opt) => expandAlternations(`${prefix}${opt}${suffix}`));
}

/**
 * @param {string} regexBody
 * @returns {string[]}
 */
function patternsFromRegexBody(regexBody) {
  let body = regexBody.replace(/^\^/, "").replace(/\$$/, "");
  // Normalize optional ucret(?:ler)? before expansion
  body = body.replace(/ucret\(\?:ler\)\?/g, "ucretler");
  return expandAlternations(body).map((p) => normalizeRegexPath(p));
}

/**
 * @param {string} source
 * @param {"demo_mock" | "e2e_mock"} sourceKind
 * @param {string} file
 * @returns {EndpointRecord[]}
 */
export function parseMockHandlers(source, sourceKind, file) {
  /** @type {EndpointRecord[]} */
  const records = [];
  const lines = source.split(/\r?\n/);

  const pathVar = sourceKind === "e2e_mock" ? "path" : "pathname";

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const lineNo = i + 1;

    // Exact: pathname === "/foo" && method === "GET"
    // or method === "GET" && pathname === "/foo"
    const exactPath = line.match(
      new RegExp(`${pathVar}\\s*===\\s*["'](\\/[^"']+)["']`)
    );
    if (exactPath) {
      const methodMatch =
        line.match(/method\s*===\s*["'](GET|POST|PUT|PATCH|DELETE|OPTIONS)["']/i) ||
        peekMethodNearby(lines, i);
      const method = normalizeMethod(methodMatch ? methodMatch[1] : "GET");
      let rawPath = exactPath[1];
      if (sourceKind === "e2e_mock") {
        rawPath = rawPath.replace(/^\/api(?=\/|$)/, "") || "/";
      }
      const normalizedPath = normalizePath(rawPath);
      records.push({
        source: sourceKind,
        method,
        path: normalizedPath,
        normalizedPath,
        file,
        line: lineNo
      });
      continue;
    }

    // Regex: pathname.match(/^\/surecler\/(\d+)$/)
    const matchCall = line.match(
      new RegExp(`${pathVar}\\.match\\(\\s*\\/(\\^[\\s\\S]*?\\$)\\/`)
    );
    // Also handle multiline: pathname.match( \n /^...$/ )
    let regexBody = null;
    if (matchCall) {
      regexBody = matchCall[1];
    } else if (new RegExp(`${pathVar}\\.match\\(\\s*$`).test(line)) {
      const next = (lines[i + 1] ?? "").trim();
      const m = next.match(/^\/(\^[\s\S]*?\$)\//);
      if (m) {
        regexBody = m[1];
      }
    }

    if (regexBody) {
      // Collect all methods referenced in the following block (up to ~40 lines / next match)
      const methods = collectMethodsInBlock(lines, i, 40);
      if (methods.length === 0) {
        methods.push(inferMethodFromContext(lines, i));
      }

      let bodies = patternsFromRegexBody(regexBody);
      if (sourceKind === "e2e_mock") {
        bodies = bodies.map((p) => normalizePath(p.replace(/^\/api(?=\/|$)/, "") || p));
      }

      for (const method of methods) {
        for (const normalizedPath of bodies) {
          records.push({
            source: sourceKind,
            method: normalizeMethod(method),
            path: normalizedPath,
            normalizedPath,
            file,
            line: lineNo
          });
        }
      }
      continue;
    }

    // startsWith catch-alls — record as family markers (expanded later against client)
    const starts = line.match(
      new RegExp(`${pathVar}\\.startsWith\\(\\s*["'](\\/[^"']+)["']`)
    );
    if (starts) {
      const methodMatch =
        findMethodInWindow(lines, i, 4) ||
        peekMethodNearby(lines, i);
      const method = normalizeMethod(methodMatch ? methodMatch[1] : "GET");
      let prefix = starts[1];
      if (sourceKind === "e2e_mock") {
        prefix = prefix.replace(/^\/api(?=\/|$)/, "") || "/";
      }
      records.push({
        source: sourceKind,
        method,
        path: `${normalizePath(prefix)}/*`,
        normalizedPath: `${normalizePath(prefix)}/*`,
        file,
        symbol: "startsWith",
        line: lineNo
      });
    }
  }

  return dedupe(records);
}

/**
 * Collect method === "X" checks in a handler block after a match.
 * @param {string[]} lines
 * @param {number} i
 * @param {number} maxLines
 * @returns {string[]}
 */
function collectMethodsInBlock(lines, i, maxLines) {
  const methods = [];
  const seen = new Set();
  for (let j = i; j <= i + maxLines && j < lines.length; j++) {
    const line = lines[j];
    // Stop at next top-level path match/exact check
    if (j > i && (/\.match\(/.test(line) || /pathname\s*===|path\s*===/.test(line))) {
      break;
    }
    const m = line.matchAll(/method\s*===\s*["'](GET|POST|PUT|PATCH|DELETE|OPTIONS)["']/gi);
    for (const hit of m) {
      const method = hit[1].toUpperCase();
      if (!seen.has(method)) {
        seen.add(method);
        methods.push(method);
      }
    }
  }
  return methods;
}

/**
 * @param {string[]} lines
 * @param {number} i
 */
function peekMethodNearby(lines, i) {
  for (let j = i; j <= i + 3 && j < lines.length; j++) {
    const m = lines[j].match(/method\s*===\s*["'](GET|POST|PUT|PATCH|DELETE|OPTIONS)["']/i);
    if (m) return m;
  }
  for (let j = i; j >= Math.max(0, i - 3); j--) {
    const m = lines[j].match(/method\s*===\s*["'](GET|POST|PUT|PATCH|DELETE|OPTIONS)["']/i);
    if (m) return m;
  }
  return null;
}

/**
 * @param {string[]} lines
 * @param {number} i
 * @param {number} window
 */
function findMethodInWindow(lines, i, window) {
  for (let j = i; j <= i + window && j < lines.length; j++) {
    const m = lines[j].match(/method\s*===\s*["'](GET|POST|PUT|PATCH|DELETE|OPTIONS)["']/i);
    if (m) return m;
  }
  return null;
}

/**
 * Heuristic: PUT blocks often check method === "PUT" a few lines after match.
 * Default GET.
 * @param {string[]} lines
 * @param {number} i
 */
function inferMethodFromContext(lines, i) {
  const slice = lines.slice(i, Math.min(lines.length, i + 8)).join("\n");
  const m = slice.match(/method\s*===\s*["'](GET|POST|PUT|PATCH|DELETE)["']/i);
  return m ? m[1] : "GET";
}

/**
 * Expand startsWith family markers against a set of concrete client paths.
 * @param {EndpointRecord[]} mockRecords
 * @param {{ method: string, normalizedPath: string }[]} clientRecords
 * @returns {EndpointRecord[]}
 */
export function expandMockFamilies(mockRecords, clientRecords) {
  const concrete = mockRecords.filter((r) => !r.normalizedPath.endsWith("/*"));
  const families = mockRecords.filter((r) => r.normalizedPath.endsWith("/*"));
  const out = [...concrete];

  for (const fam of families) {
    const prefix = fam.normalizedPath.slice(0, -2); // drop /*
    for (const c of clientRecords) {
      if (c.method !== fam.method) continue;
      if (c.normalizedPath === prefix || c.normalizedPath.startsWith(`${prefix}/`)) {
        out.push({
          ...fam,
          path: c.normalizedPath,
          normalizedPath: c.normalizedPath,
          symbol: fam.symbol ? `${fam.symbol}->${c.normalizedPath}` : undefined
        });
      }
    }
  }

  return dedupe(out);
}

/**
 * @param {EndpointRecord[]} records
 */
function dedupe(records) {
  const map = new Map();
  for (const r of records) {
    const key = `${r.method} ${r.normalizedPath}`;
    if (!map.has(key)) map.set(key, r);
  }
  return [...map.values()];
}
