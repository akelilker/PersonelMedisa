import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

/** Aşama C: yeni şirket kurulumu — ilk Genel Yönetici owner'ı + boş katalogda 067. */
const root = process.cwd();
const read = (path: string) => readFileSync(resolve(root, path), "utf8");

describe("yeni şirket kurulumu (Aşama C)", () => {
  it("ilk yönetici CLI web'e kapalı, parolayı argümandan almaz", () => {
    const cli = read("api/bin/ilk-yonetici-olustur.php");
    expect(cli).toContain("if (PHP_SAPI !== 'cli') {");
    expect(cli).toContain("http_response_code(404);");
    expect(cli).toContain("getenv('MEDISA_ILK_YONETICI_PAROLA')");
    expect(cli).toContain("getopt('', ['kullanici-adi:', 'ad-soyad:'])");
    expect(cli).not.toMatch(/parola:'|password:'/);
  });

  it("owner yalnız hiç Genel Yönetici yokken, kilitli ve tek seferlik çalışır; kişiye bağlı değildir", () => {
    const svc = read("api/src/Services/Auth/IlkYoneticiKurulumService.php");
    expect(svc).toContain("SELECT GET_LOCK(:name, 10)");
    expect(svc).toContain("\"SELECT id FROM users WHERE rol = '\" . self::ROL . \"' FOR UPDATE\"");
    expect(svc).toContain("CODE_ALREADY = 'GENEL_YONETICI_ZATEN_VAR'");
    expect(svc).toContain("PasswordHasher::hash($password)");
    expect(svc).toContain("$cols[] = 'silinmesi_korunur';");
    expect(svc).not.toMatch(/ilkerA|ILKER_A|SERHAN|medisa\.com|karmotor/i);
  });

  it("runner: 067 yalnız tüm hedef tablolar boşken çalıştırılmadan ledger'a yazılır", () => {
    const runner = read("api/src/Database/MigrationRunner.php");
    expect(runner).toContain("'067' => ['departmanlar', 'bolumler', 'birimler', 'personeller'],");
    expect(runner).toContain("if (self::isDataCorrectionOnEmptyCatalog($pdo, $version)) {");
    expect(runner).toContain("if ($hasRow) {\n                return false;");
    // Ledger checksum aynı kalır; 067 dosyası değişmez.
    expect(runner).toContain("':checksum' => $migration['checksum'],");
  });
});
