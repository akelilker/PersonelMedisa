import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  buildDuplicateShortNameKeys,
  resolveSubeDisplayLabel,
  resolveSubeDisplayLabels
} from "../../src/lib/organizasyon/sube-display-label";
import {
  DEPARTMAN_DISPLAY_ORDER,
  compareDepartmanDisplayOrder,
  normalizeDepartmanDisplayName,
  resolveDepartmanDisplayRank,
  sortDepartmanDisplayOptions
} from "../../src/lib/organizasyon/departman-display-order";
import {
  filterSgkIsverenOptionsForCreate,
  resolveSgkIsverenAfterSubeChange
} from "../../src/features/personeller/personel-create-org-deps";

const root = resolve(__dirname, "../..");

function read(pathFromRoot: string) {
  return readFileSync(resolve(root, pathFromRoot), "utf8");
}

/** Canlı organizasyon envanteriyle aynı şekil: kısa ad + şirket türetilmiş tam_ad. */
const SUBELER = [
  { id: 1, ad: "Fabrika", tamAd: "Medisa Fabrika" },
  { id: 2, ad: "Giresun", tamAd: "Medisa Giresun" },
  { id: 4, ad: "Kayseri", tamAd: "Medisa Kayseri" },
  { id: 5, ad: "Ankara", tamAd: "Medisa Ankara" },
  { id: 6, ad: "İstanbul", tamAd: "Medisa İstanbul" },
  { id: 7, ad: "Karyapı", tamAd: "Karyapı" },
  { id: 8, ad: "Ankara", tamAd: "Karyapı Ankara" },
  { id: 9, ad: "Kayseri", tamAd: "Karyapı Kayseri" },
  { id: 10, ad: "İstanbul", tamAd: "Karyapı İstanbul" },
  { id: 11, ad: "Şenay Mobilya", tamAd: "Şenay Mobilya" }
];

describe("şube display: adaptive canonical kural", () => {
  it("benzersiz kısa ad yalnız kısa ad kalır", () => {
    const duplicates = buildDuplicateShortNameKeys(SUBELER);
    const labels = resolveSubeDisplayLabels(SUBELER);

    expect(labels.get(1)).toBe("Fabrika");
    expect(labels.get(2)).toBe("Giresun");
    expect(labels.get(11)).toBe("Şenay Mobilya");
    expect(duplicates.has("fabrika")).toBe(false);
  });

  it("aynı kısa ad birden fazla şirkette varsa şirket ile ayrışır", () => {
    const labels = resolveSubeDisplayLabels(SUBELER);

    expect(labels.get(5)).toBe("Medisa Ankara");
    expect(labels.get(8)).toBe("Karyapı Ankara");
    expect(labels.get(4)).toBe("Medisa Kayseri");
    expect(labels.get(9)).toBe("Karyapı Kayseri");
    expect(labels.get(6)).toBe("Medisa İstanbul");
    expect(labels.get(10)).toBe("Karyapı İstanbul");
  });

  it("şirket adı şubeyle aynıysa tekrar yazılmaz", () => {
    const labels = resolveSubeDisplayLabels(SUBELER);
    expect(labels.get(7)).toBe("Karyapı");
  });

  it("tam_ad gelmiyorsa kısa ada düşülür; string birleştirme hack'i yok", () => {
    const duplicates = buildDuplicateShortNameKeys([
      { id: 1, ad: "Ankara" },
      { id: 2, ad: "Ankara" }
    ]);

    expect(resolveSubeDisplayLabel({ id: 1, ad: "Ankara" }, duplicates)).toBe("Ankara");
    const auth = read("src/api/auth.api.ts");
    expect(auth).not.toMatch(/\$\{.*sirket.*\}.*\$\{.*ad.*\}/);
    expect(auth).toContain("resolveSubeDisplayLabels(");
  });

  it("oturum şube etiketi de adaptive owner'dan gelir", () => {
    const auth = read("src/api/auth.api.ts");
    expect(auth).toContain("import { resolveSubeDisplayLabels }");
    expect(auth).toContain("ad: item.kisa_ad ?? item.ad");
  });
});

