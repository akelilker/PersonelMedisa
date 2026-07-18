/**
 * Extract real HTTP method+path pairs from src/api/*.api.ts call sites.
 */

import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { normalizeMethod, normalizePath, normalizeTemplatePath } from "./normalize.mjs";
import { buildEndpointsLookup, parseEndpointsCatalog } from "./parse-endpoints.mjs";

/**
 * @typedef {import('./normalize.mjs')} _N
 * @typedef {{
 *   source: "client",
 *   method: string,
 *   path: string,
 *   normalizedPath: string,
 *   file: string,
 *   symbol?: string,
 *   line?: number
 * }} EndpointRecord
 */

/**
 * Resolve a path expression against endpoints lookup + local consts.
 * @param {string} expr
 * @param {Map<string, { normalizedPath: string, path: string }>} endpointsLookup
 * @param {Map<string, string>} localConsts
 * @param {{ name: string, expr: string, index: number }[]} [localBindings]
 * @param {number} [callIndex]
 * @param {number} [depth]
 * @returns {string | null}
 */
export function resolvePathExpression(
  expr,
  endpointsLookup,
  localConsts,
  localBindings = [],
  callIndex = Number.MAX_SAFE_INTEGER,
  depth = 0
) {
  if (depth > 6) return null;
  let e = expr.trim();

  // appendQueryParams(X, ...) → X
  const aqp = e.match(/^appendQueryParams\(\s*([\s\S]+?)\s*,/);
  if (aqp) {
    e = aqp[1].trim();
  }

  // buildPreflightQuery(...) → PREFLIGHT_PATH
  if (/^buildPreflightQuery\s*\(/.test(e)) {
    const pre = localConsts.get("PREFLIGHT_PATH");
    return pre ? normalizePath(pre) : "/puantaj/donem-kapanis-preflight";
  }

  // buildReportQuery(...) → REPORT_PATH (+ optional suffix handled inside helper — base path)
  if (/^buildReportQuery\s*\(/.test(e)) {
    const report = localConsts.get("REPORT_PATH");
    return report ? normalizePath(report) : "/puantaj/bildirim-etki-adaylari/rapor";
  }

  // Template: `${endpoints.foo.bar(id)}/iptal` or `${CONST}/items`
  const tmpl = e.match(/^`([\s\S]+)`$/);
  if (tmpl) {
    return resolveTemplateLiteral(tmpl[1], endpointsLookup, localConsts);
  }

  // String concat: endpoints.foo.bar(id) + "/iptal"
  const concat = e.match(/^([\s\S]+?)\s*\+\s*["'](\/[^"']*)["']\s*$/);
  if (concat) {
    const base = resolvePathExpression(
      concat[1].trim(),
      endpointsLookup,
      localConsts,
      localBindings,
      callIndex,
      depth + 1
    );
    if (!base) return null;
    return normalizePath(`${base}${concat[2]}`);
  }

  // Local const string
  if (localConsts.has(e)) {
    return normalizePath(localConsts.get(e));
  }

  // Local binding (const path = ...) nearest before call
  if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(e)) {
    const binding = findBindingBefore(localBindings, e, callIndex);
    if (binding) {
      return resolvePathExpression(
        binding.expr,
        endpointsLookup,
        localConsts,
        localBindings,
        binding.index,
        depth + 1
      );
    }
  }

  // RAPOR_ENDPOINTS[tip] — expand all rapor paths separately by caller; skip here
  if (/^RAPOR_ENDPOINTS/.test(e)) {
    return null;
  }

  // endpoints.foo.bar or endpoints.foo.bar(...)
  const epCall = e.match(/^(endpoints(?:\.[A-Za-z_][A-Za-z0-9_]*)+)(?:\s*\([^)]*\))?$/);
  if (epCall) {
    const leaf = endpointsLookup.get(epCall[1]);
    if (leaf) {
      return leaf.normalizedPath;
    }
  }

  // Bare string literal
  const lit = e.match(/^["'](\/[^"']*)["']$/);
  if (lit) {
    return normalizeTemplatePath(lit[1]);
  }

  return null;
}

/**
 * Collect local variable bindings that look like path expressions.
 * @param {string} source
 * @returns {{ name: string, expr: string, index: number }[]}
 */
export function extractLocalPathBindings(source) {
  /** @type {{ name: string, expr: string, index: number }[]} */
  const list = [];
  const re =
    /(?:const|let)\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(appendQueryParams\s*\(|endpoints\.|`[^`]*`|["']\/[^"']*["']|[A-Z][A-Z0-9_]*\b|buildPreflightQuery\s*\(|buildReportQuery\s*\()/g;
  let m;
  while ((m = re.exec(source)) !== null) {
    const name = m[1];
    const start = m.index + m[0].indexOf(m[2]);
    // Read until semicolon at depth 0
    let depth = 0;
    let inStr = null;
    let escaped = false;
    let i = start;
    for (; i < source.length; i++) {
      const ch = source[i];
      if (inStr) {
        if (escaped) {
          escaped = false;
          continue;
        }
        if (ch === "\\") {
          escaped = true;
          continue;
        }
        if (ch === inStr) inStr = null;
        continue;
      }
      if (ch === '"' || ch === "'" || ch === "`") {
        inStr = ch;
        continue;
      }
      if (ch === "(" || ch === "{" || ch === "[") {
        depth += 1;
        continue;
      }
      if (ch === ")" || ch === "}" || ch === "]") {
        depth -= 1;
        continue;
      }
      if (ch === ";" && depth <= 0) {
        break;
      }
    }
    const expr = source.slice(start, i).trim();
    if (expr) {
      list.push({ name, expr, index: m.index });
    }
  }
  return list;
}

