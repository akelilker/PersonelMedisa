import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { beforeAll, describe, expect, it } from "vitest";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";
import {
  filterSgkIsverenOptionsForCreate,
  filterSgkIsverenOptionsForSube
} from "../../src/features/personeller/personel-create-org-deps";
import { buildCreatePersonelPayload } from "../../src/features/personeller/personel-create-utils";
import { INITIAL_CREATE_PERSONEL_FORM } from "../../src/hooks/usePersoneller";

const root = process.cwd();
const read = (rel: string) => readFileSync(resolve(root, rel), "utf8");

const disForm = {
  ...INITIAL_CREATE_PERSONEL_FORM,
  calisanKapsami: "DIS_KAYNAK" as const,
  ad: "Harici",
  iseGirisTarihi: "2026-01-05"
};

describe("employment scope / workplace / insurance model (runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("keeps the four personnel axes independent in a real database", () => {
    const result = runPhpMysqlRunner(
      resolve(root, "tests/php/PersonelEmploymentScopeMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    expect(String(result.stdout)).not.toContain("[FAIL]");
    for (const marker of [
      // A) Dahili
      "dahili: aynı şirket SGK işvereni kabul",
      "dahili: farklı şirket SGK işvereni reddedilir",
      "dahili: aktif IC personelde SGK işvereni zorunlu",
      "dahili: Mavi Yaka ve Beyaz Yaka statüsü kapsamdan bağımsız",
      // B) Harici
      "harici: farklı şirket SGK işvereni kabul (Şenay Mobilya bordrolu, Medisa fabrikasında)",
      "harici: Karyapı SGK işvereni kabul",
      "harici: SGK işvereni olmadan (Bağ-Kur) kabul",
      "harici: SGK işvereni zorunlu değil",
      "harici: SGK kaynağı ve fiili çalışma yeri birlikte taşınır",
      "harici: otomatik SGK seçimi yok (boş kaynak boş kalır)",
      "harici: Mavi Yaka statüsü kabul",
      "harici: Beyaz Yaka statüsü kabul",
      // C) Fabrika envanteri
      "fabrika: aynı fiili çalışma yerinde üç farklı bordro kaynağı birlikte bulunur",
      "fabrika: Medisa, Şenay Mobilya ve SGK kaynağı olmayan personel aynı lokasyonda",
      "fabrika: fiili çalışma yeri ile SGK işvereni bağımsız eksenlerdir",
      // D) Import parity
      "import: harici satır doğrulanır",
      "import: harici + farklı şirket SGK işvereni geçerli",
      "import: harici + Mavi Yaka + fiili çalışma yeri geçerli",
      "import: harici + Beyaz Yaka geçerli",
      "import: harici + SGK işveren boş geçerli",
      "import: dahili + farklı şirket SGK işvereni reddedilir",
      // F) Attendance / mobil
      "DIS_KAYNAK personel kapsam ekseninden çözülür",
      "DIS_KAYNAK QR ve bugün durumu kapsamında (attendance dışlaması yok)",
      "DIS_KAYNAK izin/finans yazımı fail-closed"
    ]) {
      expect(result.stdout).toContain(marker);
    }

    expect(result.stdout).toContain("verify-personel-employment-scope-mysql: OK");
  });
});

