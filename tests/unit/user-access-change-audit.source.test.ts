import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const root = resolve(__dirname, "../..");
const migration = readFileSync(
  resolve(root, "api/migrations/082_user_erisim_degisiklik_auditleri.sql"),
  "utf8",
);
const writer = readFileSync(
  resolve(root, "api/src/Services/Organizasyon/OrganizasyonAuditWriter.php"),
  "utf8",
);
const controller = readFileSync(
  resolve(root, "api/src/Controllers/YonetimController.php"),
  "utf8",
);
const preflight = readFileSync(
  resolve(root, "api/src/Database/MigrationPreflightReport.php"),
  "utf8",
);

describe("migration 082 declares an immutable, attributable access-change owner", () => {
  it("creates the table additively and idempotently", () => {
    expect(migration).toContain(
      "CREATE TABLE IF NOT EXISTS user_erisim_degisiklik_auditleri",
    );
    expect(migration).toContain("ENGINE=InnoDB");
    expect(migration).toContain("PACK082_BLOCKER: users table missing");
  });

  it("writes no business row and drops nothing", () => {
    expect(migration).not.toMatch(/\bINSERT\s+INTO\s+(?!user_erisim)/i);
    expect(migration).not.toMatch(/\bUPDATE\s+users\b/i);
    expect(migration).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migration).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(migration).not.toMatch(/\bTRUNCATE\b/i);
  });

  it("leaves the completed 080 and 081 owners alone", () => {
    for (const previous of [
      "personel_sube_degisiklik_auditleri",
      "sube_olusturma_auditleri",
      "user_org_scope_auditleri",
      "user_erisim_kaldirma_auditleri",
    ]) {
      expect(migration).not.toMatch(
        new RegExp(`(ALTER|DROP)\\s+TABLE\\s+${previous}`, "i"),
      );
    }
  });

  it("records every access axis with an attributable actor and target", () => {
    for (const column of [
      "actor_user_id",
      "target_user_id",
      "event_type",
      "eski_durum",
      "yeni_durum",
      "eski_rol",
      "yeni_rol",
      "eski_username",
      "yeni_username",
      "eski_personel_id",
      "yeni_personel_id",
      "request_hash",
      "created_at",
    ]) {
      expect(migration).toContain(column);
    }
    expect(migration).toContain("fk_ueda_actor_user");
    expect(migration).toContain("fk_ueda_target_user");
  });

  it("stores no credential material", () => {
    const ddl = migration
      .split("\n")
      .filter((line) => !line.trimStart().startsWith("--"))
      .join("\n");
    expect(ddl).not.toMatch(/password/i);
    expect(ddl).not.toMatch(/token/i);
    expect(ddl).not.toMatch(/davet|invitation/i);
  });

  it("allows exactly the six access events and refuses a no-op row", () => {
    for (const event of [
      "ACCESS_RESTORE",
      "STATUS_CHANGE",
      "ROLE_CHANGE",
      "USERNAME_CHANGE",
      "PERSONEL_BINDING_CHANGE",
      "COMBINED_ACCESS_CHANGE",
    ]) {
      expect(migration).toContain(`'${event}'`);
    }
    expect(migration).toContain("chk_ueda_event_type");
    expect(migration).toContain("chk_ueda_changed");
  });

  it("enforces append-only in the database rather than only in PHP", () => {
    expect(migration).toContain("trg_ueda_no_update");
    expect(migration).toContain("trg_ueda_no_delete");
    expect(migration).toContain("BEFORE UPDATE ON user_erisim_degisiklik_auditleri");
    expect(migration).toContain("BEFORE DELETE ON user_erisim_degisiklik_auditleri");
    expect(migration).toContain("DROP TRIGGER IF EXISTS trg_ueda_no_update");
    expect(migration).toContain("DROP TRIGGER IF EXISTS trg_ueda_no_delete");
  });

  it("indexes the ways the history is actually read", () => {
    for (const index of [
      "idx_ueda_target_created",
      "idx_ueda_actor_created",
      "idx_ueda_event_created",
      "idx_ueda_request_hash",
    ]) {
      expect(migration).toContain(index);
    }
  });
});