/**
 * Find nearest preceding binding for a name.
 * @param {{ name: string, expr: string, index: number }[]} bindings
 * @param {string} name
 * @param {number} beforeIndex
 */
function findBindingBefore(bindings, name, beforeIndex) {
  let found = null;
  for (const b of bindings) {
    if (b.name === name && b.index < beforeIndex) {
      found = b;
    }
  }
  return found;
}

/**
 * @param {string} body
 * @param {Map<string, { normalizedPath: string, path: string }>} endpointsLookup
 * @param {Map<string, string>} localConsts
 */
function resolveTemplateLiteral(body, endpointsLookup, localConsts) {
  // Replace ${endpoints.x.y(...)} with normalized path
  let out = body.replace(
    /\$\{(endpoints(?:\.[A-Za-z_][A-Za-z0-9_]*)+)(?:\([^)]*\))?\}/g,
    (_, ref) => {
      const leaf = endpointsLookup.get(ref);
      return leaf ? leaf.normalizedPath : `MISSING(${ref})`;
    }
  );

  // Replace ${CONST} or ${CONST}/suffix
  out = out.replace(/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/g, (_, name) => {
    if (localConsts.has(name)) {
      return localConsts.get(name);
    }
    // Dynamic runtime id → keep as :id-ish via normalizeTemplatePath
    return `\${${name}}`;
  });

  // If still has unresolved endpoints miss
  if (out.includes("MISSING(")) {
    return null;
  }

  return normalizeTemplatePath(out);
}

/**
 * Extract local path constants from an api.ts file.
 * @param {string} source
 * @returns {Map<string, string>}
 */
export function extractLocalPathConsts(source) {
  const map = new Map();
  const re =
    /(?:const|let)\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*["'](\/[^"']*)["']/g;
  let m;
  while ((m = re.exec(source)) !== null) {
    map.set(m[1], m[2]);
  }
  return map;
}

/**
 * Find enclosing exported function name near a line index.
 * @param {string} source
 * @param {number} index
 */
function findEnclosingSymbol(source, index) {
  const before = source.slice(0, index);
  const matches = [
    ...before.matchAll(/export\s+async\s+function\s+([A-Za-z_][A-Za-z0-9_]*)/g),
    ...before.matchAll(/export\s+function\s+([A-Za-z_][A-Za-z0-9_]*)/g)
  ];
  if (matches.length === 0) return undefined;
  return matches[matches.length - 1][1];
}

/**
 * Line number from char index.
 * @param {string} source
 * @param {number} index
 */
function lineAt(source, index) {
  return source.slice(0, index).split(/\r?\n/).length;
}

