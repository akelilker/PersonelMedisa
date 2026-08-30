import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("migration 079 control plane: preflight, backup and key transition (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("proves the preflight is read-only, the backup is verifiable and 079 never drops the only unique key", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/MigrationControlPlanePreflightMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    for (const marker of [
      "preflight leaves schema, data and ledger byte-identical",
      "preflight reports production tip 078",
      "preflight reports code tip 079",
      "pending list contains only migration 079",
      "ledger has no checksum mismatch and no gap",
      "pending checksum equals the sha256 of the 079 file on this ref",
      "canonical preimage passes the preflight",
      "null branch rows are counted",
      "orphan branch rows are counted",
      "orphan guard resolved its canonical branch owner",
      "079 is schema-only, so the expected post-migration state row count equals the preimage count",
      "preimage is still the legacy UNIQUE(ay) shape",
      "actor columns are absent before apply",
      "null and orphan branch rows surface as warnings, not as silent zeroes",
      "preflight report never carries row content: Fixture Personel",
      "preflight report carries no credential-shaped field",
      "an edited applied migration blocks the preflight",
      "backup lands outside the webroot, as a sibling of public_html",
      "backup file name carries migration tip, request id and timestamp",
      "published checksum and size match the file on disk",
      "backup readback is verified before the caller continues",
      "backup scope is the two closing tables plus the ledger preimage",
      "published metadata omits the server path",
      "the dump alone is a sufficient restore artifact for all three tables",
      "restore brings back the pre-079 key shape, so the index transition is reversible",
      "a webroot-reachable backup location fails closed instead of writing PII into the webroot",
      "an unresolvable safe location fails closed instead of falling back silently",
      "a non-unique key wearing the composite name aborts the migration",
      "the legacy UNIQUE key survives the abort, so the table is never uniqueness-free",
      "the interrupted state carries both unique keys instead of none",
      "a rerun resumes from the interrupted state",
      "the resume finishes the transition: composite kept, legacy dropped",
      "the surviving key is UNIQUE (ay, sube_id)",
      "state sube_id is NOT NULL DEFAULT 0, so the unique key cannot be bypassed by NULL",
      "legacy state rows survive verbatim under the sentinel sube_id = 0",
      "no closing or approval row is changed by the migration",
      "actor columns are additive and nullable, so legacy rows stay valid",
      "existing approved rows keep a NULL actor instead of being back-filled",
      "a second full rerun is a no-op",
      "the idempotent rerun changes neither schema nor data",
      "an already-migrated schema is refused as an apply preimage"
    ]) {
      expect(result.stdout).toContain(marker);
    }

    expect(result.stdout).toContain("verify-migration-control-plane-preflight-mysql: OK");
  });
});
