import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  filterActiveSgkIsverenOptions,
  resolveSgkIsverenSelection,
  toSgkIsverenSelectOptions
} from "../../src/lib/yonetim/sgk-isveren-options";
import type { YonetimSgkIsveren } from "../../src/types/yonetim";

function read(rel: string): string {
  return readFileSync(resolve(process.cwd(), rel), "utf8");
}

const service = read("api/src/Services/Organizasyon/OrganizasyonService.php");
const controller = read("api/src/Controllers/OrganizasyonController.php");
const router = read("api/src/Router.php");
const referansController = read("api/src/Controllers/ReferansController.php");
const endpoints = read("src/api/endpoints.ts");
const yonetimApi = read("src/api/yonetim.api.ts");
const yonetimTypes = read("src/types/yonetim.ts");
const page = read("src/features/yonetim/pages/YonetimPaneliPage.tsx");
const optionsHelper = read("src/lib/yonetim/sgk-isveren-options.ts");
const personelCreateFields = read("src/features/personeller/components/PersonelCreateFields.tsx");

function sgk(id: number, ad: string, sirketId: number | null, durum: "AKTIF" | "PASIF" = "AKTIF"): YonetimSgkIsveren {
  return {
    id,
    kod: `K-${id}`,
    ad,
    durum,
    sirket: sirketId == null ? null : { id: sirketId, kod: `S-${sirketId}`, ad: `Şirket ${sirketId}` },
    sube_sayisi: 0
  };
}

