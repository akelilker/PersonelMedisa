import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const controllerPath = resolve(process.cwd(), "api/src/Controllers/PuantajController.php");
const controllerSource = readFileSync(controllerPath, "utf8");

describe("PuantajController gec/erken dakika parity source", () => {
  it("accepts Gorevde_Calisma as canonical dayanak", () => {
    expect(controllerSource).toContain("'Gorevde_Calisma'");
  });

  it("reads nullable dakika fields on upsert", () => {
    expect(controllerSource).toContain("'gec_kalma_dakika' => self::readNullableInt(");
    expect(controllerSource).toContain("'erken_cikis_dakika' => self::readNullableInt(");
  });

  it("persists dakika columns on insert and update", () => {
    expect(controllerSource).toContain("gec_kalma_dakika, erken_cikis_dakika");
    expect(controllerSource).toContain(":gec_kalma_dakika, :erken_cikis_dakika");
    expect(controllerSource).toContain("gec_kalma_dakika = :gec_kalma_dakika");
    expect(controllerSource).toContain("erken_cikis_dakika = :erken_cikis_dakika");
  });

  it("maps dakika columns in API responses", () => {
    expect(controllerSource).toContain("'gec_kalma_dakika' => self::mapNullableInt(");
    expect(controllerSource).toContain("'erken_cikis_dakika' => self::mapNullableInt(");
  });

  it("copies dakika columns into monthly seal snapshot rows", () => {
    expect(controllerSource).toMatch(/function insertSealRows/);
    expect(controllerSource).toContain("'gec_kalma_dakika' => $row['gec_kalma_dakika'] ?? null");
    expect(controllerSource).toContain("'erken_cikis_dakika' => $row['erken_cikis_dakika'] ?? null");
  });
});
