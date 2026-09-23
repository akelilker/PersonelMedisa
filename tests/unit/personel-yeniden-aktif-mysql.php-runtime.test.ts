import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelYenidenAktifMysqlTestRunner.php");

describe("Personel yeniden aktif (PASIF -> AKTIF) MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("reactivates through the canonical lifecycle owner and fails closed on every guard", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-yeniden-aktif-mysql: OK");
    expect(result.stdout).not.toContain("[FAIL]");

    // Transaction ownership + the PASIF -> AKTIF transition itself.
    expect(result.stdout).toContain("[PASS] reactivation requires an active transaction");
    expect(result.stdout).toContain("[PASS] happy path flips aktif_durum to AKTIF");
    expect(result.stdout).toContain("[PASS] happy path reports cancelled exit surec");

    // The exit process is cancelled in place, never deleted.
    expect(result.stdout).toContain("[PASS] exit surec row is preserved (not deleted)");
    expect(result.stdout).toContain("[PASS] exit surec state becomes IPTAL");

    // Append-only evidence, real MySQL trigger proof.
    expect(result.stdout).toContain("[PASS] reactivation writes an append-only audit row");
    expect(result.stdout).toContain("[PASS] audit preimage keeps the old value (PASIF)");
    expect(result.stdout).toContain("[PASS] audit postimage holds the new value (AKTIF)");
    expect(result.stdout).toContain("[PASS] audit rows cannot be updated (append-only trigger)");
    expect(result.stdout).toContain("[PASS] audit rows cannot be deleted (append-only trigger)");
    expect(result.stdout).toContain("[PASS] pre-existing manifest row is byte-identical after reactivation");

    // Idempotent replay for an already-AKTIF record.
    expect(result.stdout).toContain("[PASS] already AKTIF reports replay");
    expect(result.stdout).toContain("[PASS] already AKTIF appended no audit row");
    expect(result.stdout).toContain("[PASS] already AKTIF leaves the historical exit surec untouched");

    // Fail-closed guards.
    expect(result.stdout).toContain("REACTIVATE_EXIT_SUREC_MISSING");
    expect(result.stdout).toContain("REACTIVATE_EXIT_SUREC_NOT_UNIQUE");
    expect(result.stdout).toContain("LEGAL_HOLD_ACTIVE");
    expect(result.stdout).toContain("REACTIVATE_TEST_FIXTURE_HIDDEN");
    expect(result.stdout).toContain("REACTIVATE_LEGAL_HOLD_SCHEMA_NOT_READY");
    expect(result.stdout).toContain("PERSONEL_ORGANIZASYON_AUDIT_SCHEMA_NOT_READY");
    expect(result.stdout).toContain("PERSONEL_SGK_ISVEREN_REQUIRED");
    expect(result.stdout).toContain("PERSONEL_SGK_SIRKET_UYUSMAZligi");
    expect(result.stdout).toContain("REACTIVATE_KAPSAM_TRANSITION_INVALID");

    // DIS_KAYNAK -> IC_PERSONEL identity contract.
    expect(result.stdout).toContain("[PASS] DIS -> IC happy path persists IC_PERSONEL");
    expect(result.stdout).toContain("[PASS] DIS -> IC rejects incomplete internal identity");

    // Atomicity after the mutation.
    expect(result.stdout).toContain("[PASS] atomic rollback restores PASIF");
    expect(result.stdout).toContain("[PASS] atomic rollback removes the pre-flip manifests");

    // The archive gate is neither bypassed nor weakened.
    expect(result.stdout).toContain("[PASS] PersonelArchiveGate keeps its read-only contract");
    expect(result.stdout).toContain(
      "[PASS] reactivation owner never calls into the archive gate or bypasses its write lock"
    );
  });
});
