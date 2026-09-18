import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { describe, expect, it } from "vitest";
import { buildPersonelUsernameFromNames } from "../../src/features/yonetim/personelUsernameFromNames";
import { shouldEmitGlobalAuthForbidden } from "../../src/lib/api-forbidden-policy";

const root = resolve(process.cwd());
const read = (relative: string) => readFileSync(resolve(root, relative), "utf8");

const service = read("api/src/Services/Auth/PersonelAccountOnboardingService.php");
const worker = read("api/bin/personel-first-login-credentials.php");

/** Ad/soyad STDIN'den verilir; Windows argv encoding riski yok. */
function phpRule(kind: "username" | "password", ad: string, soyad: string): string {
  const method =
    kind === "username" ? "buildPersonelUsernameFromNames" : "buildPersonelInitialPasswordFromNames";
  const code = [
    "require 'api/src/bootstrap.php';",
    "$in = json_decode(stream_get_contents(STDIN), true);",
    "echo Medisa\\Api\\Services\\Auth\\PersonelAccountOnboardingService::" + method + "($in[0], $in[1]);"
  ].join(" ");
  const result = spawnSync("php", ["-r", code], {
    encoding: "utf8",
    cwd: process.cwd(),
    input: JSON.stringify([ad, soyad])
  });
  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || "php rule evaluation failed");
  }
  return (result.stdout || "").trim();
}

describe("PERSONEL canonical first-login credential kurallari", () => {
  it("username kurali: ilk ad kucuk ASCII + soyad ilk harfi buyuk ASCII", () => {
    const cases: Array<[string, string, string]> = [
      ["Serhan", "Köse", "serhanK"],
      ["Musa", "Taş", "musaT"],
      ["İlker", "AKEL", "ilkerA"],
      ["Mehmet Ali", "YILMAZ", "mehmetY"]
    ];
    for (const [ad, soyad, expected] of cases) {
      expect(buildPersonelUsernameFromNames(ad, soyad)).toBe(expected);
      expect(phpRule("username", ad, soyad)).toBe(expected);
    }
  });

  it("baslangic sifresi kurali: soyad ASCII TitleCase + 123", () => {
    const cases: Array<[string, string, string]> = [
      ["Serhan", "Köse", "Kose123"],
      ["Musa", "Taş", "Tas123"],
      ["Serhan", "KÖSE", "Kose123"],
      ["Emre", "ÇELİK", "Celik123"],
      ["X", "ŞENAY", "Senay123"]
    ];
    for (const [ad, soyad, expected] of cases) {
      expect(phpRule("password", ad, soyad)).toBe(expected);
    }
    expect(phpRule("password", "Serhan", "Köse")).toMatch(/^[A-Z][a-z0-9]*123$/);
  });
});

describe("PERSONEL canonical first-login gecisi (owner kontratlari)", () => {
  it("canonical sahip cohort'u ve hedef state'i tanimlar", () => {
    expect(service).toContain("migrateCanonicalFirstLoginCredentials");
    expect(service).toContain("PROTECTED_USERNAMES = ['ilkerA']");
    expect(service).toContain("buildPersonelInitialPasswordFromNames");
    expect(service).toContain("EVENT_FIRST_LOGIN_CREDENTIALS_APPLIED = 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLIED'");
    expect(service).toContain("PasswordHasher::hash(");
    expect(service).toContain("activation_required = 0");
    expect(service).toContain("must_change_password = 1");
    // Cohort yalniz aktif, bagli ve bagli personeli AKTIF satirlar icindir.
    expect(service).toContain("WHERE u.rol = 'PERSONEL'");
    expect(service).toMatch(/user_durum'\] !== 'AKTIF'/);
    expect(service).toMatch(/personel_aktif_durum[\s\S]{0,80}!== 'AKTIF'/);
    // Degismeyen alanlar SET listesinde yok; cohort guard WHERE'de kalir.
    const methodStart = service.indexOf("migrateCanonicalFirstLoginCredentials");
    const updateStart = service.indexOf("UPDATE users", methodStart);
    const setClause = service.slice(updateStart, service.indexOf("WHERE id = :id", methodStart));
    const whereClause = service.slice(
      service.indexOf("WHERE id = :id", methodStart),
      service.indexOf("AND username = :old_username", methodStart)
    );
    expect(setClause).toContain("SET username = :new_username");
    expect(setClause).toContain("password_hash = :password_hash");
    expect(setClause).not.toContain("rol =");
    expect(setClause).not.toContain("durum =");
    expect(setClause).not.toContain("personel_id =");
    expect(whereClause).toContain("rol = 'PERSONEL'");
  });

  it("collision guard mutation'dan once fail-closed durur", () => {
    expect(service).toContain("ERR_CANONICAL_USERNAME_COLLISION = 'PERSONEL_CANONICAL_USERNAME_COLLISION'");
    expect(service).toContain("detectCanonicalUsernameCollisions");
    expect(service).toMatch(/count\(\$collisions\) > 0[\s\S]*'blocked' => true[\s\S]*beginTransaction\(\)/);
    expect(service).toContain("'outside_cohort'");
  });

  it("plaintext sifre ve hash hicbir yere yazilmaz", () => {
    expect(service).not.toMatch(/error_log\s*\(/);
    expect(service).not.toMatch(/password_hash'\s*=>\s*self::build/);
    expect(worker).not.toMatch(/error_log\s*\(/);
    expect(worker).not.toMatch(/buildPersonelInitialPasswordFromNames\s*\(/);
    expect(worker).not.toMatch(/\b(UPDATE|INSERT|DELETE)\b/);
  });

  it("ops worker yalniz acik onayla mutation yapar ve web'den erisilemez", () => {
    expect(worker).toContain("PHP_SAPI !== 'cli'");
    expect(worker).toContain("PERSONEL_FIRST_LOGIN_CREDENTIALS");
    expect(worker).toContain("--apply");
    expect(worker).toContain("--confirm=");
    expect(worker).toContain("--actor-user-id=");
    expect(worker).toContain("ACTOR_USER_ID_REQUIRED");
    expect(worker).toContain("migrateCanonicalFirstLoginCredentials");
  });
});

describe("zorunlu sifre degisimi global /yetkisiz yonlendirmesi uretmez", () => {
  it("PASSWORD_CHANGE_REQUIRED 403'u global forbidden sayilmaz", () => {
    expect(
      shouldEmitGlobalAuthForbidden("/gunluk-puantaj/2/2026-01-01", "GET", "PASSWORD_CHANGE_REQUIRED")
    ).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/yonetim/kullanicilar", "GET", "password_change_required")).toBe(false);
  });

  it("diger 403 davranislari ve diger kodlar degismedi", () => {
    expect(shouldEmitGlobalAuthForbidden("/gunluk-puantaj/2/2026-01-01", "GET")).toBe(true);
    expect(shouldEmitGlobalAuthForbidden("/unknown-endpoint", "GET", "FORBIDDEN")).toBe(true);
    expect(shouldEmitGlobalAuthForbidden("/personeller", "GET", "FORBIDDEN")).toBe(false);
  });

  it("api-client 403 kararinda hata kodunu politikaya gecirir", () => {
    const client = read("src/api/api-client.ts");
    expect(client).toContain(
      "shouldEmitGlobalAuthForbidden(path, method, extractFirstApiError(payload)?.code ?? null)"
    );
  });
});