describe("the access-change writer is a real, fail-closed owner", () => {
  it("owns the 082 table under its own error code", () => {
    expect(writer).toContain(
      "public const USER_ACCESS_CHANGE_TABLE = 'user_erisim_degisiklik_auditleri';",
    );
    expect(writer).toContain(
      "public const ACCESS_CHANGE_SCHEMA_NOT_READY = 'USER_ACCESS_AUDIT_SCHEMA_NOT_READY';",
    );
    expect(writer).toContain("public static function assertAccessChangeReady(PDO $pdo): void");
    expect(writer).toMatch(/assertAccessChangeReady[\s\S]{0,400}?ACCESS_CHANGE_SCHEMA_NOT_READY/);
  });

  it("collapses a multi-axis request into a single combined event", () => {
    expect(writer).toContain(
      "public static function resolveAccessEventType(array $before, array $after): ?string",
    );
    expect(writer).toMatch(
      /if \(count\(\$changed\) > 1\) \{\s*return self::ACCESS_EVENT_COMBINED;/,
    );
    expect(writer).toMatch(/count\(\$changed\) === 0[\s\S]{0,60}return null;/);
  });

  it("types a lone reactivation as a restore", () => {
    expect(writer).toMatch(
      /\$before\['durum'\] === 'PASIF' && \(string\) \$after\['durum'\] === 'AKTIF'\)\s*\?\s*self::ACCESS_EVENT_RESTORE/,
    );
  });

  it("writes the audit row inside the caller's transaction without swallowing failure", () => {
    const record = writer.slice(writer.indexOf("function recordUserAccessChange"));
    const body = record.slice(0, record.indexOf("\n    /**", 1));
    expect(body).toContain("self::assertAccessChangeReady($pdo);");
    expect(body).toContain("INSERT INTO ' . self::USER_ACCESS_CHANGE_TABLE");
    expect(body).not.toMatch(/\btry\b/);
    expect(body).not.toMatch(/\bcatch\b/);
    expect(body).not.toMatch(/beginTransaction|commit|rollBack/);
    expect(body).toContain("$context->actorUserId()");
    expect(body).toContain("$context->requestHash()");
  });

  it("leaves an untouched axis null instead of repeating its own value", () => {
    expect(writer).toContain("'eski_durum' => $durumChanged ? (string) $before['durum'] : null,");
    expect(writer).toContain("'eski_rol' => $rolChanged ? (string) $before['rol'] : null,");
    expect(writer).toContain(
      "'eski_username' => $usernameChanged ? (string) $before['username'] : null,",
    );
    expect(writer).toContain("'eski_personel_id' => $personelChanged ? $beforePersonelId : null,");
  });

  it("never carries credential material into the row", () => {
    const record = writer.slice(writer.indexOf("function recordUserAccessChange"));
    const body = record.slice(0, record.indexOf("\n    /**", 1));
    expect(body).not.toMatch(/password|token|sifre/i);
  });

  it("keeps the revocation owner of 081 as a separate table", () => {
    expect(writer).toContain(
      "public const USER_ACCESS_REVOKE_TABLE = 'user_erisim_kaldirma_auditleri';",
    );
    expect(writer).toContain("public static function recordUserAccessRevoke(");
  });
});

describe("the update owner cannot change access unaudited", () => {
  const owner = controller.slice(
    controller.indexOf("public static function kullaniciGuncelle"),
    controller.indexOf("public static function kullaniciErisimKaldir"),
  );

  it("computes the before and after image of all four access axes", () => {
    for (const axis of ["durum", "rol", "username", "personel_id"]) {
      expect(owner).toMatch(new RegExp(`\\$accessBefore = \\[[\\s\\S]*?'${axis}'`));
      expect(owner).toMatch(new RegExp(`\\$accessAfter = \\[[\\s\\S]*?'${axis}'`));
    }
  });

  it("asserts the audit schema before the transaction opens", () => {
    const gate = owner.indexOf("OrganizasyonAuditWriter::assertAccessChangeReady($pdo)");
    const transaction = owner.indexOf("$pdo->beginTransaction()");
    expect(gate).toBeGreaterThan(0);
    expect(transaction).toBeGreaterThan(gate);
  });

  it("only gates when an access axis actually moves", () => {
    expect(owner).toContain(
      "if (OrganizasyonAuditWriter::resolveAccessEventType($accessBefore, $accessAfter) !== null) {",
    );
  });

  it("writes the audit inside the same transaction as the business change", () => {
    const transaction = owner.indexOf("$pdo->beginTransaction()");
    const audit = owner.indexOf("OrganizasyonAuditWriter::recordUserAccessChange(");
    const commit = owner.indexOf("$pdo->commit()");
    expect(audit).toBeGreaterThan(transaction);
    expect(commit).toBeGreaterThan(audit);
  });

  it("records the audit after the binding, so the after-image is the committed one", () => {
    const binding = owner.indexOf("UserPersonelBindingService::");
    const audit = owner.indexOf("OrganizasyonAuditWriter::recordUserAccessChange(");
    expect(binding).toBeGreaterThan(0);
    expect(audit).toBeGreaterThan(binding);
  });

  it("keeps the scope owner of 080 in place", () => {
    expect(owner).toContain("OrganizasyonAuditWriter::recordUserOrgScopeChange(");
    expect(owner).toContain("OrganizasyonAuditWriter::assertReady(");
  });
});

describe("the control plane authorizes the 084 to 085 round and nothing else", () => {
  it("pins production tip 084 as the only apply-ready preimage", () => {
    expect(preflight).toContain("public const EXPECTED_APPLIED_TIP = '084';");
    expect(preflight).toContain("'085' => '085_gunluk_bildirim_duzeltme_auditleri.sql',");
    expect(preflight).toContain(
      "'086' => '086_personel_historical_exit_date_correction_auditleri.sql',",
    );
    expect(preflight).not.toContain("'084' => '085_gunluk_bildirim_duzeltme_auditleri.sql',");
  });

  it("proves the completed round is present rather than trusting the ledger tip", () => {
    expect(preflight).toContain("PREIMAGE_PREDECESSOR_AUDIT_TABLE_MISSING");
    expect(preflight).toContain("PREIMAGE_PREDECESSOR_ROLE_MISSING");
    expect(preflight).toContain("private const PREDECESSOR_ROLE = 'IK_PERSONELI';");
  });

  it("separates a completed round from an unexpected empty chain", () => {
    expect(preflight).toContain("ROUND_ALREADY_COMPLETE");
    expect(preflight).toContain("APPLIED_TIP_UNEXPECTED");
  });
});
