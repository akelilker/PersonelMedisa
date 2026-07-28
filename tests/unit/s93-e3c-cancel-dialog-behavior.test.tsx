// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { KayitBelgeKayitlariSection } from "../../src/features/kayit/components/KayitBelgeKayitlariSection";
import { MevzuatParametreleriPanel } from "../../src/features/yonetim/components/MevzuatParametreleriPanel";
import type { MevzuatParametresi } from "../../src/types/mevzuat";
import type { PersonelBelgeKaydi } from "../../src/types/personel-belge-kaydi";

const cancelMevzuatParametresiMock = vi.hoisted(() => vi.fn());
const fetchMevzuatParametreleriMock = vi.hoisted(() => vi.fn());
const cancelPersonelBelgeKaydiMock = vi.hoisted(() => vi.fn());
const fetchPersonelBelgeKayitlariMock = vi.hoisted(() => vi.fn());
const createPersonelBelgeKaydiMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/api/mevzuat.api", () => ({
  cancelMevzuatParametresi: cancelMevzuatParametresiMock,
  createMevzuatParametresi: vi.fn(),
  fetchMevzuatParametreleri: fetchMevzuatParametreleriMock,
  getMevzuatApiErrorMessage: (_error: unknown, fallback: string) => fallback
}));

vi.mock("../../src/api/personel-belge-kayitlari.api", () => ({
  cancelPersonelBelgeKaydi: cancelPersonelBelgeKaydiMock,
  createPersonelBelgeKaydi: createPersonelBelgeKaydiMock,
  fetchPersonelBelgeKayitlari: fetchPersonelBelgeKayitlariMock
}));

vi.mock("../../src/api/api-client", () => ({
  getApiErrorMessage: (_error: unknown, fallback: string) => fallback
}));

function makeMevzuat(overrides: Partial<MevzuatParametresi> = {}): MevzuatParametresi {
  return {
    id: 11,
    parametre_kodu: "ASGARI_UCRET_BRUT",
    deger_tipi: "SAYISAL",
    sayisal_deger: 17002.0,
    metin_deger: null,
    birim: "TL",
    gecerlilik_baslangic: "2026-01-01",
    gecerlilik_bitis: null,
    durum: "AKTIF",
    aciklama: null,
    kaynak_referansi: null,
    ...overrides
  };
}

function makeBelge(overrides: Partial<PersonelBelgeKaydi> = {}): PersonelBelgeKaydi {
  return {
    id: 55,
    personel_id: 1,
    kayit_tipi: "SERTIFIKA",
    ad: "ISG Temel",
    veren_kurum: "Merkez",
    belge_no: "B-1",
    belge_no_masked: "B-1",
    baslangic_tarihi: "2026-01-01",
    bitis_tarihi: "2027-01-01",
    durum: "AKTIF",
    gecerlilik_durumu: "GECERLI",
    takip_durumu: "AKTIF",
    ek_ref: null,
    aciklama: null,
    ...overrides
  };
}

describe("S93-E3C cancel dialog behavior", () => {
  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
    vi.clearAllMocks();
  });

  beforeEach(() => {
    fetchMevzuatParametreleriMock.mockResolvedValue([makeMevzuat()]);
    cancelMevzuatParametresiMock.mockResolvedValue(undefined);
    fetchPersonelBelgeKayitlariMock.mockResolvedValue({ items: [makeBelge()], pagination: null });
    cancelPersonelBelgeKaydiMock.mockResolvedValue(makeBelge({ durum: "IPTAL" }));
  });

  it("mevzuat iptal: native dialog yok, AppActionDialog açılır ve API çağrılır", async () => {
    const confirmSpy = vi.spyOn(window, "confirm");
    render(<MevzuatParametreleriPanel canManage />);

    await screen.findByTestId("yonetim-mevzuat-iptal-11");
    fireEvent.click(screen.getByTestId("yonetim-mevzuat-iptal-11"));

    expect(await screen.findByTestId("mevzuat-action-dialog")).toBeVisible();
    await waitFor(() => expect(screen.getByTestId("mevzuat-action-dialog-cancel")).toHaveFocus());
    expect(confirmSpy).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("mevzuat-action-dialog-confirm"));
    await waitFor(() => expect(cancelMevzuatParametresiMock).toHaveBeenCalledWith(11));
    await waitFor(() => expect(screen.queryByTestId("mevzuat-action-dialog")).toBeNull());
  });

  it("mevzuat iptal: hata sonrası dialog açık kalır", async () => {
    cancelMevzuatParametresiMock.mockRejectedValueOnce(new Error("fail"));
    render(<MevzuatParametreleriPanel canManage />);

    await screen.findByTestId("yonetim-mevzuat-iptal-11");
    fireEvent.click(screen.getByTestId("yonetim-mevzuat-iptal-11"));
    fireEvent.click(await screen.findByTestId("mevzuat-action-dialog-confirm"));

    await waitFor(() =>
      expect(screen.getByTestId("mevzuat-action-dialog-error")).toHaveTextContent(
        "Mevzuat parametresi iptal edilemedi."
      )
    );
    expect(screen.getByTestId("mevzuat-action-dialog")).toBeVisible();
  });

  it("belge iptal: prompt yerine field dialog açılır ve payload korunur", async () => {
    const promptSpy = vi.spyOn(window, "prompt");
    render(
      <KayitBelgeKayitlariSection
        personelId={1}
        personelLabel="Ayşe Yılmaz"
        isPersonelPasif={false}
        canWrite
        isActive
      />
    );

    await screen.findByTestId("kayit-belge-kayit-iptal-55");
    fireEvent.click(screen.getByTestId("kayit-belge-kayit-iptal-55"));

    expect(await screen.findByTestId("belge-kayit-action-dialog")).toBeVisible();
    expect(promptSpy).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText(/İptal nedeni/i), {
      target: { value: "E2E iptal nedeni" }
    });
    fireEvent.click(screen.getByTestId("belge-kayit-action-dialog-confirm"));

    await waitFor(() =>
      expect(cancelPersonelBelgeKaydiMock).toHaveBeenCalledWith(55, {
        iptal_nedeni: "E2E iptal nedeni"
      })
    );
    await waitFor(() => expect(screen.queryByTestId("belge-kayit-action-dialog")).toBeNull());
  });

  it("belge iptal: boş neden ile confirm disabled kalır", async () => {
    render(
      <KayitBelgeKayitlariSection
        personelId={1}
        personelLabel="Ayşe Yılmaz"
        isPersonelPasif={false}
        canWrite
        isActive
      />
    );

    await screen.findByTestId("kayit-belge-kayit-iptal-55");
    fireEvent.click(screen.getByTestId("kayit-belge-kayit-iptal-55"));
    const confirm = await screen.findByTestId("belge-kayit-action-dialog-confirm");
    expect(confirm).toBeDisabled();
    expect(cancelPersonelBelgeKaydiMock).not.toHaveBeenCalled();
  });
});
