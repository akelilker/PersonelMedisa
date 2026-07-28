// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { PersonelBordroKapsamSection } from "../../src/features/personeller/components/personel-dosya/PersonelBordroKapsamSection";
import { PersonelUcretGecmisiSection } from "../../src/features/personeller/components/personel-dosya/PersonelUcretGecmisiSection";
import type { Personel } from "../../src/types/personel";
import type { PersonelBordroKapsamKaydi } from "../../src/types/personel-bordro-kapsam";
import type { PersonelUcretKaydi } from "../../src/types/ucret";

const cancelPersonelUcretMock = vi.hoisted(() => vi.fn());
const fetchPersonelUcretListMock = vi.hoisted(() => vi.fn());
const fetchPersonelAktifUcretMock = vi.hoisted(() => vi.fn());
const cancelPersonelBordroKapsamMock = vi.hoisted(() => vi.fn());
const fetchPersonelBordroKapsamlariMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/api/ucretler.api", () => ({
  cancelPersonelUcret: cancelPersonelUcretMock,
  createPersonelUcret: vi.fn(),
  fetchPersonelAktifUcret: fetchPersonelAktifUcretMock,
  fetchPersonelUcretList: fetchPersonelUcretListMock,
  getUcretApiErrorMessage: (_error: unknown, fallback: string) => fallback
}));

vi.mock("../../src/api/personel-bordro-kapsam.api", () => ({
  approvePersonelBordroKapsam: vi.fn(),
  cancelPersonelBordroKapsam: cancelPersonelBordroKapsamMock,
  createPersonelBordroKapsam: vi.fn(),
  dryRunPersonelBordroKapsam: vi.fn(),
  fetchPersonelBordroKapsamlari: fetchPersonelBordroKapsamlariMock,
  getBordroKapsamApiErrorMessage: (_error: unknown, fallback: string) => fallback,
  submitPersonelBordroKapsam: vi.fn()
}));

vi.mock("../../src/data/data-manager", () => ({
  dataCacheKeys: { personelDetail: () => "personel-detail" },
  deleteCacheEntry: vi.fn(),
  getActiveSube: () => 1
}));

const personel: Personel = {
  id: 7,
  tc_kimlik_no: "10000000146",
  ad: "Ayşe",
  soyad: "Yılmaz",
  aktif_durum: "AKTIF"
};

function makeUcret(overrides: Partial<PersonelUcretKaydi> = {}): PersonelUcretKaydi {
  return {
    id: 21,
    personel_id: 7,
    ucret_tutari: 42000,
    ucret_turu: "NET",
    para_birimi: "TRY",
    gecerlilik_baslangic: "2026-01-01",
    gecerlilik_bitis: null,
    durum: "AKTIF",
    guncel_mi: true,
    kaynak: "MANUEL",
    aciklama: null,
    ...overrides
  };
}

function makeKapsam(overrides: Partial<PersonelBordroKapsamKaydi> = {}): PersonelBordroKapsamKaydi {
  return {
    id: 31,
    personel_id: 7,
    sube_id: 1,
    durum: "HARIC",
    neden_kodu: "DEMO_TEST_VERISI",
    aciklama: "demo",
    gecerlilik_baslangic: "2026-03-01",
    gecerlilik_bitis: null,
    state: "TASLAK",
    ...overrides
  };
}

