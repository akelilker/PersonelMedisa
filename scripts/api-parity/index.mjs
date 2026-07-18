/**
 * API parity gate orchestrator — collect, classify, format.
 */

import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { validateAllowlist } from "./allowlist.mjs";
import { classifyParity } from "./classify.mjs";
import { collectClientEndpoints } from "./parse-client.mjs";
import { expandMockFamilies, parseMockHandlers } from "./parse-mock.mjs";
import { parsePhpRouter } from "./parse-php.mjs";

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * @param {string} [repoRoot]
 * @param {{ allowlistPath?: string, allowlist?: object }} [options]
 */
export function runApiParityGate(repoRoot, options = {}) {
  const root = repoRoot ?? join(__dirname, "../..");
  const allowlistPath =
    options.allowlistPath ?? join(root, "scripts/api-parity-allowlist.json");

  let allowlistDoc = options.allowlist;
  if (!allowlistDoc) {
    allowlistDoc = JSON.parse(readFileSync(allowlistPath, "utf8"));
  }

  const { entries: allowlist, errors: allowlistErrors } = validateAllowlist(allowlistDoc);

  const client = collectClientEndpoints(root);

  const demoSource = readFileSync(join(root, "src/api/mock-demo.ts"), "utf8");
  const e2eSource = readFileSync(join(root, "tests/e2e/helpers/mock-api.ts"), "utf8");
  const phpSource = readFileSync(join(root, "api/src/Router.php"), "utf8");

  let demo = parseMockHandlers(demoSource, "demo_mock", "src/api/mock-demo.ts");
  let e2e = parseMockHandlers(e2eSource, "e2e_mock", "tests/e2e/helpers/mock-api.ts");
  demo = expandMockFamilies(demo, client);
  e2e = expandMockFamilies(e2e, client);

  const php = parsePhpRouter(phpSource, "api/src/Router.php");

  /** @type {string[]} */
  const fatals = [];

  if (allowlistErrors.length > 0) {
    for (const err of allowlistErrors) {
      fatals.push(`[ALLOWLIST_SCHEMA] ${err}`);
    }
  }

  const classified = classifyParity({
    client,
    demo,
    e2e,
    php,
    allowlist: allowlistErrors.length > 0 ? [] : allowlist
  });

  for (const f of classified.fatals) {
    fatals.push(formatFatal(f));
  }

  const knownGaps = classified.results.filter((r) => r.classification === "ALLOWLISTED_KNOWN_GAP");
  const deferred = classified.results.filter((r) => r.classification === "ALLOWLISTED_DEFERRED");
  const phpOnlyWarnings = classified.warnings.filter((r) => r.classification === "PHP_ONLY");

  const summary = {
    pass: fatals.length === 0,
    clientCount: client.length,
    phpCount: php.length,
    demoCount: demo.filter((d) => !d.normalizedPath.endsWith("/*")).length,
    e2eCount: e2e.filter((d) => !d.normalizedPath.endsWith("/*")).length,
    knownGaps: knownGaps.length,
    deferred: deferred.length,
    warnings: classified.warnings.length,
    fatal: fatals.length,
    phpOnlyWarnings: phpOnlyWarnings.length
  };

  return {
    summary,
    fatals,
    warnings: classified.warnings.map(formatWarning),
    knownGaps: knownGaps.map(formatAllowlisted),
    deferred: deferred.map(formatAllowlisted),
    results: classified.results,
    inventories: { client, demo, e2e, php },
    allowlist,
    allowlistErrors,
    staleAllowlist: classified.staleAllowlist
  };
}

/**
 * @param {import('./classify.mjs').ClassifiedEndpoint} f
 */
