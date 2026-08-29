import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("personel unified search (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("combined name, Turkish collation, wildcard escaping, scope and pagination parity", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/PersonelUnifiedSearchMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    for (const marker of [
      'combined "İlker Akel" resolves the single person',
      "reverse token order resolves the same person",
      "partial tokens across ad and soyad",
      "dotted/dotless I variation",
      "leading/trailing/multiple whitespace is ignored",
      "multi-word ad + soyad matched as one field",
      "token AND across fields: name token + sicil token",
      "tokens are ANDed, so two different people never match",
      "T.C. Kimlik No still searchable",
      "telefon still searchable",
      'ASCII "sule" finds "Şule"',
      'dotless ı input finds "IŞIK"',
      "search expression carries an explicit case-folding collation",
      "no schema/collation migration happened",
      "literal % matches only the row that contains %",
      "underscore does not act as a single-character wildcard",
      "literal backslash is matched, not treated as an escape",
      "empty search appends no predicate",
      "overlong input is clamped to MAX_LENGTH",
      "unrestricted user in active branch finds branchless DIS_KAYNAK 212",
      "SUBE_YONETICISI cannot reach branchless DIS_KAYNAK through search",
      "MUHASEBE cannot reach branchless DIS_KAYNAK through search",
      "search never crosses the branch boundary",
      "branchless IC_PERSONEL stays invisible in a branch context",
      "search AND calisan_kapsami filter",
      "count query and data query use one canonical predicate",
      "paged rows add up to the total with no duplicates and no phantom page",
      "exact query returns 212 exactly once",
      "quote injection is a literal search token, not SQL",
      "every field/token pair is bound as its own placeholder",
      "PersonellerController has no duplicated ad/soyad search block",
      "ArsivController delegates to the canonical search owner"
    ]) {
      expect(result.stdout).toContain(marker);
    }

    expect(result.stdout).toContain("verify-personel-unified-search-mysql: OK");
  });
});
