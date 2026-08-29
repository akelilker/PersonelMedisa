import { readFileSync } from "node:fs";
import { relative, resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { globSync } from "node:fs";

/**
 * Production runs PHP 7.4 while CI runs 8.x, so PHP 8-only syntax type-checks and
 * tests green here and then dies on the live host. It cost a production outage:
 * `mixed $raw` in PersonelSearchPredicate::normalize() is not a type on 7.4, it is
 * a class name resolved inside the current namespace, so every personel list
 * request raised
 *
 *   TypeError: Argument 1 ... must be an instance of
 *   Medisa\Api\Services\Personel\mixed, string given
 *
 * These files are loaded by web requests and must therefore parse and run on 7.4.
 */

const WEB_REACHABLE_ROOTS = ["api/src", "api/public"];

/**
 * Reachable only from migration tooling that runs under the CLI PHP, never from a
 * web request, so the 7.4 web baseline does not apply to them.
 */
const CLI_ONLY_ALLOWLIST = new Set([
  "api/src/Database/BundledMigrationSourceProvider.php",
  "api/src/Database/FilesystemMigrationSourceProvider.php",
]);

function phpFiles(): string[] {
  const files: string[] = [];
  for (const root of WEB_REACHABLE_ROOTS) {
    for (const file of globSync(`${root}/**/*.php`, { cwd: process.cwd() })) {
      const normalized = relative(process.cwd(), resolve(process.cwd(), file)).replace(/\\/g, "/");
      if (!CLI_ONLY_ALLOWLIST.has(normalized)) {
        files.push(normalized);
      }
    }
  }
  return files.sort();
}

/** Strips comments and strings so docblock `@param mixed` never trips the scan. */
function stripCommentsAndStrings(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, " ")
    .replace(/\/\/[^\n]*/g, " ")
    .replace(/#[^\n]*/g, " ")
    .replace(/'(?:\\.|[^'\\])*'/g, "''")
    .replace(/"(?:\\.|[^"\\])*"/g, '""');
}

describe("api PHP 7.4 production runtime baseline", () => {
  const files = phpFiles();

  it("scans a meaningful number of web-reachable php files", () => {
    expect(files.length).toBeGreaterThan(100);
    expect(files).toContain("api/src/Services/Personel/PersonelSearchPredicate.php");
    expect(files).toContain("api/src/Http/ErrorBoundary.php");
  });

  it("declares no mixed or never types", () => {
    const offenders: string[] = [];
    for (const file of files) {
      const code = stripCommentsAndStrings(readFileSync(file, "utf8"));
      // Parameter position: `(mixed $x` / `, mixed $x`, and return position `): mixed`.
      if (/[(,]\s*(?:\?\s*)?mixed\s+\$/.test(code) || /\)\s*:\s*(?:\?\s*)?mixed\b/.test(code)) {
        offenders.push(`${file} (mixed)`);
      }
      if (/\)\s*:\s*never\b/.test(code)) {
        offenders.push(`${file} (never)`);
      }
    }
    expect(offenders, offenders.join("\n")).toEqual([]);
  });

  it("declares no readonly properties or promoted readonly constructor params", () => {
    const offenders: string[] = [];
    for (const file of files) {
      const code = stripCommentsAndStrings(readFileSync(file, "utf8"));
      if (/\breadonly\s+/.test(code)) {
        offenders.push(file);
      }
    }
    expect(offenders, offenders.join("\n")).toEqual([]);
  });

  it("declares no union or intersection parameter and return types", () => {
    const offenders: string[] = [];
    const scalars = "int|float|string|bool|array|object|callable|iterable|self|static|null|false|true";
    const unionParam = new RegExp(`[(,]\\s*(?:${scalars})\\s*[|&]\\s*\\w`, "i");
    const unionReturn = new RegExp(`\\)\\s*:\\s*(?:\\?\\s*)?(?:${scalars})\\s*[|&]\\s*\\w`, "i");
    for (const file of files) {
      const code = stripCommentsAndStrings(readFileSync(file, "utf8"));
      if (unionParam.test(code) || unionReturn.test(code)) {
        offenders.push(file);
      }
    }
    expect(offenders, offenders.join("\n")).toEqual([]);
  });

  it("uses no PHP 8 only syntax: attributes, nullsafe calls, enums, match", () => {
    const offenders: string[] = [];
    for (const file of files) {
      const code = stripCommentsAndStrings(readFileSync(file, "utf8"));
      if (/\?->/.test(code)) {
        offenders.push(`${file} (nullsafe)`);
      }
      if (/^\s*#\[/m.test(code)) {
        offenders.push(`${file} (attribute)`);
      }
      if (/^\s*enum\s+\w+/m.test(code)) {
        offenders.push(`${file} (enum)`);
      }
      if (/=\s*match\s*\(/.test(code)) {
        offenders.push(`${file} (match)`);
      }
    }
    expect(offenders, offenders.join("\n")).toEqual([]);
  });

  it("keeps the search predicate signatures untyped for the 7.4 host", () => {
    const source = readFileSync(
      "api/src/Services/Personel/PersonelSearchPredicate.php",
      "utf8",
    );
    expect(source).toContain("public static function normalize($raw): string");
    expect(source).toContain("public static function tokenize($raw): array");
    // Only the docblocks may say `mixed`; the signatures may not.
    expect(stripCommentsAndStrings(source)).not.toMatch(/mixed\s+\$raw/);
  });
});
