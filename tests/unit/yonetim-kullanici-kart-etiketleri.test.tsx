/** @vitest-environment jsdom */
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import type { YonetimKullanici } from "../../src/types/yonetim";

/**
 * Kullanıcı Yönetimi kartları: gerçek render ile görünen metin.
 * - Aynı kişiye ait iki ayrı hesap (yönetici + PERSONEL) iki ayrı kart kalır, birleşmez.
 * - Ad Soyad onaylı kurala göre (Ad Title Case + SOYAD BÜYÜK) iki kartta aynı biçimde.
 * - Boş şube ataması rol kuralına göre etiketlenir ("Tüm Şubeler" yalnız global rollerde).
 * - Şifre durumu yalnız must_change_password'ın kanıtladığını söyler.
 * Liste yalnız okunur; hiçbir yazma API'si çağrılmaz.
 */

const kullanicilar: YonetimKullanici[] = [
  {
    id: 1,
    username: "ilkerA",
    ad_soyad: "İlker Akel",
    kullanici_tipi: "HARICI",
    rol: "GENEL_YONETICI",
    personel_id: null,
    personel_ad_soyad: null,
    sube_ids: [1, 2],
    varsayilan_sube_id: 1,
    durum: "AKTIF"
  },
  {
    id: 2,
    username: "ilker.akel",
    ad_soyad: "İlker AKEL",
    kullanici_tipi: "IC_PERSONEL",
    rol: "PERSONEL",
    personel_id: 501,
    personel_ad_soyad: "İlker AKEL",
    must_change_password: false,
    sube_ids: [],
    varsayilan_sube_id: null,
    durum: "AKTIF"
  },
  {
    id: 3,
    username: "serhanK",
    ad_soyad: "Serhan Köse",
    kullanici_tipi: "IC_PERSONEL",
    rol: "SUBE_YONETICISI",
    personel_id: null,
    personel_ad_soyad: null,
    sube_ids: [1, 2],
    varsayilan_sube_id: 1,
    durum: "AKTIF"
  },
  {
    id: 4,
    username: "serhan.kose",
    ad_soyad: "Serhan KÖSE",
    kullanici_tipi: "IC_PERSONEL",
    rol: "PERSONEL",
    personel_id: 502,
    personel_ad_soyad: "Serhan KÖSE",
    must_change_password: true,
    sube_ids: [],
    varsayilan_sube_id: null,
    durum: "AKTIF"
  },
  { id: 5, username: "birim", ad_soyad: "Birim Amir", kullanici_tipi: "IC_PERSONEL", rol: "BIRIM_AMIRI", personel_id: null, sube_ids: [], birim_ids: [7], varsayilan_sube_id: null, durum: "AKTIF" },
  { id: 6, username: "bolum", ad_soyad: "Bolum Yonetici", kullanici_tipi: "IC_PERSONEL", rol: "BOLUM_YONETICISI", personel_id: null, sube_ids: [], bolum_ids: [], varsayilan_sube_id: null, durum: "AKTIF" },
  { id: 7, username: "muhasebe", ad_soyad: "Muhasebe Kisi", kullanici_tipi: "IC_PERSONEL", rol: "MUHASEBE", personel_id: null, sube_ids: [], sirket_ids: [1], varsayilan_sube_id: null, durum: "AKTIF" },
  { id: 8, username: "ik", ad_soyad: "Ik Sorumlu", kullanici_tipi: "IC_PERSONEL", rol: "IK_SORUMLUSU", personel_id: null, sube_ids: [], varsayilan_sube_id: null, durum: "AKTIF" },
  { id: 9, username: "sistem", ad_soyad: "Sistem Yonetici", kullanici_tipi: "IC_PERSONEL", rol: "SISTEM_YONETICISI", personel_id: null, sube_ids: [], varsayilan_sube_id: null, durum: "AKTIF" },
  { id: 10, username: "sube", ad_soyad: "Sube Yonetici", kullanici_tipi: "IC_PERSONEL", rol: "SUBE_YONETICISI", personel_id: null, sube_ids: [], varsayilan_sube_id: null, durum: "AKTIF" }
];

const yonetimApi = vi.hoisted(() => ({
  fetchYonetimKullanicilari: vi.fn(),
  fetchYonetimSubeleri: vi.fn(),
  fetchOrganizasyonReadiness: vi.fn(),
  createYonetimKullanici: vi.fn(),
  updateYonetimKullanici: vi.fn(),
  resetYonetimKullaniciBaslangicSifresi: vi.fn(),
  fixYonetimKullaniciCanonicalUsername: vi.fn()
}));

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: () => ({
    hasPermission: (permission: string) =>
      permission === "yonetim-paneli.view" || permission === "yonetim-paneli.manage"
  })
}));

vi.mock("../../src/api/yonetim.api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../../src/api/yonetim.api")>()),
  ...yonetimApi
}));