/**
 * Parse one *.api.ts file into EndpointRecords.
 * @param {string} source
 * @param {string} file
 * @param {Map<string, { normalizedPath: string, path: string }>} endpointsLookup
 * @returns {EndpointRecord[]}
 */
export function parseClientApiFile(source, file, endpointsLookup) {
  /** @type {EndpointRecord[]} */
  const records = [];
  const localConsts = extractLocalPathConsts(source);
  const localBindings = extractLocalPathBindings(source);

  // RAPOR_ENDPOINTS map values → GET each
  const raporBlock = source.match(
    /const\s+RAPOR_ENDPOINTS[^=]*=\s*\{([\s\S]*?)\}\s*;/
  );
  if (raporBlock) {
    const refs = [...raporBlock[1].matchAll(/endpoints(?:\.[A-Za-z_][A-Za-z0-9_]*)+/g)];
    for (const ref of refs) {
      const leaf = endpointsLookup.get(ref[0]);
      if (!leaf) continue;
      records.push({
        source: "client",
        method: "GET",
        path: leaf.path.includes("${") ? leaf.normalizedPath : leaf.path.split("?")[0],
        normalizedPath: leaf.normalizedPath,
        file,
        symbol: "fetchRapor",
        line: lineAt(source, raporBlock.index ?? 0)
      });
    }
  }

  // apiRequest<...>(pathExpr) — nested generics need a scanner, not [^>]*
  let searchFrom = 0;
  while (searchFrom < source.length) {
    const idx = source.indexOf("apiRequest", searchFrom);
    if (idx < 0) break;
    // Avoid matching identifiers like "apiRequestError"
    const prev = idx > 0 ? source[idx - 1] : " ";
    const nextStart = idx + "apiRequest".length;
    if (/[A-Za-z0-9_]/.test(prev) || /[A-Za-z0-9_]/.test(source[nextStart] ?? "")) {
      searchFrom = nextStart;
      continue;
    }

    let cursor = nextStart;
    while (/\s/.test(source[cursor] ?? "")) cursor += 1;

    // Optional type args <...>
    if (source[cursor] === "<") {
      const afterGeneric = skipGeneric(source, cursor);
      if (afterGeneric < 0) {
        searchFrom = nextStart;
        continue;
      }
      cursor = afterGeneric;
      while (/\s/.test(source[cursor] ?? "")) cursor += 1;
    }

    if (source[cursor] !== "(") {
      searchFrom = nextStart;
      continue;
    }

    const args = extractBalanced(source, cursor + 1);
    if (!args) {
      searchFrom = nextStart;
      continue;
    }

    const methodMatch = args.match(/method\s*:\s*["'](GET|POST|PUT|PATCH|DELETE)["']/i);
    const method = normalizeMethod(methodMatch ? methodMatch[1] : "GET");
    const firstArg = splitTopLevelArgs(args)[0]?.trim() ?? "";
    const resolved = resolvePathExpression(
      firstArg,
      endpointsLookup,
      localConsts,
      localBindings,
      idx
    );
    searchFrom = cursor + 1 + args.length + 1;
    if (!resolved) {
      continue;
    }

    records.push({
      source: "client",
      method,
      path: resolved,
      normalizedPath: normalizePath(resolved),
      file,
      symbol: findEnclosingSymbol(source, idx),
      line: lineAt(source, idx)
    });
  }

  // downloadAuthenticatedFile(path, ...) — GET file downloads
  const dlRe = /downloadAuthenticatedFile\s*\(/g;
  let dlMatch;
  while ((dlMatch = dlRe.exec(source)) !== null) {
    const argsStart = dlMatch.index + dlMatch[0].length;
    const args = extractBalanced(source, argsStart);
    if (!args) continue;
    const firstArg = splitTopLevelArgs(args)[0]?.trim() ?? "";
    const resolved = resolvePathExpression(
      firstArg,
      endpointsLookup,
      localConsts,
      localBindings,
      dlMatch.index
    );
    if (!resolved) continue;
    records.push({
      source: "client",
      method: "GET",
      path: resolved,
      normalizedPath: normalizePath(resolved),
      file,
      symbol: findEnclosingSymbol(source, dlMatch.index),
      line: lineAt(source, dlMatch.index)
    });
  }

  return records;
}

/**
 * Skip a TypeScript generic argument list starting at '<'.
 * @param {string} source
 * @param {number} start index of '<'
 * @returns {number} index just after matching '>' or -1
 */
function skipGeneric(source, start) {
  let depth = 0;
  let inStr = null;
  let escaped = false;
  for (let i = start; i < source.length; i++) {
    const ch = source[i];
    if (inStr) {
      if (escaped) {
        escaped = false;
        continue;
      }
      if (ch === "\\") {
        escaped = true;
        continue;
      }
      if (ch === inStr) inStr = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === "`") {
      inStr = ch;
      continue;
    }
    if (ch === "<") {
      depth += 1;
      continue;
    }
    if (ch === ">") {
      depth -= 1;
      if (depth === 0) return i + 1;
      continue;
    }
  }
  return -1;
}

/**
 * Extract balanced paren contents starting after open paren.
 * @param {string} source
 * @param {number} argsStart index of first char inside (
 */
function extractBalanced(source, argsStart) {
  let depth = 1;
  let i = argsStart;
  let inStr = null;
  let escaped = false;
  for (; i < source.length; i++) {
    const ch = source[i];
    if (inStr) {
      if (escaped) {
        escaped = false;
        continue;
      }
      if (ch === "\\") {
        escaped = true;
        continue;
      }
      if (ch === inStr) {
        inStr = null;
      }
      continue;
    }
    if (ch === '"' || ch === "'" || ch === "`") {
      inStr = ch;
      continue;
    }
    if (ch === "(" || ch === "{" || ch === "[") {
      depth += 1;
      continue;
    }
    if (ch === ")" || ch === "}" || ch === "]") {
      depth -= 1;
      if (depth === 0 && ch === ")") {
        return source.slice(argsStart, i);
      }
      continue;
    }
  }
  return null;
}

/**
 * @param {string} args
 * @returns {string[]}
 */
function splitTopLevelArgs(args) {
  const out = [];
  let depth = 0;
  let inStr = null;
  let escaped = false;
  let start = 0;
  for (let i = 0; i < args.length; i++) {
    const ch = args[i];
    if (inStr) {
      if (escaped) {
        escaped = false;
        continue;
      }
      if (ch === "\\") {
        escaped = true;
        continue;
      }
      if (ch === inStr) inStr = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === "`") {
      inStr = ch;
      continue;
    }
    if (ch === "(" || ch === "{" || ch === "[") {
      depth += 1;
      continue;
    }
    if (ch === ")" || ch === "}" || ch === "]") {
      depth -= 1;
      continue;
    }
    if (ch === "," && depth === 0) {
      out.push(args.slice(start, i));
      start = i + 1;
    }
  }
  out.push(args.slice(start));
  return out;
}

/**
 * @param {string} repoRoot
 * @returns {EndpointRecord[]}
 */
export function collectClientEndpoints(repoRoot) {
  const endpointsPath = join(repoRoot, "src/api/endpoints.ts");
  const endpointsSource = readFileSync(endpointsPath, "utf8");
  const leaves = parseEndpointsCatalog(endpointsSource, "src/api/endpoints.ts");
  const lookup = buildEndpointsLookup(leaves);

  const apiDir = join(repoRoot, "src/api");
  const files = readdirSync(apiDir)
    .filter((f) => f.endsWith(".api.ts"))
    .sort();

  /** @type {EndpointRecord[]} */
  const all = [];
  for (const file of files) {
    const rel = `src/api/${file}`;
    const source = readFileSync(join(apiDir, file), "utf8");
    all.push(...parseClientApiFile(source, rel, lookup));
  }

  return dedupeRecords(all);
}

/**
 * @param {EndpointRecord[]} records
 */
function dedupeRecords(records) {
  const map = new Map();
  for (const r of records) {
    const key = `${r.method} ${r.normalizedPath}`;
    if (!map.has(key)) {
      map.set(key, r);
    }
  }
  return [...map.values()].sort((a, b) =>
    `${a.method} ${a.normalizedPath}`.localeCompare(`${b.method} ${b.normalizedPath}`)
  );
}