describe("departman display order (canonical business order)", () => {
  const catalog = [
    { id: 1, label: "Depolama" },
    { id: 2, label: "Diğer" },
    { id: 3, label: "Finans ve Risk Yönetimi" },
    { id: 4, label: "Giresun Depo" },
    { id: 5, label: "Karabük Depo" },
    { id: 6, label: "Mali Ve İdari İşler" },
    { id: 7, label: "Pazarlama ve Satış" },
    { id: 8, label: "Uretim" },
    { id: 9, label: "Yönetim" },
    { id: 10, label: "İhracat" }
  ];

  it("kanonik business order aynen uygulanır", () => {
    expect(sortDepartmanDisplayOptions(catalog).map((item) => item.label)).toEqual([
      "Yönetim",
      "Finans ve Risk Yönetimi",
      "Mali Ve İdari İşler",
      "Pazarlama ve Satış",
      "Uretim",
      "İhracat",
      "Karabük Depo",
      "Giresun Depo",
      "Depolama",
      "Diğer"
    ]);
  });

  it("alfabetik değildir: bilinen sıra korunur", () => {
    const sorted = sortDepartmanDisplayOptions(catalog).map((item) => item.label);
    expect(sorted[0]).toBe("Yönetim");
    expect(sorted[1]).toBe("Finans ve Risk Yönetimi");
    expect(sorted.indexOf("Karabük Depo")).toBeLessThan(sorted.indexOf("Depolama"));
  });

  it("bilinmeyen/yeni departman kaybolmaz: bilinen setten sonra stabil gelir", () => {
    const withUnknown = [...catalog, { id: 99, label: "Ar-Ge" }, { id: 98, label: "Zemin Kat" }];
    const sorted = sortDepartmanDisplayOptions(withUnknown).map((item) => item.label);

    expect(sorted).toHaveLength(withUnknown.length);
    // Bilinmeyenler bilinen sıradan SONRA alfabetik-stabil gelir.
    expect(sorted.slice(-4)).toEqual(["Ar-Ge", "Depolama", "Diğer", "Zemin Kat"]);
  });

  it("katalog yazım farkları normalize edilir (Uretim/Üretim, büyük-küçük harf)", () => {
    expect(normalizeDepartmanDisplayName("Üretim")).toBe(normalizeDepartmanDisplayName("URETIM"));
    expect(resolveDepartmanDisplayRank("uretim")).toBe(
      DEPARTMAN_DISPLAY_ORDER.indexOf("Üretim")
    );
    expect(
      compareDepartmanDisplayOrder({ id: 1, label: "Üretim" }, { id: 2, label: "Depolama" })
    ).toBeLessThan(0);
  });

  it("kısa kod prefix'li etiketlerde de ad parçası eşleşir", () => {
    expect(resolveDepartmanDisplayRank("DPT — Yönetim")).toBe(0);
  });

  it("tek canonical owner okuma katmanında uygulanır (dağınık hardcode yok)", () => {
    const api = read("src/api/referans.api.ts");
    expect(api).toContain("sortDepartmanDisplayOptions(normalizeIdOptions(response.data))");

    const ordered = read("src/lib/organizasyon/departman-display-order.ts");
    expect(ordered).toContain("DEPARTMAN_DISPLAY_ORDER");
    expect(ordered).not.toContain("!important");
  });
});


