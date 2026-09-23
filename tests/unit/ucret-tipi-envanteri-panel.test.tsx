// @vitest-environment jsdom

/**
 * Render-level proof for "Ücret Tipi Envanteri" (yönetim, salt okunur).
 *
 * Tek kaynak: canonical read API `fetchPersonellerList` → `personeller.ucret_tipi_id`.
 * Personel Kartı'ndaki Mavi/Beyaz statüsü ücret tipine çevrilmez; statü yalnız gösterilir.
 * Bu ekran yazma/güncelleme çağrısı yapmaz.
 */

import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

const listMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/api/personeller.api", () => ({
  fetchPersonellerList: listMock
}));

import { UcretTipiEnvanteriPanel } from "../../src/features/yonetim/components/UcretTipiEnvanteriPanel";
import type { Personel } from "../../src/types/personel";

function personel(over: Partial<Personel> & { id: number }): Personel {
  return { ad: "Ali", soyad: "Veli", aktif_durum: "AKTIF", tc_kimlik_no: null, ...over };
}

function mockPage(items: Personel[], total = items.length) {
  listMock.mockResolvedValueOnce({
    items,
    pagination: {
      page: 1,
      limit: 250,
      total,
      totalPages: Math.max(1, Math.ceil(total / 250)),
      hasNextPage: false,
      hasPreviousPage: false
    }
  });
}

afterEach(() => {
  cleanup();
  listMock.mockReset();
});

describe("Ücret Tipi Envanteri paneli (salt okunur)", () => {
  it("canonical read API'sini aktif + iç personel kapsamıyla çağırır", async () => {
    mockPage([personel({ id: 1, ucret_tipi_id: 3 })]);
    render(<UcretTipiEnvanteriPanel />);
    await screen.findByTestId("ucret-tipi-envanteri-sayaclar");

    expect(listMock).toHaveBeenCalledTimes(1);
    expect(listMock.mock.calls[0][0]).toMatchObject({
      aktiflik: "aktif",
      calisan_kapsami: "IC_PERSONEL",
      page: 1,
      limit: 250
    });
  });

  it("SAATLIK / GUNLUK / MAKTU_AYLIK / eksik / geçersiz sayaçlarını gösterir", async () => {
    mockPage([
      personel({ id: 1, ucret_tipi_id: 3, personel_tipi_adi: "Mavi Yaka" }),
      personel({ id: 2, ucret_tipi_id: 2, personel_tipi_adi: "Beyaz Yaka" }),
      personel({ id: 3, ucret_tipi_id: 1, personel_tipi_adi: "Beyaz Yaka" }),
      personel({ id: 4, ucret_tipi_id: 1, personel_tipi_adi: "Mavi Yaka" }),
      personel({ id: 5, personel_tipi_adi: "Mavi Yaka" }),
      personel({ id: 6, ucret_tipi_id: 9, personel_tipi_adi: "Beyaz Yaka" })
    ]);

    render(<UcretTipiEnvanteriPanel />);
    await screen.findByTestId("ucret-tipi-envanteri-sayaclar");

    expect(screen.getByTestId("ucret-tipi-envanteri-toplam")).toHaveTextContent("6");
    expect(screen.getByTestId("ucret-tipi-envanteri-sayac-SAATLIK")).toHaveTextContent("1");
    expect(screen.getByTestId("ucret-tipi-envanteri-sayac-GUNLUK")).toHaveTextContent("1");
    expect(screen.getByTestId("ucret-tipi-envanteri-sayac-MAKTU_AYLIK")).toHaveTextContent("2");
    expect(screen.getByTestId("ucret-tipi-envanteri-sayac-EKSIK")).toHaveTextContent("1");
    expect(screen.getByTestId("ucret-tipi-envanteri-sayac-GECERSIZ")).toHaveTextContent("1");
  });

  it("kişi bazında ad-soyad, statü, ücret tipi ve durum gösterir", async () => {
    mockPage([
      personel({ id: 11, ad: "Ayşe", soyad: "Yılmaz", ucret_tipi_id: 3, personel_tipi_adi: "Mavi Yaka" }),
      personel({ id: 12, ad: "Mehmet", soyad: "Kaya", personel_tipi_adi: "Beyaz Yaka" }),
      personel({ id: 13, ad: "Zeynep", soyad: "Demir", ucret_tipi_id: 9 })
    ]);

    render(<UcretTipiEnvanteriPanel />);
    await screen.findByTestId("ucret-tipi-envanteri-sayaclar");

    const saatlikRow = screen.getByTestId("ucret-tipi-envanteri-row-11");
    expect(saatlikRow).toHaveTextContent("Ayşe Yılmaz");
    expect(saatlikRow).toHaveTextContent("Mavi Yaka");
    expect(saatlikRow).toHaveTextContent("Saatlik");
    expect(screen.getByTestId("ucret-tipi-envanteri-durum-11")).toHaveTextContent("Tanımlı");

    const eksikRow = screen.getByTestId("ucret-tipi-envanteri-row-12");
    expect(eksikRow).toHaveTextContent("Beyaz Yaka");
    expect(eksikRow).toHaveTextContent("-");
    expect(screen.getByTestId("ucret-tipi-envanteri-durum-12")).toHaveTextContent("Eksik");

    const gecersizRow = screen.getByTestId("ucret-tipi-envanteri-row-13");
    expect(gecersizRow).toHaveTextContent("#9");
    expect(screen.getByTestId("ucret-tipi-envanteri-durum-13")).toHaveTextContent("Geçersiz");
  });

  it("Mavi/Beyaz statüsünden ücret tipi türetmez", async () => {
    mockPage([
      personel({ id: 21, personel_tipi_adi: "Mavi Yaka" }),
      personel({ id: 22, personel_tipi_adi: "Beyaz Yaka" })
    ]);

    render(<UcretTipiEnvanteriPanel />);
    await screen.findByTestId("ucret-tipi-envanteri-sayaclar");

    expect(screen.getByTestId("ucret-tipi-envanteri-sayac-SAATLIK")).toHaveTextContent("0");
    expect(screen.getByTestId("ucret-tipi-envanteri-sayac-GUNLUK")).toHaveTextContent("0");
    expect(screen.getByTestId("ucret-tipi-envanteri-sayac-MAKTU_AYLIK")).toHaveTextContent("0");
    expect(screen.getByTestId("ucret-tipi-envanteri-sayac-EKSIK")).toHaveTextContent("2");
  });

  it("salt okunur olduğunu bildirir ve yazma çağrısı yapmaz", async () => {
    mockPage([personel({ id: 31, ucret_tipi_id: 2 })]);

    render(<UcretTipiEnvanteriPanel />);
    await screen.findByTestId("ucret-tipi-envanteri-sayaclar");

    expect(screen.getByTestId("yonetim-section-ucret-tipi-envanteri")).toHaveTextContent(
      "Bu ekran hiçbir kaydı değiştirmez ve ücret tipi atamaz."
    );
    expect(listMock).toHaveBeenCalledTimes(1);
  });

  it("aktif iç personel yoksa boş durumu gösterir", async () => {
    mockPage([]);

    render(<UcretTipiEnvanteriPanel />);
    await screen.findByTestId("ucret-tipi-envanteri-empty");

    expect(screen.getByTestId("ucret-tipi-envanteri-empty")).toHaveTextContent(
      "Aktif iç personel bulunamadı."
    );
    expect(screen.queryByTestId("ucret-tipi-envanteri-tablo")).toBeNull();
  });
});
