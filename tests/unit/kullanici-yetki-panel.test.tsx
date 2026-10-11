/** @vitest-environment jsdom */
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import { YETKI_SEMA_HAZIR_DEGIL } from "../../src/lib/yonetim/kullanici-yetki-ekrani";

const api = vi.hoisted(() => ({
  fetchKullaniciYetkileri: vi.fn(),
  fetchKullaniciYetkiAuditleri: vi.fn(),
  verKullaniciYetkisi: vi.fn(),
  iptalKullaniciYetkisi: vi.fn()
}));

vi.mock("../../src/api/yonetim.api", () => api);

import { KullaniciYetkiPanel } from "../../src/features/yonetim/components/KullaniciYetkiPanel";

const hazirOkuma = {
  userId: 2,
  rol: "BOLUM_YONETICISI",
  schemaReady: true,
  rolVarsayilanlari: ["personeller.update", "sgk_karar_paketi.approve"],
  istisnalar: [
    {
      id: 7,
      permission: "sgk_karar_paketi.approve",
      etki: "DENY" as const,
      subeId: null,
      gecerlilikBaslangic: "2026-10-01 09:00:00",
      gecerlilikBitis: null,
      gerekce: "Hassas başlangıç",
      verenUsername: "İlker Akel",
      createdAt: "2026-10-01 09:00:00",
      iptalEdildiAt: null,
      iptalNedeni: null,
      iptalEdenUsername: null
    }
  ],
  effectivePermissions: ["personeller.update"],
  kaynaklar: { "personeller.update": "ROLE_DEFAULT" }
};

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("Kullanıcı yetki paneli", () => {
  it("şema hazır değilken uyarı verir ve işlem olmuş gibi göstermez", async () => {
    api.fetchKullaniciYetkileri.mockResolvedValue({
      ...hazirOkuma,
      schemaReady: false,
      istisnalar: [],
      effectivePermissions: hazirOkuma.rolVarsayilanlari
    });
    api.fetchKullaniciYetkiAuditleri.mockResolvedValue({ schemaReady: false, items: [] });

    render(<KullaniciYetkiPanel userId={2} actorUserId={9} canManage canViewAudit />);

    const uyari = await screen.findByTestId("yonetim-yetki-sema-uyari");
    expect(uyari.textContent).toContain("henüz kurulmadı");
    expect(screen.queryByTestId("yonetim-yetki-form")).toBeNull();
    expect(screen.queryByTestId("yonetim-yetki-basari")).toBeNull();
    expect(screen.queryByTestId("yonetim-yetki-istisna-yok")).toBeNull();
    expect(screen.queryByTestId("yonetim-yetki-gecmis-bos")).toBeNull();
    expect(api.verKullaniciYetkisi).not.toHaveBeenCalled();
  });

  it("hassas kısıtı kaldırma düğmesi sunmaz ve kendi hesabında form açmaz", async () => {
    api.fetchKullaniciYetkileri.mockResolvedValue(hazirOkuma);
    api.fetchKullaniciYetkiAuditleri.mockResolvedValue({ schemaReady: true, items: [] });

    const { unmount } = render(
      <KullaniciYetkiPanel userId={2} actorUserId={9} canManage canViewAudit />
    );
    expect(await screen.findByTestId("yonetim-yetki-hassas-7")).toBeTruthy();
    expect(screen.queryByTestId("yonetim-yetki-kaldir-7")).toBeNull();
    expect(screen.getByTestId("yonetim-yetki-form")).toBeTruthy();
    unmount();

    api.fetchKullaniciYetkileri.mockResolvedValue({ ...hazirOkuma, userId: 9, istisnalar: [] });
    render(<KullaniciYetkiPanel userId={9} actorUserId={9} canManage canViewAudit />);
    expect(await screen.findByTestId("yonetim-yetki-kendi")).toBeTruthy();
    expect(screen.queryByTestId("yonetim-yetki-form")).toBeNull();
  });

  it("şema hatasında başarı yazmaz", async () => {
    api.fetchKullaniciYetkileri.mockResolvedValue({
      ...hazirOkuma,
      istisnalar: [],
      effectivePermissions: ["personeller.update", "sgk_karar_paketi.approve"],
      kaynaklar: {
        "personeller.update": "ROLE_DEFAULT",
        "sgk_karar_paketi.approve": "ROLE_DEFAULT"
      }
    });
    api.fetchKullaniciYetkiAuditleri.mockResolvedValue({ schemaReady: true, items: [] });
    api.verKullaniciYetkisi.mockRejectedValue(
      new ApiRequestError("Yetki istisnası şeması henüz kurulmadı (migration 100).", 503, {
        code: YETKI_SEMA_HAZIR_DEGIL
      })
    );

    render(<KullaniciYetkiPanel userId={2} actorUserId={9} canManage canViewAudit />);
    expect(await screen.findByTestId("yonetim-yetki-form")).toBeTruthy();

    fireEvent.change(document.querySelector('select[name="yonetim-yetki-izin"]') as HTMLSelectElement, {
      target: { value: "personeller.update" }
    });
    fireEvent.change(document.querySelector('textarea[name="yonetim-yetki-gerekce"]') as HTMLTextAreaElement, {
      target: { value: "Düzenleme kapansın" }
    });
    fireEvent.click(screen.getByTestId("yonetim-yetki-kaydet"));

    expect(await screen.findByTestId("yonetim-yetki-sema-uyari")).toBeTruthy();
    expect(screen.queryByTestId("yonetim-yetki-basari")).toBeNull();
    expect(api.verKullaniciYetkisi).toHaveBeenCalledWith(2, {
      permission: "personeller.update",
      etki: "DENY",
      gerekce: "Düzenleme kapansın",
      gecerlilikBitis: null
    });
  });
});