describe("SGK employer self-service organisation management", () => {
  it("keeps the canonical SGK read owner unchanged and adds exactly one write owner", () => {
    // Read-only projection stays read-only and AKTIF-only.
    expect(router).toContain("if ($path === '/referans/sgk-isverenler' && $method === 'GET')");
    expect(router).not.toContain("'/referans/sgk-isverenler' && $method === 'POST'");
    expect(referansController).toContain("function sgkIsverenler");
    expect(referansController).toContain("WHERE durum = 'AKTIF'");

    // The organisation owner owns the catalog write surface.
    expect(service).toContain("public static function listSgkIsverenleri");
    expect(service).toContain("public static function createSgkIsveren");
    expect(service).toContain("public static function updateSgkIsveren");
    expect(service).toContain("public static function deleteSgkIsveren");

    expect(controller).toContain("function sgkIsverenleri");
    expect(controller).toContain("function sgkIsverenOlustur");
    expect(controller).toContain("function sgkIsverenGuncelle");
    expect(controller).toContain("function sgkIsverenSil");

    expect(router).toContain("if ($path === '/yonetim/sgk-isverenler' && $method === 'GET')");
    expect(router).toContain("if ($path === '/yonetim/sgk-isverenler' && $method === 'POST')");
    expect(router).toContain("'#^/yonetim/sgk-isverenler/(\\d+)$#'");
    expect(router).toContain("OrganizasyonController::sgkIsverenOlustur($this->request)");
    expect(router).toContain("OrganizasyonController::sgkIsverenGuncelle($this->request, $matches[1])");
    expect(router).toContain("OrganizasyonController::sgkIsverenSil($this->request, $matches[1])");
  });

  it("enforces the existing organisation authorization model on every write", () => {
    for (const name of ["sgkIsverenOlustur", "sgkIsverenGuncelle", "sgkIsverenSil"]) {
      const start = controller.indexOf(`function ${name}(`);
      expect(start, `${name} missing`).toBeGreaterThan(-1);
      const handler = controller.slice(start, controller.indexOf("\n    }", start));
      expect(handler).toContain("AuthMiddleware::authenticate($request, true)");
      expect(handler).toContain("self::assertManage($user)");
    }

    const readStart = controller.indexOf("function sgkIsverenleri(");
    expect(controller.slice(readStart, readStart + 400)).toContain("self::assertRead($user)");

    // No new permission/role was invented: the canonical manage permission is used.
    expect(controller).toContain("RolePermissions::assert($user, 'yonetim-paneli.manage')");
    expect(controller).not.toContain("sgk-isverenler.manage");
    expect(service).not.toContain("RolePermissions");
  });

  it("keeps sgk_isveren_id mapping purely relational (no city/name hard-code)", () => {
    const sgkSectionStart = service.indexOf("// ------------------------------------------------------------ sgk employers");
    const sgkSectionEnd = service.indexOf("// ----------------------------------------------------------------- branches");
    expect(sgkSectionStart).toBeGreaterThan(-1);
    expect(sgkSectionEnd).toBeGreaterThan(sgkSectionStart);
    const sgkSection = service.slice(sgkSectionStart, sgkSectionEnd);

    // The catalog section derives everything from stored ids, never from a city.
    expect(sgkSection.length).toBeGreaterThan(1000);
    expect(sgkSection).not.toContain("Kayseri");
    expect(sgkSection).not.toContain("Ankara");
    expect(sgkSection).toContain("sirket_id = :sirket_id");

    expect(optionsHelper).not.toContain("Kayseri");
    expect(optionsHelper).not.toContain("Ankara");

    // 2026-09-15 business kararı: seçim listesi şirkete göre filtrelenmez.
    expect(optionsHelper).not.toContain("option.sirket");
    expect(optionsHelper).toContain('option.durum === "AKTIF"');
    expect(page).toContain("filterActiveSgkIsverenOptions(sgkIsverenleri, editingSubeSgkIsverenId)");
    expect(page).not.toContain("filterSgkIsverenOptionsForSirket");
  });

  it("keeps the branch employer mapping independent from the branch company", () => {
    // Şube yazımının tek invariantı AKTIF işveren kuralıdır. Şirket eşleşmesi
    // (mismatch) ve şirketsiz kayıt 2026-09-15 kararıyla şube tarafında kaldırıldı:
    // şube şirketi ile sgk_isveren.sirket_id farklı olabilir.
    expect(service).not.toContain("SGK_ISVEREN_SIRKET_MISMATCH");
    expect(service).not.toContain("SGK_ISVEREN_SIRKET_UNMAPPED");
    expect(service).toContain("SGK_ISVEREN_PASIF");
    expect(service).toContain("SGK_ISVEREN_HAS_DEPENDENTS");
    expect(service).toContain("SGK_ISVEREN_SIRKET_CHANGE_BLOCKED");
    expect(service).toContain("private static function assertSgkIsverenConsistent");
    expect(service).toContain("$sgkIsverenId !== $existingSgkIsverenId");
    // Personel tarafı ayrı owner ve bu fazda değişmedi: IC aynı-şirket kuralı durur.
    expect(read("api/src/Services/Personel/PersonelSgkCompanyConsistency.php")).toContain(
      "const ERROR_MISMATCH"
    );
    // Physical delete stays blocked instead of cascading.
    expect(service).toContain("sgkIsverenDependencyCounts");
    expect(service).toContain("user_sgk_isverenler WHERE sgk_isveren_id = :id");
  });

  it("wires the management API contract for the catalog", () => {
    expect(endpoints).toContain('sgkIsverenler: "/yonetim/sgk-isverenler"');
    expect(endpoints).toContain("sgkIsverenDetail: (id: number | string) => `/yonetim/sgk-isverenler/${id}`");

    expect(yonetimApi).toContain("export async function fetchYonetimSgkIsverenleri");
    expect(yonetimApi).toContain("export async function createYonetimSgkIsveren");
    expect(yonetimApi).toContain("export async function updateYonetimSgkIsveren");
    expect(yonetimApi).toContain("export async function deleteYonetimSgkIsveren");
    expect(yonetimApi).toContain("function normalizeYonetimSgkIsveren");

    expect(yonetimTypes).toContain("export type YonetimSgkIsveren");
    expect(yonetimTypes).toContain("export type UpsertYonetimSgkIsverenPayload");
    expect(yonetimTypes).toContain("sirket_id: number;");
  });

  it("reuses the existing organisation design system instead of a parallel form", () => {
    expect(page).toContain('from "../../../components/form/AppSelect"');
    expect(page).toContain('data-testid="yonetim-sgk-isveren-yeni"');
    expect(page).toContain('data-testid="yonetim-sgk-isveren-kaydet"');
    expect(page).toContain('data-testid="yonetim-sgk-isveren-sil"');
    expect(page).toContain('data-testid="yonetim-sgk-isveren-section"');
    expect(page).toContain('data-testid="yonetim-sgk-isveren-load-error"');
    expect(page).toContain('name="yonetim-sgk-isveren-sirket"');
    expect(page).toContain('name="yonetim-sgk-isveren-kod"');
    expect(page).toContain('name="yonetim-sgk-isveren-ad"');
    expect(page).toContain('className="yonetim-form-stack"');
    expect(page).toContain("yonetim-durum-toggle");
    // No override CSS or duplicated event system.
    expect(page).not.toContain("!important");
    expect(page).not.toContain("addEventListener");
  });

  it("selects and prefills the branch employer inside the şube form", () => {
    expect(page).toContain('name="yonetim-sube-sgk-isveren"');
    expect(page).toContain('sgkIsverenId: item.sgk_isveren?.id != null ? String(item.sgk_isveren.id) : ""');
    expect(page).toContain("payload.sgk_isveren_id = parsed");
    expect(page).toContain("payload.sgk_isveren_id = null");
    expect(page).toContain("allowedSgkIsverenIds: subeSgkIsverenAllowedIds");
    expect(page).toContain("resolveSgkIsverenSelection");
    expect(page).toContain("Aktif SGK işvereni bulunmuyor.");
    // An unreadable catalog never looks like "no employer": the mapping is
    // preserved and no SGK key is sent for this branch write.
    expect(page).toContain("isSgkIsverenCatalogLoaded");
    expect(page).toContain("const hasSgkIsverenContext =");
    expect(page).toContain("hasSgkIsverenContext ? { allowedSgkIsverenIds: subeSgkIsverenAllowedIds } : undefined");
  });

  it("offers every active employer regardless of the branch company", () => {
    const options = [
      sgk(1, "MEDISA MERKEZ", 1),
      sgk(2, "MEDISA BURSA", 1),
      sgk(3, "SENAY MERKEZ", 2),
      sgk(4, "MEDISA PASIF", 1, "PASIF")
    ];

    // 2026-09-15: şube şirketi ile SGK işvereninin şirketi farklı olabilir, bu
    // yüzden katalogdaki tüm aktif SGK işverenleri sunulur.
    expect(filterActiveSgkIsverenOptions(options).map((item) => item.id)).toEqual([1, 2, 3]);
    expect(filterActiveSgkIsverenOptions(options).some((item) => item.id === 3)).toBe(true);
    // Şirketsiz (henüz eşlenmemiş) aktif kayıt da seçilebilir.
    expect(filterActiveSgkIsverenOptions([sgk(9, "ESLESMEMIS", null)]).map((item) => item.id)).toEqual([
      9
    ]);
    expect(filterActiveSgkIsverenOptions([])).toEqual([]);
  });

  it("keeps a deactivated employer visible only while it is the stored mapping", () => {
    const options = [
      sgk(1, "MEDISA MERKEZ", 1),
      sgk(3, "SENAY MERKEZ", 2),
      sgk(4, "MEDISA PASIF", 1, "PASIF")
    ];

    expect(filterActiveSgkIsverenOptions(options).map((item) => item.id)).toEqual([1, 3]);
    expect(filterActiveSgkIsverenOptions(options, 4).map((item) => item.id)).toEqual([1, 3, 4]);
    expect(toSgkIsverenSelectOptions(filterActiveSgkIsverenOptions(options, 4))).toContainEqual({
      value: "4",
      label: "MEDISA PASIF (pasif)"
    });

    // A stale selection never survives a catalog reload silently.
    expect(resolveSgkIsverenSelection("9", filterActiveSgkIsverenOptions(options))).toBe("");
    expect(resolveSgkIsverenSelection("1", filterActiveSgkIsverenOptions(options))).toBe("1");
    expect(resolveSgkIsverenSelection("", options)).toBe("");
  });

  it("does not add a new personnel-create business rule", () => {
    // 2026-09-13: EMPLOYMENT_SCOPE_WORKPLACE_INSURANCE_MODEL_CORRECTION fazında
    // kayıt formu SGK kaynağı kapsam duyarlı tek owner'a taşındı
    // (filterSgkIsverenOptionsForCreate → IC aynı-şirket, DIS tam AKTİF katalog).
    expect(personelCreateFields).toContain("filterSgkIsverenOptionsForCreate");
    expect(personelCreateFields).not.toContain("sube.sgk_isveren");
    expect(read("src/features/personeller/personel-create-org-deps.ts")).toContain(
      "SGK işveren options for create: only employers that share the selected"
    );
  });
});
