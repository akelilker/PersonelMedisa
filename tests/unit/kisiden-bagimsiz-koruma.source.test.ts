import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * Aşama B: hesap koruması kişiye bağlı değildir. Koruma `users.silinmesi_korunur`
 * bayrağı + rol kuralıyla (GENEL_YONETICI hedef engeli) sağlanır. Kişi anahtarı /
 * kullanıcı adı yalnız aşağıdaki TARİHSEL (tek seferlik, Medisa'ya özgü) sahiplerde kalabilir.
 */
const root = process.cwd();
const TARIHSEL_KAPSAM = new Set([
  // 099 migration apply/preflight sahibi: canlıda uygulandı; kayıt anahtarları 099 şemasının parçası.
  "api/src/Services/Auth/KullaniciKaliciSilMigrationService.php",
  // Final kapanış paketi: tarihsel kimlik kanıtı (id → username snapshot).
  "api/src/Services/Operations/FinalClosePackage.php",
  // Tek seferlik first-login rollout rapor sözleşmesi (replay engelli).
  "api/src/Services/Auth/PersonelAccountOnboardingService.php",
  "api/src/Services/Auth/PersonelFirstLoginCredentialsApplyReport.php",
  "api/src/Services/Auth/PersonelFirstLoginCredentialsPreflightReport.php",
  "api/src/Services/Auth/BoundUserCanonicalUsernameReconciliationService.php"
]);

describe("kişiden bağımsız hesap koruması (Aşama B)", () => {
  it("kişi anahtarı / kullanıcı adı yalnız tarihsel sahiplerde", () => {
    const out = execFileSync(
      "git",
      ["grep", "-l", "-i", "-E", "ILKER_A|SERHAN_KOSE|'ilkerA'|PROTECTED_USERNAMES", "--", "api/src"],
      { cwd: root, encoding: "utf8" }
    )
      .split(/\r?\n/)
      .filter(Boolean);
    const disarida = out.filter((file) => !TARIHSEL_KAPSAM.has(file));
    expect(disarida).toEqual([]);
  });

  it("Kalıcı Sil hazırlığı belirli kişi anahtarı / sayısı aramaz; bayrak sapması fail-closed", () => {
    const src = readFileSync(resolve(root, "api/src/Services/Auth/KullaniciKaliciSilService.php"), "utf8");
    const start = src.indexOf("private static function hasProtectedAccountsReady");
    const body = src.slice(start, src.indexOf("private static function", start + 10));
    expect(body).not.toMatch(/ILKER_A|SERHAN_KOSE|=== 2/);
    expect(body).toContain("u.id IS NULL OR u.silinmesi_korunur <> 1");
    expect(body).toContain("=== 0");
    // Hedef koruması bayrak + rol kuralıyla.
    expect(src).toMatch(/\(int\) \$target\['silinmesi_korunur'\] === 1/);
  });

  it("first-login geçişi silinmesi_korunur hesaplara dokunmaz", () => {
    const src = readFileSync(resolve(root, "api/src/Services/Auth/PersonelAccountOnboardingService.php"), "utf8");
    expect(src).toContain("|| self::isProtectedCredentialRow($row)");
    expect(src).toContain("UsersSchema::hasSilinmesiKorunur($pdo) ? ', u.silinmesi_korunur' : ''");
  });
});