describe("SGK işveren kapsamı (Dahili / Harici)", () => {
  const sgkOptions = [
    { id: 1, label: "Medisa", sirketId: 1 },
    { id: 2, label: "Karyapı", sirketId: 2 },
    { id: 3, label: "Şenay Mobilya", sirketId: 3 }
  ];
  const subeOptions = [
    { id: 2, label: "Giresun", sirketId: 1 },
    { id: 8, label: "Karyapı Ankara", sirketId: 2 }
  ];

  it("Dahili: yalnız seçili şubenin şirketine ait aktif SGK işverenleri", () => {
    const giresun = filterSgkIsverenOptionsForCreate(sgkOptions, subeOptions, "2", "IC_PERSONEL");
    expect(giresun.map((option) => option.label)).toEqual(["Medisa"]);

    const karyapi = filterSgkIsverenOptionsForCreate(sgkOptions, subeOptions, "8", "IC_PERSONEL");
    expect(karyapi.map((option) => option.label)).toEqual(["Karyapı"]);
  });

  it("Dahili: şube seçilmeden liste boş; otomatik seçim yok", () => {
    expect(filterSgkIsverenOptionsForCreate(sgkOptions, subeOptions, "", "IC_PERSONEL")).toEqual([]);
    expect(resolveSgkIsverenAfterSubeChange("", sgkOptions)).toBe("");
  });

  it("Harici: fiziksel şubeden bağımsız tüm aktif SGK işverenleri seçilebilir", () => {
    const harici = filterSgkIsverenOptionsForCreate(sgkOptions, subeOptions, "2", "DIS_KAYNAK");
    expect(harici.map((option) => option.label)).toEqual(["Medisa", "Karyapı", "Şenay Mobilya"]);
  });

  it("Harici: SGK seçimi boş bırakılabilir (null sözleşmesi)", () => {
    expect(resolveSgkIsverenAfterSubeChange("", sgkOptions)).toBe("");
    expect(resolveSgkIsverenAfterSubeChange("2", [])).toBe("");
  });

  it("şube değişince geçersiz seçim temizlenir, geçerli seçim korunur", () => {
    const medisa = filterSgkIsverenOptionsForCreate(sgkOptions, subeOptions, "2", "IC_PERSONEL");
    expect(resolveSgkIsverenAfterSubeChange("2", medisa)).toBe("");
    expect(resolveSgkIsverenAfterSubeChange("1", medisa)).toBe("1");
  });
});

describe("bağlı amir uygunluk kontratı (üst düzey yönetici dahil)", () => {
  const controller = read("api/src/Controllers/ReferansController.php");

  it("yalnız AKTIF kullanıcılar listelenir", () => {
    expect(controller).toMatch(/function bagliAmirler[\s\S]*durum = 'AKTIF'/);
  });

  it("PERSONEL ve teknik smoke aktörü hariç tüm yönetim rolleri seçilebilir", () => {
    expect(controller).toContain("AND rol NOT IN ('PERSONEL', 'AUTH_SMOKE_READONLY')");
    // Kapanmış dar allowlist geri gelmemeli: üst düzey yönetici (GENEL_YONETICI /
    // SISTEM_YONETICISI) ve şube/İK yöneticileri de bağlı amir olabilir.
    expect(controller).not.toMatch(/rol IN \('GENEL_YONETICI'/);
  });

  it("yazma kontratı ile aynı tablo ve durum invariants kullanılır", () => {
    const createService = read("api/src/Services/Personel/PersonelCreateService.php");
    expect(createService).toContain("SELECT id FROM users WHERE id = :id AND durum = 'AKTIF'");
  });
});

describe("picker panel taşma kontratı (tek canonical owner)", () => {
  const layer = read("src/components/form/app-picker-layer.ts");
  const select = read("src/components/form/AppSelect.tsx");

  it("yatay clamp ve viewport sınırı tek owner'da hesaplanır", () => {
    expect(layer).toContain("export function measurePickerPanel");
    expect(layer).toContain("export function applyPickerPanelGeometry");
    expect(layer).toContain("resolvePickerVisibleClip");
    expect(layer).toMatch(
      /isClippingOverflow\(style\.overflowY\) \|\| isClippingOverflow\(style\.overflowX\)/
    );
  });

  it("AppSelect ve AppDatePicker aynı ölçüm owner'ını kullanır (paralel konumlandırma yok)", () => {
    expect(select).toContain("measurePickerPanel(root, panel)");
    expect(select).toContain("applyPickerPanelGeometry(panel, geometry)");
    expect(select).not.toContain("resolveVisibleClip(root)");

    const datePicker = read("src/components/form/AppDatePicker.tsx");
    expect(datePicker).toContain("measurePickerPanel(root, panel)");
    expect(datePicker).toContain("applyPickerPanelGeometry(panel, geometry)");
  });

  it("panel yüksekliği ve satır sarma politikası CSS'te tutarlıdır", () => {
    const css = read("src/styles/components/app-select.css");
    expect(css).toMatch(/\.app-picker-panel\s*\{[^}]*overflow-x:\s*hidden/s);
    expect(css).toMatch(/\.app-select-option\s*\{[^}]*overflow-wrap:\s*anywhere/s);
    expect(css).toMatch(/\.app-select-option\.is-clear\s*\{/);
    expect(css).not.toContain("!important");
  });
});
