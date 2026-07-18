/**
 * Parse api/src/Router.php route declarations.
 */

import { normalizeMethod, normalizePath, normalizeRegexPath } from "./normalize.mjs";

/**
 * @typedef {{
 *   source: "php",
 *   method: string,
 *   path: string,
 *   normalizedPath: string,
 *   file: string,
 *   symbol?: string,
 *   line?: number
 * }} EndpointRecord
 */

/**
 * @param {string} source
 * @param {string} [file]
 * @returns {EndpointRecord[]}
 */
export function parsePhpRouter(source, file = "api/src/Router.php") {
  /** @type {EndpointRecord[]} */
  const records = [];
  const lines = source.split(/\r?\n/);

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const lineNo = i + 1;

    // Exact: $path === '/foo' && $method === 'GET'
    const exactPath = line.match(/\$path\s*===\s*'(\/[^']+)'/);
    const exactMethod = line.match(/\$method\s*===\s*'(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)'/i);

    if (exactPath && exactMethod) {
      const method = normalizeMethod(exactMethod[1]);
      const normalizedPath = normalizePath(exactPath[1]);
      records.push({
        source: "php",
        method,
        path: normalizedPath,
        normalizedPath,
        file,
        line: lineNo,
        symbol: extractControllerSymbol(line, lines, i)
      });
      continue;
    }

    // preg_match with method on same or nearby line
    const preg = line.match(/preg_match\(\s*'#(\^[^']+\$)#'/);
    if (preg) {
      const method =
        (exactMethod && exactMethod[1]) ||
        findPhpMethodNearby(lines, i)?.[1] ||
        "GET";
      const normalizedPath = normalizeRegexPath(preg[1]);
      records.push({
        source: "php",
        method: normalizeMethod(method),
        path: normalizedPath,
        normalizedPath,
        file,
        line: lineNo,
        symbol: extractControllerSymbol(line, lines, i)
      });
    }
  }

  return dedupe(records);
}

/**
 * @param {string[]} lines
 * @param {number} i
 */
function findPhpMethodNearby(lines, i) {
  for (let j = Math.max(0, i - 1); j <= Math.min(lines.length - 1, i + 1); j++) {
    const m = lines[j].match(/\$method\s*===\s*'(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)'/i);
    if (m) return m;
  }
  return null;
}

/**
 * @param {string} line
 * @param {string[]} lines
 * @param {number} i
 */
function extractControllerSymbol(line, lines, i) {
  const same = line.match(/([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)/);
  if (same) return `${same[1]}::${same[2]}`;
  const next = lines[i + 1] ?? "";
  const n = next.match(/([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)/);
  if (n) return `${n[1]}::${n[2]}`;
  return undefined;
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
