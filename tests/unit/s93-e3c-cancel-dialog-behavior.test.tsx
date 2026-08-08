// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { PersonelBelgelerPanel } from "../../src/features/personeller/components/personel-dosya/PersonelBelgelerPanel";
import { MevzuatParametreleriPanel } from "../../src/features/yonetim/components/MevzuatParametreleriPanel";
import type { MevzuatParametresi } from "../../src/types/mevzuat";
import type { Personel } from "../../src/types/personel";
import type { PersonelBelgeKaydi } from "../../src/types/personel-belge-kaydi";

const cancelMevzuatParametresiMock = vi.hoisted(() => vi.fn());
const fetchMevzuatParametreleriMock = vi.hoisted(() => vi.fn());
const cancelPersonelBelgeKaydiMock = vi.hoisted(() => vi.fn());
const fetchPersonelBelgeKayitlariMock = vi.hoisted(() => vi.fn());
const createPersonelBelgeKaydiMock = vi.hoisted(() => vi.fn());
const fetchPersonelBelgeHistoryMock = vi.hoisted(() => vi.fn());
const downloadPersonelBelgeDosyaMock = vi.hoisted(() => vi.fn());
const replacePersonelBelgeDosyaMock = vi.hoisted(() => vi.fn());
const updatePersonelBelgeKaydiMock = vi.hoisted(() => vi.fn());
const fetchPersonelBelgeDurumuMock = vi.hoisted(() => vi.fn());
const useRoleAccessMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/api/mevzuat.api", () => ({
  cancelMevzuatParametresi: cancelMevzuatParametresiMock,
  createMevzuatParametresi: vi.fn(),
  fetchMevzuatParametreleri: fetchMevzuatParametreleriMock,
  getMevzuatApiErrorMessage: (_error: unknown, fallback: string) => fallback
}));

vi.mock("../../src/api/personel-belge-kayitlari.api", () => ({
  cancelPersonelBelgeKaydi: cancelPersonelBelgeKaydiMock,
  createPersonelBelgeKaydi: createPersonelBelgeKaydiMock,
  downloadPersonelBelgeDosya: downloadPersonelBelgeDosyaMock,
  fetchPersonelBelgeHistory: fetchPersonelBelgeHistoryMock,
  fetchPersonelBelgeKayitlari: fetchPersonelBelgeKayitlariMock,
  replacePersonelBelgeDosya: replacePersonelBelgeDosyaMock,
  updatePersonelBelgeKaydi: updatePersonelBelgeKaydiMock
}));

vi.mock("../../src/api/belgeler.api", () => ({
  fetchPersonelBelgeDurumu: fetchPersonelBelgeDurumuMock
}));

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: useRoleAccessMock
}));

vi.mock("react-router-dom", () => ({
  Link: ({ children, to, ...rest }: { children?: unknown; to: string; [key: string]: unknown }) => (
    <a href={typeof to === "string" ? to : "#"} {...rest}>
      {children as never}
    </a>
  )
}));

vi.mock("../../src/api/api-client", () => ({
  getApiErrorMessage: (_error: unknown, fallback: string) => fallback
}));

const testPersonel = {
  id: 1,
  ad: "Ayşe",
  soyad: "Yılmaz",
  aktif_durum: "AKTIF"
} as Personel;

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

function renderBelgelerPanel() {
  return render(
    <PersonelBelgelerPanel personel={testPersonel} isActive showBelgeDurumu={false} />
  );
}

describe("S93-E3C cancel dialog behavior", () => {
  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
    vi.clearAllMocks();
  });

  beforeEach(() => {
    useRoleAccessMock.mockReturnValue({
      hasPermission: (permission: string) =>
        permission === "surecler.create" ||
        permission === "surecler.update" ||
        permission === "surecler.cancel"
    });
    fetchMevzuatParametreleriMock.mockResolvedValue([makeMevzuat()]);
    cancelMevzuatParametresiMock.mockResolvedValue(undefined);
    fetchPersonelBelgeKayitlariMock.mockImplementation((_id: number, opts?: { state?: string }) => {
      if (opts?.state === "IPTAL") {
        return Promise.resolve({ items: [], pagination: null });
      }
      return Promise.resolve({ items: [makeBelge()], pagination: null });
    });
    cancelPersonelBelgeKaydiMock.mockResolvedValue(makeBelge({ durum: "IPTAL" }));
    fetchPersonelBelgeHistoryMock.mockResolvedValue([]);
    fetchPersonelBelgeDurumuMock.mockResolvedValue([]);
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
    renderBelgelerPanel();

    await screen.findByTestId("personel-belge-iptal-55");
    fireEvent.click(screen.getByTestId("personel-belge-iptal-55"));

    expect(await screen.findByTestId("personel-belge-action-dialog")).toBeVisible();
    expect(promptSpy).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText(/İptal nedeni/i), {
      target: { value: "E2E iptal nedeni" }
    });
    fireEvent.click(screen.getByTestId("personel-belge-action-dialog-confirm"));

    await waitFor(() =>
      expect(cancelPersonelBelgeKaydiMock).toHaveBeenCalledWith(55, {
        iptal_nedeni: "E2E iptal nedeni"
      })
    );
    await waitFor(() => expect(screen.queryByTestId("personel-belge-action-dialog")).toBeNull());
  });

  it("belge iptal: boş neden ile confirm disabled kalır", async () => {
    renderBelgelerPanel();

    await screen.findByTestId("personel-belge-iptal-55");
    fireEvent.click(screen.getByTestId("personel-belge-iptal-55"));
    const confirm = await screen.findByTestId("personel-belge-action-dialog-confirm");
    expect(confirm).toBeDisabled();
    expect(cancelPersonelBelgeKaydiMock).not.toHaveBeenCalled();
  });
});
