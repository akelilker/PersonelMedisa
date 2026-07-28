// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { DonemKapanisMerkeziPage } from "../../src/features/raporlar/pages/DonemKapanisMerkeziPage";
import type { DonemKapanisPreflightSummary } from "../../src/api/donem-kapanis.api";

const useRoleAccessMock = vi.hoisted(() => vi.fn());
const useDonemKapanisPreflightMock = vi.hoisted(() => vi.fn());
const muhurleAylikPuantajMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: useRoleAccessMock
}));

vi.mock("../../src/hooks/useDonemKapanisPreflight", () => ({
  useDonemKapanisPreflight: useDonemKapanisPreflightMock
}));

vi.mock("../../src/api/puantaj.api", () => ({
  muhurleAylikPuantaj: muhurleAylikPuantajMock
}));

vi.mock("../../src/api/yonetim.api", () => ({
  fetchYonetimSubeleri: vi.fn().mockResolvedValue([{ id: 1, ad: "Merkez" }])
}));

vi.mock("../../src/api/referans.api", () => ({
  fetchDepartmanOptions: vi.fn().mockResolvedValue([{ id: 3, label: "Operasyon" }])
}));

vi.mock("../../src/api/donem-kapanis.api", () => ({
  downloadDonemKapanisPreflightCsv: vi.fn()
}));

const authSession = vi.hoisted(() => ({
  active_sube_id: 1,
  sube_list: [{ id: 1, ad: "Merkez" }],
  user: { id: 1, rol: "GENEL_YONETICI", sube_ids: [1] }
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({
    session: authSession
  })
}));

const sealableSummary: DonemKapanisPreflightSummary = {
  sube: { id: 1, kod: "MRK", ad: "Merkez" },
  yil: 2026,
  ay: 6,
  donem: "2026-06",
  donem_state: "ACIK",
  muhur_state: "ACIK",
  muhur_id: null,
  kapanabilir_mi: true,
  blocker_count: 0,
  warning_count: 0,
  info_count: 0,
  kategori_sayaclari: { etki_adayi: 0, finans: 0 },
  blockers: [],
  warnings: [],
  infos: [],
  candidate_state_counts: { HAZIR: 0, INCELEME_GEREKLI: 0, UYGULANDI: 0, YOK_SAYILDI: 0 },
  notification_chain_counts: { toplam: 0 },
  puantaj_counts: { toplam_satir: 1, kontrol_bekleyen: 0 },
  finance_readiness: { eksik_maas_sayisi: 0, finans_kayit_sayisi: 0 },
  preflight_hash: "seal-ok",
  schema_version: "S76_PERIOD_CLOSE_PREFLIGHT_V1",
  generated_at: "2026-07-16T00:00:00+00:00"
};

function renderPage() {
  return render(
    <MemoryRouter>
      <DonemKapanisMerkeziPage />
    </MemoryRouter>
  );
}

describe("S95 dönem mühür confirm dialog behavior", () => {
  beforeEach(() => {
    useRoleAccessMock.mockReturnValue({
      hasPermission: (permission: string) =>
        permission === "puantaj.donem_kapanis.view" ||
        permission === "puantaj.muhurle" ||
        permission === "puantaj.donem_kapanis.export"
    });
    useDonemKapanisPreflightMock.mockReturnValue({
      summary: sealableSummary,
      audits: [],
      isLoading: false,
      isAuditsLoading: false,
      errorMessage: null,
      auditsErrorMessage: null,
      buildParams: vi.fn().mockReturnValue({ sube_id: 1, yil: 2026, ay: 6 }),
      refetch: vi.fn(),
      refetchAudits: vi.fn()
    });
    muhurleAylikPuantajMock.mockResolvedValue({
      donem: "2026-06",
      muhurlenen_kayit_sayisi: 4
    });
  });

  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
    vi.clearAllMocks();
  });

  it("onay sonrası mühür API çağrılır ve dialog kapanır", async () => {
    renderPage();
    await waitFor(() => {
      const sube = screen.getByLabelText("Şube") as HTMLSelectElement;
      expect(sube.value).toBe("1");
    });
    fireEvent.click(screen.getByTestId("donem-kapanis-muhurle"));
    fireEvent.click(await screen.findByTestId("donem-kapanis-muhur-action-dialog-confirm"));
    await waitFor(() => expect(muhurleAylikPuantajMock).toHaveBeenCalled());
    expect(muhurleAylikPuantajMock.mock.calls[0]?.[0]).toEqual(
      expect.objectContaining({ yil: expect.any(Number), ay: expect.any(Number) })
    );
    await waitFor(() => expect(screen.queryByTestId("donem-kapanis-muhur-action-dialog")).toBeNull());
    expect(screen.getByTestId("donem-kapanis-action-success")).toHaveTextContent(/mühürlendi/i);
  });

  it("API hatası dialog içinde kalır", async () => {
    muhurleAylikPuantajMock.mockRejectedValueOnce(new Error("Mühürleme reddedildi."));
    renderPage();
    await waitFor(() => {
      const sube = screen.getByLabelText("Şube") as HTMLSelectElement;
      expect(sube.value).toBe("1");
    });
    fireEvent.click(screen.getByTestId("donem-kapanis-muhurle"));
    fireEvent.click(await screen.findByTestId("donem-kapanis-muhur-action-dialog-confirm"));
    await waitFor(() =>
      expect(screen.getByTestId("donem-kapanis-muhur-action-dialog-error")).toHaveTextContent(
        "Mühürleme reddedildi."
      )
    );
    expect(screen.getByTestId("donem-kapanis-muhur-action-dialog")).toBeVisible();
  });

  it("mühür butonu AppActionDialog açar; vazgeç native dialog üretmez", async () => {
    renderPage();
    await waitFor(() => {
      const sube = screen.getByLabelText("Şube") as HTMLSelectElement;
      expect(sube.value).toBe("1");
    });
    fireEvent.click(screen.getByTestId("donem-kapanis-muhurle"));
    expect(await screen.findByTestId("donem-kapanis-muhur-action-dialog")).toBeVisible();
    await waitFor(() => expect(screen.getByTestId("donem-kapanis-muhur-action-dialog-cancel")).toHaveFocus());
    fireEvent.click(screen.getByTestId("donem-kapanis-muhur-action-dialog-cancel"));
    await waitFor(() => expect(screen.queryByTestId("donem-kapanis-muhur-action-dialog")).toBeNull());
    expect(muhurleAylikPuantajMock).not.toHaveBeenCalled();
  });
});
