import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const hookSource = readFileSync(resolve(process.cwd(), "src/hooks/useBildirimler.ts"), "utf8");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/BildirimlerController.php"),
  "utf8"
);
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/005_gunluk_bildirimler.sql"),
  "utf8"
);

describe("S74-D1-D1 frontend günlük bildirim tarih kontratı", () => {
  it("boş tarihi açıkça reddeder ve submit sırasında bugüne fallback yapmaz", () => {
    expect(hookSource).toContain('setCreateErrorMessage("Tarih zorunludur.")');
    expect(hookSource).toContain("tarih: createForm.tarih");
    expect(hookSource).not.toContain("tarih: createForm.tarih || getTodayIsoDate()");
  });
});

describe("S74-D1-D1 backend günlük bildirim tarih kontratı", () => {
  it("tarih alanını zorunlu YYYY-MM-DD olarak doğrular", () => {
    expect(controllerSource).toContain(
      "self::requireDate($body, 'tarih', 'Tarih zorunludur.')"
    );
    expect(controllerSource).toMatch(/private static function requireDate[\s\S]*?self::isValidDate\(\$value\)/);
  });

  it("request tarihini doğrudan tarih kolonuna bind eder", () => {
    expect(controllerSource).toContain("personel_id, tarih, sube_id");
    expect(controllerSource).toContain(":personel_id, :tarih, :sube_id");
    expect(controllerSource).toContain("'tarih' => $payload['tarih']");
  });

  it("liste ve detail mapperında operasyon tarihi kolonunu kullanır", () => {
    expect(controllerSource).toContain("'tarih' => (string) $row['tarih']");
    expect(controllerSource).not.toContain("'tarih' => (string) $row['created_at']");
  });

  it("tarih kolonunu default olmadan DATE NOT NULL tutar", () => {
    expect(migrationSource).toMatch(/^\s*tarih DATE NOT NULL,\s*$/m);
    expect(migrationSource).not.toMatch(/tarih DATE NOT NULL DEFAULT/i);
    expect(migrationSource).not.toMatch(/\bCURRENT_DATE\b/i);
  });
});