vi.mock("../../src/api/personeller.api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../../src/api/personeller.api")>()),
  fetchPersonellerListForSelect: vi.fn().mockResolvedValue([
    { id: 501, ad: "İlker", soyad: "AKEL", aktif_durum: "AKTIF" },
    { id: 502, ad: "Serhan", soyad: "Köse", aktif_durum: "AKTIF" }
  ])
}));

vi.mock("../../src/api/referans.api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../../src/api/referans.api")>()),
  fetchDepartmanOptions: vi.fn().mockResolvedValue([]),
  fetchBolumOptions: vi.fn().mockResolvedValue([]),
  fetchBirimOptions: vi.fn().mockResolvedValue([]),
  fetchSgkIsverenCatalog: vi.fn().mockResolvedValue([])
}));

import { YonetimPaneliPage } from "../../src/features/yonetim/pages/YonetimPaneliPage";

function cardTexts(container: HTMLElement) {
  return Array.from(container.querySelectorAll(".yonetim-card-grid--users article")).map((card) => ({
    name: card.querySelector("strong")?.textContent ?? "",
    lines: Array.from(card.querySelectorAll(".yonetim-card-meta > span")).map((span) => span.textContent ?? "")
  }));
}

async function renderPanel() {
  yonetimApi.fetchYonetimKullanicilari.mockResolvedValue(kullanicilar);
  yonetimApi.fetchYonetimSubeleri.mockResolvedValue([
    { id: 1, kod: "F", ad: "Fabrika", tam_ad: "Medisa Fabrika", departman_ids: [], departman_adlari: [], durum: "AKTIF" },
    { id: 2, kod: "G", ad: "Giresun", tam_ad: "Medisa Giresun", departman_ids: [], departman_adlari: [], durum: "AKTIF" }
  ]);
  yonetimApi.fetchOrganizasyonReadiness.mockRejectedValue(new Error("legacy"));

  const view = render(
    <MemoryRouter initialEntries={["/yonetim-paneli?tab=kullanicilar"]}>
      <YonetimPaneliPage />
    </MemoryRouter>
  );
  await screen.findByTestId("yonetim-kullanici-first-login-summary");
  return view;
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("Kullanıcı Yönetimi kart etiketleri (gerçek render)", () => {
  it("aynı kişinin iki ayrı hesabı iki kart kalır ve adı onaylı biçimde aynı görünür", async () => {
    const { container } = await renderPanel();
    const cards = cardTexts(container);

    expect(cards).toHaveLength(kullanicilar.length);
    expect(cards[0]).toEqual({ name: "İlker AKEL", lines: ["Medisa Fabrika, Medisa Giresun"] });
    expect(cards[1]).toEqual({
      name: "İlker AKEL",
      lines: ["Kendi Kaydı", "Kalıcı Şifre"]
    });
    expect(cards[2]).toEqual({ name: "Serhan KÖSE", lines: ["Medisa Fabrika, Medisa Giresun"] });
    expect(cards[3]).toEqual({
      name: "Serhan KÖSE",
      lines: ["Kendi Kaydı", "Geçici Şifre"]
    });

    // Görünüm düzeltmesi hesaplara dokunmaz: yazma/sıfırlama API'si çağrılmaz.
    expect(yonetimApi.createYonetimKullanici).not.toHaveBeenCalled();
    expect(yonetimApi.updateYonetimKullanici).not.toHaveBeenCalled();
    expect(yonetimApi.resetYonetimKullaniciBaslangicSifresi).not.toHaveBeenCalled();
    expect(yonetimApi.fixYonetimKullaniciCanonicalUsername).not.toHaveBeenCalled();
  });

  it("boş şube atamasını rol kuralına göre etiketler", async () => {
    const { container } = await renderPanel();
    const scopeByIndex = cardTexts(container).map((card) => card.lines[0]);

    expect(scopeByIndex.slice(4)).toEqual([
      "Birim Kapsamı",
      "Atama Yok",
      "Şirket Kapsamı",
      "Tüm Şubeler",
      "Tüm Şubeler",
      "Atama Yok"
    ]);
    expect(scopeByIndex.filter((label) => label === "Tüm Şubeler")).toHaveLength(2);
  });

  it("şifre durumu sayaç ve filtre metinleri yeni terminolojiyi kullanır", async () => {
    await renderPanel();

    const summary = screen.getByTestId("yonetim-kullanici-first-login-summary");
    expect(summary.textContent).toContain("Geçici Şifre");
    expect(summary.textContent).toContain("Kalıcı Şifre");
    expect(summary.textContent).not.toContain("İlk Giriş");
    expect(screen.getByTestId("yonetim-kullanici-first-login-pending-count").textContent).toBe("1");
    expect(screen.getByTestId("yonetim-kullanici-first-login-completed-count").textContent).toBe("1");
    expect(screen.getByLabelText("Şifre Durumu")).toBeTruthy();
  });
});
