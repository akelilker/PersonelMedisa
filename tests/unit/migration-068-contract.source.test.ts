import { readdirSync, readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const migrationDirectory = "api/migrations";
const migrationNames = readdirSync(migrationDirectory)
  .filter((name) => /^\d{3}_.+\.sql$/.test(name))
  .sort();
const migrationNumbers = migrationNames.map((name) => Number.parseInt(name.slice(0, 3), 10));

describe("migration 068 actor identity audit contract", () => {
  it("keeps a unique, numeric, ordered migration chain including 068→075 by name", () => {
    expect(new Set(migrationNumbers).size).toBe(migrationNumbers.length);
    expect(migrationNumbers).toEqual([...migrationNumbers].sort((a, b) => a - b));

    const tail = [
      "068_sgk_actor_identity_lifecycle_audit.sql",
      "069_personel_credential_onboarding.sql",
      "070_offline_mutation_idempotency.sql",
      "071_org_hierarchy_authorization.sql",
      "072_org_reference_short_codes.sql",
      "073_test_fixture_personel_archive.sql",
      "074_qr_attendance_correction_and_inbox.sql",
      "075_personel_account_activation.sql",
    ];
    for (const name of tail) {
      expect(migrationNames).toContain(name);
    }
    for (let i = 1; i < tail.length; i += 1) {
      expect(migrationNames.indexOf(tail[i - 1]!)).toBeLessThan(migrationNames.indexOf(tail[i]!));
    }
  });

  it("keeps 068 append-only and attributable", () => {
    const migration = readFileSync(`${migrationDirectory}/068_sgk_actor_identity_lifecycle_audit.sql`, "utf8");
    expect(migration).toContain("CREATE TABLE IF NOT EXISTS actor_identity_audits");
    expect(migration).toContain("changed_by_user_id");
    expect(migration).toContain("created_at");
    expect(migration).toContain("fk_actor_identity_audits_identity");
    expect(migration).toContain("fk_actor_identity_audits_changed_by");
    expect(migration).not.toContain("DROP TABLE");
    expect(migration).not.toContain("ALTER TABLE 067");
  });
});