describe("S93-E3D cancel dialog behavior", () => {
  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
    vi.clearAllMocks();
  });

  beforeEach(() => {
    const ucret = makeUcret();
    fetchPersonelUcretListMock.mockResolvedValue([ucret]);
    fetchPersonelAktifUcretMock.mockResolvedValue(ucret);
    cancelPersonelUcretMock.mockResolvedValue(undefined);
    fetchPersonelBordroKapsamlariMock.mockResolvedValue([makeKapsam()]);
    cancelPersonelBordroKapsamMock.mockResolvedValue(makeKapsam({ state: "IPTAL" }));
  });

  it("ücret iptal: native dialog yok, AppActionDialog açılır ve API çağrılır", async () => {
    const confirmSpy = vi.spyOn(window, "confirm");
    render(<PersonelUcretGecmisiSection personel={personel} canManageUcret isActive />);

    await screen.findByTestId("personel-ucret-iptal-21");
    fireEvent.click(screen.getByTestId("personel-ucret-iptal-21"));

    expect(await screen.findByTestId("personel-ucret-action-dialog")).toBeVisible();
    await waitFor(() => expect(screen.getByTestId("personel-ucret-action-dialog-cancel")).toHaveFocus());
    expect(confirmSpy).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("personel-ucret-action-dialog-confirm"));
    await waitFor(() => expect(cancelPersonelUcretMock).toHaveBeenCalledWith(7, 21));
    await waitFor(() => expect(screen.queryByTestId("personel-ucret-action-dialog")).toBeNull());
  });

  it("ücret iptal: hata sonrası dialog açık kalır", async () => {
    cancelPersonelUcretMock.mockRejectedValueOnce(new Error("fail"));
    render(<PersonelUcretGecmisiSection personel={personel} canManageUcret isActive />);

    await screen.findByTestId("personel-ucret-iptal-21");
    fireEvent.click(screen.getByTestId("personel-ucret-iptal-21"));
    fireEvent.click(await screen.findByTestId("personel-ucret-action-dialog-confirm"));

    await waitFor(() =>
      expect(screen.getByTestId("personel-ucret-action-dialog-error")).toHaveTextContent(
        "Ücret kaydı iptal edilemedi."
      )
    );
    expect(screen.getByTestId("personel-ucret-action-dialog")).toBeVisible();
  });

  it("bordro kapsam iptal: prompt yerine field dialog açılır ve payload korunur", async () => {
    const promptSpy = vi.spyOn(window, "prompt");
    render(
      <PersonelBordroKapsamSection personel={personel} canManage canApprove={false} isActive />
    );

    await screen.findByTestId("personel-bordro-kapsam-cancel-31");
    fireEvent.click(screen.getByTestId("personel-bordro-kapsam-cancel-31"));

    expect(await screen.findByTestId("personel-bordro-kapsam-action-dialog")).toBeVisible();
    await waitFor(() =>
      expect(screen.getByTestId("personel-bordro-kapsam-action-dialog-cancel")).toHaveFocus()
    );
    expect(promptSpy).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText(/İptal nedeni/i), {
      target: { value: "E3D test iptal" }
    });
    fireEvent.click(screen.getByTestId("personel-bordro-kapsam-action-dialog-confirm"));

    await waitFor(() =>
      expect(cancelPersonelBordroKapsamMock).toHaveBeenCalledWith(7, 31, "E3D test iptal")
    );
    await waitFor(() => expect(screen.queryByTestId("personel-bordro-kapsam-action-dialog")).toBeNull());
  });

  it("bordro kapsam iptal: kısa neden field hatası verir, API çağrılmaz", async () => {
    render(
      <PersonelBordroKapsamSection personel={personel} canManage canApprove={false} isActive />
    );

    await screen.findByTestId("personel-bordro-kapsam-cancel-31");
    fireEvent.click(screen.getByTestId("personel-bordro-kapsam-cancel-31"));
    fireEvent.change(screen.getByLabelText(/İptal nedeni/i), { target: { value: "ab" } });
    fireEvent.click(screen.getByTestId("personel-bordro-kapsam-action-dialog-confirm"));

    expect(await screen.findByText("İptal nedeni en az 3 karakter olmalıdır.")).toBeVisible();
    expect(cancelPersonelBordroKapsamMock).not.toHaveBeenCalled();
    expect(screen.getByTestId("personel-bordro-kapsam-action-dialog")).toBeVisible();
  });
});
