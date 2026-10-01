import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { buildPersonelSelfIdentityView } from "../../src/features/self-service/personel-self-identity-view";
import type { MeIdentity } from "../../src/types/self-service";

const root = resolve(process.cwd());
const read = (relative: string) => readFileSync(resolve(root, relative), "utf8");

describe("personel cinsiyet (095 + /me self identity)", () => {
  it("adds migration 095 after 094 with idempotent nullable ENUM and no seed", () => {
    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations).toContain("094_attendance_no_event_day.sql");
    expect(migrations.at(-1)).toBe("095_personel_cinsiyet.sql");
    const idx094 = migrations.indexOf("094_attendance_no_event_day.sql");
    expect(migrations[idx094 + 1]).toBe("095_personel_cinsiyet.sql");

    const sql = read("api/migrations/095_personel_cinsiyet.sql");
    expect(sql).toContain("information_schema.COLUMNS");
    expect(sql).toContain("cinsiyet");
    expect(sql).toContain("ENUM(''Erkek'', ''Kadın'')");
    expect(sql).toContain("NULL");
    expect(sql).not.toMatch(/\bINSERT\b/i);
    expect(sql).not.toMatch(/\bUPDATE\b/i);
  });

  it("wires SelfPersonelContext and GET /me personel payload like kan_grubu", () => {
    const ctx = read("api/src/Services/SelfService/SelfPersonelContext.php");
    expect(ctx).toContain("PersonelCinsiyetSchema::isReady");
    expect(ctx).toContain("'cinsiyet' =>");
    expect(ctx).toContain("p.cinsiyet");

    const me = read("api/src/Controllers/MeController.php");
    expect(me).toContain("'cinsiyet' => \$ctx['cinsiyet'] ?? null");
    expect(me).toContain("'kan_grubu' => \$ctx['kan_grubu'] ?? null");
  });

  it("validates canonical create/update/incomplete writes without inventing defaults", () => {
    const validator = read("api/src/Services/Personel/PersonelCanonicalValidator.php");
    expect(validator).toContain("validCinsiyetValues");
    expect(validator).toContain("'Erkek', 'Kadın'");
    expect(validator).toContain("normalizeOptionalCinsiyet");

    const create = read("api/src/Services/Personel/PersonelCreateService.php");
    expect(create).toContain("PersonelCinsiyetSchema::assertReadyForWrite");
    expect(create).toContain("array_key_exists('cinsiyet', \$payload)");

    const incomplete = read("api/src/Services/Personel/PersonelIncompleteCreateService.php");
    expect(incomplete).toContain("validCinsiyetValues");

    const update = read("api/src/Services/Personel/PersonelBasicUpdateService.php");
    expect(update).toContain("'cinsiyet'");
  });

  it("shows stored gender in self identity view and never invents missing values", () => {
    const identity: MeIdentity = {
      user_id: 1,
      username: "p",
      ad_soyad: "Test User",
      rol: "PERSONEL",
      personel_id: 2,
      personel: {
        id: 2,
        ad: "Test",
        soyad: "User",
        ad_soyad: "Test User",
        sube_id: 1,
        sube_ad: "Merkez",
        departman_id: null,
        departman_ad: null,
        gorev_id: null,
        gorev_ad: null,
        aktif_durum: "AKTIF",
        cinsiyet: "Kadın"
      }
    };
    expect(buildPersonelSelfIdentityView(identity)?.cinsiyet).toBe("Kadın");
    expect(
      buildPersonelSelfIdentityView({ ...identity, personel: { ...identity.personel, cinsiyet: null } })
        ?.cinsiyet
    ).toBe("-");
  });
});
