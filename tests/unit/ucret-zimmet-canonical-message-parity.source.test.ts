import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const CANONICAL = {
  ucretTutari: "Ücret tutarı sıfırdan büyük olmalıdır.",
  ucretTuru: "Ücret türü BRÜT veya NET olmalıdır.",
  urunTuru: "Ürün türü zorunludur."
} as const;

const ASCII_LEGACY = {
  ucretTutari: "Ucret tutari sifirdan buyuk olmalidir.",
  ucretTuru: "Ucret turu BRUT veya NET olmalidir.",
  urunTuru: "Urun turu zorunludur.",
  ucretTutariVeyaTarih: "Ucret tutari veya tarih gecersiz."
} as const;

function readOwner(relativePath: string) {
  return readFileSync(resolve(process.cwd(), relativePath), "utf8");
}

const OWNERS = {
  mockDemo: readOwner("src/api/mock-demo.ts"),
  mockApi: readOwner("tests/e2e/helpers/mock-api.ts"),
  personelUcretService: readOwner("api/src/Services/PersonelUcretService.php"),
  personellerController: readOwner("api/src/Controllers/PersonellerController.php"),
  zimmetlerController: readOwner("api/src/Controllers/ZimmetlerController.php"),
  personelUcretCreateModal: readOwner(
    "src/features/personeller/components/personel-dosya/PersonelUcretCreateModal.tsx"
  ),
  personelZimmetCreate: readOwner("src/hooks/usePersonelZimmetCreate.ts")
} as const;

describe("S93-E2D-A ucret ve zimmet canonical mesaj parity", () => {
  it("eski ASCII ucret/zimmet mesajlarini ilgili owner dosyalarinda tutmaz", () => {
    const combined = Object.values(OWNERS).join("\n");

    expect(combined).not.toContain(ASCII_LEGACY.ucretTutari);
    expect(combined).not.toContain(ASCII_LEGACY.ucretTuru);
    expect(combined).not.toContain(ASCII_LEGACY.urunTuru);
    expect(combined).not.toContain(ASCII_LEGACY.ucretTutariVeyaTarih);
  });

  it("demo, e2e mock ve PHP ucret ownerlari canonical ucret mesajlarini tutar", () => {
    for (const source of [OWNERS.mockDemo, OWNERS.mockApi, OWNERS.personelUcretService]) {
      expect(source).toContain(CANONICAL.ucretTutari);
      expect(source).toContain(CANONICAL.ucretTuru);
    }
    expect(OWNERS.personellerController).toContain(CANONICAL.ucretTutari);
    expect(OWNERS.personelUcretCreateModal).toContain(CANONICAL.ucretTutari);
  });

  it("demo, e2e mock, PHP ve FE zimmet ownerlari canonical urun turu mesajini tutar", () => {
    expect(OWNERS.mockDemo).toContain(CANONICAL.urunTuru);
    expect(OWNERS.mockApi).toContain(CANONICAL.urunTuru);
    expect(OWNERS.zimmetlerController).toContain(CANONICAL.urunTuru);
    expect(OWNERS.personelZimmetCreate).toContain(CANONICAL.urunTuru);
  });
});