function formatFatal(f) {
  if (f.detail?.startsWith("stale allowlist")) {
    return `[STALE_ALLOWLIST] ${f.method} ${f.normalizedPath}\n${f.detail}`;
  }

  if (f.classification === "METHOD_DRIFT") {
    return [
      `[METHOD_DRIFT] ${f.method} ${f.normalizedPath}`,
      f.detail ?? "",
      "suggestion: align methods across layers or add explicit allowlist"
    ]
      .filter(Boolean)
      .join("\n");
  }

  const lines = [
    `[${f.classification}] ${f.method} ${f.normalizedPath}`,
    `client: ${formatRef(f.client)}`,
    `demo: ${formatRef(f.demo)}`,
    `e2e: ${formatRef(f.e2e)}`,
    `php: ${f.php ? formatRef(f.php) : "missing"}`,
    "suggestion: add explicit allowlist or implement PHP owner"
  ];
  return lines.join("\n");
}

/**
 * @param {import('./classify.mjs').ClassifiedEndpoint} w
 */
function formatWarning(w) {
  return `[${w.classification}] ${w.method} ${w.normalizedPath}${w.detail ? ` — ${w.detail}` : ""}`;
}

/**
 * @param {import('./classify.mjs').ClassifiedEndpoint} r
 */
function formatAllowlisted(r) {
  const a = r.allowlist;
  if (!a) {
    return `[ALLOWLISTED] ${r.method} ${r.normalizedPath}`;
  }
  if (a.category === "known_active_gap") {
    return `[KNOWN_ACTIVE_GAP:${a.gapId}] ${a.method} ${a.path} → target ${a.targetPhase}`;
  }
  if (a.category === "approved_deferred") {
    return `[APPROVED_DEFERRED${a.gapId ? ":" + a.gapId : ""}] ${a.method} ${a.path} → target ${a.targetPhase}`;
  }
  return `[${a.category.toUpperCase()}] ${a.method} ${a.path}`;
}

function formatRef(rec) {
  if (!rec) return "missing";
  const loc = [rec.file, rec.symbol, rec.line != null ? `L${rec.line}` : null]
    .filter(Boolean)
    .join(":");
  return loc || "present";
}

/**
 * Print human summary to stdout/stderr.
 * @param {ReturnType<typeof runApiParityGate>} report
 * @param {{ stdout?: NodeJS.WritableStream, stderr?: NodeJS.WritableStream }} [io]
 */
export function printParityReport(report, io = {}) {
  const out = io.stdout ?? process.stdout;
  const err = io.stderr ?? process.stderr;

  if (report.allowlistErrors.length > 0) {
    err.write("Allowlist schema errors:\n");
    for (const e of report.allowlistErrors) {
      err.write(`  ${e}\n`);
    }
  }

  if (report.fatals.length > 0) {
    err.write("\nAPI parity gate FAIL — fatal findings:\n\n");
    for (const f of report.fatals) {
      err.write(`${f}\n\n`);
    }
  }

  if (report.knownGaps.length > 0) {
    out.write("Known active gaps:\n");
    for (const g of report.knownGaps) {
      out.write(`  ${g}\n`);
    }
    out.write("\n");
  }

  if (report.deferred.length > 0) {
    out.write(`Approved deferred (${report.deferred.length}):\n`);
    for (const d of report.deferred.slice(0, 30)) {
      out.write(`  ${d}\n`);
    }
    if (report.deferred.length > 30) {
      out.write(`  ... +${report.deferred.length - 30} more\n`);
    }
    out.write("\n");
  }

  if (report.warnings.length > 0) {
    out.write(`Warnings (${report.warnings.length}):\n`);
    for (const w of report.warnings.slice(0, 40)) {
      out.write(`  ${w}\n`);
    }
    if (report.warnings.length > 40) {
      out.write(`  ... +${report.warnings.length - 40} more\n`);
    }
    out.write("\n");
  }

  const s = report.summary;
  if (s.pass) {
    out.write("API parity gate PASS\n");
  } else {
    err.write("API parity gate FAIL\n");
  }
  out.write(`Client endpoints: ${s.clientCount}\n`);
  out.write(`PHP routes: ${s.phpCount}\n`);
  out.write(`Demo handlers: ${s.demoCount}\n`);
  out.write(`E2E handlers: ${s.e2eCount}\n`);
  out.write(`Known gaps: ${s.knownGaps}\n`);
  out.write(`Deferred: ${s.deferred}\n`);
  out.write(`Warnings: ${s.warnings}\n`);
  out.write(`Fatal: ${s.fatal}\n`);
}