describe("employment scope / workplace / insurance model (create form)", () => {
  const sgkOptions = [
    { id: 1, label: "Medisa", sirketId: 1 },
    { id: 2, label: "Şenay Mobilya", sirketId: 2 },
    { id: 3, label: "Karyapı", sirketId: 3 }
  ];
  const subeOptions = [
    { id: 10, label: "Fabrika", sirketId: 1 },
    { id: 20, label: "Karyapı Merkez", sirketId: 3 }
  ];

  it("keeps Dahili on the same-company catalog and opens the full catalog to Harici", () => {
    expect(
      filterSgkIsverenOptionsForCreate(sgkOptions, subeOptions, "10", "IC_PERSONEL").map((o) => o.id)
    ).toEqual([1]);
    expect(filterSgkIsverenOptionsForCreate(sgkOptions, subeOptions, "", "IC_PERSONEL")).toEqual([]);
    expect(
      filterSgkIsverenOptionsForCreate(sgkOptions, subeOptions, "10", "DIS_KAYNAK").map((o) => o.id)
    ).toEqual([1, 2, 3]);
    // Harici için şube seçilmemiş olsa bile katalog açıktır.
    expect(
      filterSgkIsverenOptionsForCreate(sgkOptions, subeOptions, "", "DIS_KAYNAK").map((o) => o.id)
    ).toEqual([1, 2, 3]);
    // Dahili yolu değişmedi.
    expect(filterSgkIsverenOptionsForSube(sgkOptions, subeOptions, "20").map((o) => o.id)).toEqual([3]);
  });

  it("carries Harici SGK source and work location, and never auto-selects", () => {
    const withSgk = buildCreatePersonelPayload({
      ...disForm,
      subeId: "10",
      sgkIsverenId: "2",
      calismaLokasyonuId: "1",
      personelTipiId: "1"
    });
    expect(withSgk.calisan_kapsami).toBe("DIS_KAYNAK");
    expect(withSgk.sgk_isveren_id).toBe(2);
    expect(withSgk.calisma_lokasyonu_id).toBe(1);

    const withoutSgk = buildCreatePersonelPayload({ ...disForm, subeId: "10", sgkIsverenId: "" });
    expect(withoutSgk.sgk_isveren_id).toBeNull();
    expect(withoutSgk.calisma_lokasyonu_id).toBeUndefined();

    // Beyaz Yaka (2) da Harici ile birlikte geçerli; kapsam statüyü daraltmaz.
    const beyaz = buildCreatePersonelPayload({ ...disForm, subeId: "10", personelTipiId: "2" });
    expect(beyaz.personel_tipi_id).toBe(2);
  });

  it("keeps Dahili SGK required on the create payload", () => {
    expect(() =>
      buildCreatePersonelPayload({
        ...INITIAL_CREATE_PERSONEL_FORM,
        tcKimlikNo: "12345678901",
        ad: "Dahili",
        soyad: "Test",
        dogumTarihi: "1990-01-01",
        telefon: "05321234567",
        acilDurumTelefon: "05329876543",
        iseGirisTarihi: "2026-01-05",
        subeId: "10",
        departmanId: "3",
        gorevId: "4",
        personelTipiId: "1",
        sgkIsverenId: ""
      })
    ).toThrow(/SGK İşveren/);
  });
});

