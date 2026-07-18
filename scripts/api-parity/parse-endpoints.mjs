/**
 * Parse src/api/endpoints.ts into a symbol → normalized path map.
 */

import { normalizeTemplatePath } from "./normalize.mjs";

/**
 * @typedef {{ symbol: string, path: string, normalizedPath: string, line: number, dynamic: boolean }} EndpointLeaf
 */

/**
 * @param {string} source
 * @param {string} [file]
 * @returns {EndpointLeaf[]}
 */
export function parseEndpointsCatalog(source, file = "src/api/endpoints.ts") {
  /** @type {EndpointLeaf[]} */
  const leaves = [];
  const lines = source.split(/\r?\n/);

  /** @type {string[]} */
  const stack = [];
  let depth = 0;
  let inEndpoints = false;

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const lineNo = i + 1;

    if (!inEndpoints) {
      if (/export\s+const\s+endpoints\s*=/.test(line)) {
        inEndpoints = true;
      }
      continue;
    }

    // Nested object open: key: {
    const nestOpen = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*\{\s*$/);
    if (nestOpen) {
      stack.push(nestOpen[1]);
      depth += 1;
      continue;
    }

    // Static path: key: "/path"
    const staticMatch = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*"(\/[^"]*)"\s*,?\s*$/);
    if (staticMatch) {
      const symbol = [...stack, staticMatch[1]].join(".");
      const path = staticMatch[2];
      leaves.push({
        symbol,
        path,
        normalizedPath: normalizeTemplatePath(path),
        line: lineNo,
        dynamic: false,
        file
      });
      continue;
    }

    // Dynamic single-line: key: (...) => `/path/${id}`
    const dynSingle = line.match(
      /^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*\([^)]*\)\s*=>\s*`(\/[^`]*)`\s*,?\s*$/
    );
    if (dynSingle) {
      const symbol = [...stack, dynSingle[1]].join(".");
      const path = dynSingle[2];
      leaves.push({
        symbol,
        path,
        normalizedPath: normalizeTemplatePath(path),
        line: lineNo,
        dynamic: true,
        file
      });
      continue;
    }

    // Dynamic multi-line start: key: (...) =>
    const dynStart = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*\([^)]*\)\s*=>\s*$/);
    if (dynStart) {
      const next = lines[i + 1] ?? "";
      const tmpl = next.match(/^\s*`(\/[^`]*)`\s*,?\s*$/);
      if (tmpl) {
        const symbol = [...stack, dynStart[1]].join(".");
        const path = tmpl[1];
        leaves.push({
          symbol,
          path,
          normalizedPath: normalizeTemplatePath(path),
          line: lineNo,
          dynamic: true,
          file
        });
        i += 1;
      }
      continue;
    }

    // Close braces
    const closes = (line.match(/\}/g) || []).length;
    const opens = (line.match(/\{/g) || []).length;
    const net = closes - opens;
    if (net > 0) {
      for (let c = 0; c < net && stack.length > 0; c++) {
        stack.pop();
        depth = Math.max(0, depth - 1);
      }
      if (stack.length === 0 && inEndpoints && /};\s*$/.test(line.trim()) || (depth === 0 && closes > 0 && stack.length === 0)) {
        // end of endpoints object
        if (/^\s*\};?\s*$/.test(line) || line.includes("};")) {
          break;
        }
      }
    }
  }

  return leaves;
}

/**
 * Build lookup: "endpoints.surecler.detail" → leaf
 * @param {EndpointLeaf[]} leaves
 * @returns {Map<string, EndpointLeaf>}
 */
export function buildEndpointsLookup(leaves) {
  const map = new Map();
  for (const leaf of leaves) {
    map.set(`endpoints.${leaf.symbol}`, leaf);
    map.set(leaf.symbol, leaf);
  }
  return map;
}
