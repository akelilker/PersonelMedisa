import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("company/branch hierarchy: read model, API contract, scope and import (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("derives one shared display name, scopes branch names per company and keeps the legacy database working", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/SirketSubeHiyerarsisiMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    for (const marker of [
      // read model
      "company + short name compose",
      "Medisa Fabrika composes without inventing a longer name",
      "no company falls back to the raw branch name",
      "an equal short name is not repeated",
      "Şenay equal short name is not duplicated",
      "equality is decided after whitespace and Turkish case normalization",
      "Turkish dotted-I folds to the same normalized name",
      // legacy fallback
      "a pre-079 database is not schema ready",
      "readiness names the missing structure instead of crashing",
      "the flat legacy branch list still reads on a pre-079 database",
      "a hierarchy read on a pre-079 database answers 409 readiness, never a 500 unknown table",
      "schema readiness alone is not data readiness: nothing is mapped yet",
      // company + branch contract
      "duplicate company write is refused with DUPLICATE_SIRKET_KOD",
      "duplicate company write is refused with DUPLICATE_SIRKET_AD",
      "company code is immutable on edit",
      "the stored branch name stays the short name",
      "the read model composes the shared display name",
      "findById returns short ad and derived tam_ad through the same owner",
      "the parent company comes from the route, not from the payload",
      "the same short name under a different company is accepted and stays distinguishable",
      "a normalized duplicate short name inside one company is refused",
      "branch code stays globally unique across companies",
      "tam_ad is rejected on write: it is a derived read field",
      "a payload sirket_id is refused instead of silently moving the branch",
      "a payroll employer from another company cannot be attached to this branch",
      "branch code is immutable on edit",
      "company detail lists only its own branches",
      "the legacy flat endpoint still lists every branch",
      "a company with dependents answers 409 instead of cascading a delete",
      // scope
      "global roles stay unrestricted",
      "a branch manager never escalates to a company scope",
      "server-side role validation decides which roles may receive a company scope",
      "a branch added after the grant is visible without copying any assignment",
      "the company scope is never materialised into user_subeler rows",
      "a scoped role with no scope at all fails closed instead of matching every row",
      "an SGK scope filters on personeller.sgk_isveren_id, the payroll axis itself",
      "a company-only MUHASEBE payload filters the resolved company branches",
      "MUHASEBE may hold company and SGK grants without inventing a new role",
      "company + explicit branch grants compose as a widening union",
      "company scope never opens another company branch",
      // import / export
      "the canonical full name resolves to exactly one branch",
      "the bare short name stays ambiguous instead of silently picking one branch",
      "on a pre-079 database the raw branch name fallback still resolves"
    ]) {
      expect(result.stdout).toContain(marker);
    }

    expect(result.stdout).toContain("verify-sirket-sube-hiyerarsisi-mysql: OK");
  });
});