describe("employment scope / workplace / insurance model (owner wiring)", () => {
  it("keeps the scope-aware SGK rule in the single canonical owner", () => {
    const consistency = read("api/src/Services/Personel/PersonelSgkCompanyConsistency.php");
    expect(consistency).toContain("evaluateForKapsam");
    expect(consistency).toContain("evaluateAgainstSirketForKapsam");
    expect(consistency).toContain("assertCompatibleForKapsam");
    expect(consistency).toContain("assertRequiredForActiveIc");

    const canonical = read("api/src/Services/Personel/PersonelCanonicalValidator.php");
    expect(canonical).not.toContain("assertSgkIsverenAllowed");
    expect(canonical).toContain("SGK/bordro kaynağı ayrı bir eksendir");

    const kapsam = read("api/src/Services/Personel/PersonelCalisanKapsamService.php");
    expect(kapsam).not.toContain("ERROR_SGK_YASAK");
    expect(kapsam).not.toContain("assertSgkIsverenAllowed");
    expect(kapsam).toContain("sqlFinancialEligiblePredicate");

    for (const path of [
      "api/src/Services/Personel/PersonelCreateService.php",
      "api/src/Services/Personel/PersonelImportDryRunService.php"
    ]) {
      const src = read(path);
      expect(src, path).toContain("PersonelSgkCompanyConsistency::assertCompatibleForKapsam");
      expect(src, path).not.toContain("PersonelCalisanKapsamService::assertSgkIsverenAllowed");
    }

    expect(read("api/src/Services/Personel/PersonelOrganizasyonDegisikligiService.php")).toContain(
      "PersonelSgkCompanyConsistency::evaluateForKapsam"
    );
    expect(read("api/src/Services/Personel/PersonelKaliciSubeDegisikligiService.php")).toContain(
      "PersonelSgkCompanyConsistency::evaluateAgainstSirketForKapsam"
    );
    expect(read("api/src/Services/Personel/PersonelBasicUpdateService.php")).not.toContain(
      "assertSgkIsverenAllowed"
    );
    expect(read("api/src/Services/Personel/PersonelIncompleteCreateService.php")).not.toContain(
      "assertSgkIsverenAllowed"
    );
    expect(read("api/src/Controllers/PersonellerController.php")).not.toContain(
      "assertSgkIsverenAllowed"
    );
  });

  it("does not exclude DIS_KAYNAK from the attendance roster", () => {
    const qr = read("api/src/Services/Qr/QrAttendanceIntervalReadService.php");
    expect(qr).not.toContain("calisan_kapsami");
    expect(qr).toContain("Fiili çalışan envanteri");
    expect(read("api/src/Services/SelfService/PersonelMobileCapabilityService.php")).toMatch(
      /DIS_KAYNAK[\s\S]*qr_scan'\s*=>\s*true/
    );
  });

  it("wires the work-location filter through list owner, api, cache and page", () => {
    const controller = read("api/src/Controllers/PersonellerController.php");
    expect(controller).toContain("$calismaLokasyonuId");
    expect(controller).toContain("p.calisma_lokasyonu_id = :calisma_lokasyonu_id");
    expect(controller).toContain("OrgScope::appendPersonelOrgFilter");

    expect(read("src/api/personeller.api.ts")).toContain(
      "calisma_lokasyonu_id: params?.calisma_lokasyonu_id"
    );
    const hook = read("src/hooks/usePersoneller.ts");
    expect(hook).toContain("calismaLokasyonuId");
    expect(hook).toContain("calisma_lokasyonu_id: parseOptionalPositiveInt(appliedFilters.calismaLokasyonuId)");
    expect(read("src/data/data-manager.ts")).toContain("lok:${calismaLokasyonuId}");
    const page = read("src/features/personeller/pages/PersonellerPage.tsx");
    expect(page).toContain('name="personel-filter-calisma-lokasyonu"');
    expect(page).toContain("Çalışma Lokasyonu");
  });

  it("keeps create/detail surfaces honest about the four axes", () => {
    const createFields = read("src/features/personeller/components/PersonelCreateFields.tsx");
    expect(createFields).toContain("filterSgkIsverenOptionsForCreate");
    expect(createFields).toContain('label="Çalışan Kapsamı"');
    expect(createFields).toContain('label="Statü"');
    expect(createFields).toContain('label="SGK İşveren"');
    expect(createFields).toContain('label="Çalışma Lokasyonu"');
    // Fiili çalışma yeri alanı artık kapsama göre gizlenmez.
    expect(createFields).not.toContain('form.calisanKapsami !== "DIS_KAYNAK" && refs.calismaLokasyonuOptions');

    const deps = read("src/features/personeller/personel-create-org-deps.ts");
    expect(deps).toContain("filterSgkIsverenOptionsForCreate");
    expect(deps).toContain("SGK/bordro kaynağı fiili organizasyon şubesinden");

    const detail = read("src/features/personeller/components/personel-dosya/PersonelKartPanelGenelBilgiler.tsx");
    expect(detail).toContain('label="SGK İşvereni"');
    expect(detail).toContain('label="Çalışma Lokasyonu"');
    const genelUst = read("src/features/personeller/components/personel-dosya/PersonelDosyaGenelUst.tsx");
    expect(genelUst).toContain("Çalışan Kapsamı");
    expect(genelUst).toContain("personel_tipi_id");
  });
});
