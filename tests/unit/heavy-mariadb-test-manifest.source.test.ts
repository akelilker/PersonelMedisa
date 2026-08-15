import { readFileSync, readdirSync } from "node:fs";
import { join, relative, resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { heavyMariaDbTestFiles } from "../scripts/heavy-mariadb-test-files.mjs";

const repoRoot = resolve(process.cwd());
const unitRoot = join(repoRoot, "tests", "unit");
const directMariaDbTestFiles = ["tests/unit/s2b-yillik-izin-hak-ledger.mysql.php-runtime.test.ts"];

function listTestFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      return listTestFiles(path);
    }
    return /\.test\.tsx?$/.test(entry.name) ? [path] : [];
  });
}

describe("fast CI MariaDB dependency manifest", () => {
  it("keeps every disposable MariaDB helper consumer out of the fast suite", () => {
    const helperConsumers = listTestFiles(unitRoot)
      .filter((path) => /\b(?:runPhpMysqlRunner|ensureDisposableMariaDbEnv)\s*\(/.test(readFileSync(path, "utf8")))
      .map((path) => relative(repoRoot, path).replaceAll("\\", "/"))
      .sort();

    expect([...heavyMariaDbTestFiles].sort()).toEqual([...helperConsumers, ...directMariaDbTestFiles].sort());
  });
});
