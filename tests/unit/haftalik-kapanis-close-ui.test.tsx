/** @vitest-environment jsdom */
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { ApiRequestError } from "../../src/api/api-client";
import { HaftalikKapanisPage } from "../../src/features/revizyon/pages/HaftalikKapanisPage";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";
import type { UserRole } from "../../src/types/auth";

const createHaftalikKapanisMock = vi.hoisted(() => vi.fn());
const fetchDepartmanOptionsMock = vi.hoisted(() => vi.fn());
const fetchPersonellerListMock = vi.hoisted(() => vi.fn());
const fetchRevizyonKaynaklarMock = vi.hoisted(() => vi.fn());
const useRoleAccessMock = vi.hoisted(() => vi.fn());

const authSession = vi.hoisted(() => ({
  active_sube_id: 1 as number | null,
  sube_list: [
    { id: 1, ad: "Merkez" },
    { id: 2, ad: "Giresun" }
  ],
  user: { id: 1, rol: "GENEL_YONETICI" as UserRole, sube_ids: [1, 2] }
}));

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: useRoleAccessMock
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({
    session: authSession
  })
}));

vi.mock("../../src/api/haftalik-kapanis.api", () => ({
  createHaftalikKapanis: createHaftalikKapanisMock
}));

vi.mock("../../src/api/referans.api", () => ({
  fetchDepartmanOptions: fetchDepartmanOptionsMock
}));

vi.mock("../../src/api/personeller.api", () => ({
  fetchPersonellerList: fetchPersonellerListMock
}));

vi.mock("../../src/api/revizyon-talebi.api", () => ({
  fetchRevizyonKaynaklar: fetchRevizyonKaynaklarMock
}));

function permissionsForRole(role: UserRole) {
  return {
    hasPermission: (permission: string) => hasRolePermission(role, permission as never)
  };
}

function renderPage() {
  return render(
    <MemoryRouter>
      <HaftalikKapanisPage />
    </MemoryRouter>
  );
}

async function fillValidMondayAndOpenConfirm() {
  const weekInput = screen.getByTestId("hk-close-hafta-baslangic");
  fireEvent.change(weekInput, { target: { value: "2026-04-06" } });
  await waitFor(() => {
    expect(screen.getByTestId("hk-close-hafta-bitis").textContent).toContain("2026-04-12");
  });
  fireEvent.click(screen.getByTestId("hk-close-open"));
  await waitFor(() => {
    expect(screen.getByTestId("hk-close-confirm-dialog")).toBeTruthy();
  });
}

describe("HaftalikKapanisClosePanel UI", () => {
  beforeEach(() => {
    authSession.active_sube_id = 1;
    authSession.user.rol = "GENEL_YONETICI";
    authSession.user.sube_ids = [1, 2];
    authSession.sube_list = [
      { id: 1, ad: "Merkez" },
      { id: 2, ad: "Giresun" }
    ];
    useRoleAccessMock.mockReturnValue(permissionsForRole("GENEL_YONETICI"));
    fetchDepartmanOptionsMock.mockResolvedValue([
      { id: 3, label: "Operasyon" },
      { id: 6, label: "Depo" }
    ]);
    fetchPersonellerListMock.mockResolvedValue({
      items: [{ id: 1, ad: "Ayşe", soyad: "Yılmaz", sicil_no: "P-0001" }]
    });
    fetchRevizyonKaynaklarMock.mockResolvedValue([]);
    createHaftalikKapanisMock.mockReset();
    createHaftalikKapanisMock.mockResolvedValue({
      id: 55,
      kapanis_id: 55,
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12",
      state: "KAPANDI",
      personel_sayisi: 4,
      snapshot_satir_sayisi: 4,
      snapshot_satirlari: []
    });
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it.each([
    ["GENEL_YONETICI", true],
    ["BOLUM_YONETICISI", true],
    ["MUHASEBE", false],
    ["BIRIM_AMIRI", false],
    ["PATRON", false]
  ] as const)("%s close action visibility = %s", async (role, visible) => {
    useRoleAccessMock.mockReturnValue(permissionsForRole(role));
    authSession.user.rol = role;
    renderPage();
    await waitFor(() => {
      expect(screen.getByTestId("haftalik-kapanis-page")).toBeTruthy();
    });
    if (visible) {
      expect(screen.getByTestId("hk-close-open")).toBeTruthy();
    } else {
      expect(screen.queryByTestId("hk-close-open")).toBeNull();
      expect(screen.queryByTestId("hk-close-panel")).toBeNull();
    }
  });

  it("disables close when active_sube_id is null and does not POST", async () => {
    authSession.active_sube_id = null;
    renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-close-open")).toBeTruthy());
    fireEvent.change(screen.getByTestId("hk-close-hafta-baslangic"), {
      target: { value: "2026-04-06" }
    });
    expect((screen.getByTestId("hk-close-open") as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByTestId("hk-close-active-sube-required").textContent).toMatch(
      /aktif şube seçilmelidir/i
    );
    expect(createHaftalikKapanisMock).not.toHaveBeenCalled();
  });

  it("keeps empty week disabled and rejects non-Monday without POST", async () => {
    renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-close-open")).toBeTruthy());
    expect((screen.getByTestId("hk-close-open") as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(screen.getByTestId("hk-close-hafta-baslangic"), {
      target: { value: "2026-04-07" }
    });
    expect(screen.getByTestId("hk-close-monday-error")).toBeTruthy();
    expect((screen.getByTestId("hk-close-open") as HTMLButtonElement).disabled).toBe(true);
    expect(createHaftalikKapanisMock).not.toHaveBeenCalled();

    fireEvent.change(screen.getByTestId("hk-close-hafta-baslangic"), {
      target: { value: "2026-04-06" }
    });
    await waitFor(() => {
      expect(screen.getByTestId("hk-close-hafta-bitis").textContent).toContain("2026-04-12");
    });
    expect((screen.getByTestId("hk-close-open") as HTMLButtonElement).disabled).toBe(false);
  });

  it("opens confirm without POST; cancel keeps POST=0; confirm POSTs once", async () => {
    renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-close-open")).toBeTruthy());
    await fillValidMondayAndOpenConfirm();
    expect(createHaftalikKapanisMock).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("hk-close-confirm-dialog-cancel"));
    await waitFor(() => {
      expect(screen.queryByTestId("hk-close-confirm-dialog")).toBeNull();
    });
    expect(createHaftalikKapanisMock).not.toHaveBeenCalled();

    await fillValidMondayAndOpenConfirm();
    fireEvent.click(screen.getByTestId("hk-close-confirm-dialog-confirm"));
    await waitFor(() => expect(createHaftalikKapanisMock).toHaveBeenCalledTimes(1));

    const payload = createHaftalikKapanisMock.mock.calls[0]![0] as Record<string, unknown>;
    expect(payload).toEqual({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12"
    });
    expect(Object.prototype.hasOwnProperty.call(payload, "departman_id")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(payload, "sube_id")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(payload, "created_by")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(payload, "actor_id")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(payload, "user_id")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(payload, "state")).toBe(false);

    await waitFor(() => expect(screen.getByTestId("hk-close-success")).toBeTruthy());
    expect(screen.getByTestId("hk-close-success-id").textContent).toContain("55");
    expect(screen.getByTestId("hk-close-success-state").textContent).toContain("KAPANDI");
  });

  it("sends department payload with numeric departman_id", async () => {
    renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-close-open")).toBeTruthy());
    fireEvent.change(screen.getByTestId("hk-close-hafta-baslangic"), {
      target: { value: "2026-04-06" }
    });
    fireEvent.click(screen.getByTestId("hk-close-scope-departman"));
    await waitFor(() => expect(screen.getByTestId("hk-close-departman")).toBeTruthy());
    fireEvent.change(screen.getByTestId("hk-close-departman"), { target: { value: "3" } });
    fireEvent.click(screen.getByTestId("hk-close-open"));
    await waitFor(() => expect(screen.getByTestId("hk-close-confirm-dialog")).toBeTruthy());
    fireEvent.click(screen.getByTestId("hk-close-confirm-dialog-confirm"));
    await waitFor(() => expect(createHaftalikKapanisMock).toHaveBeenCalledTimes(1));
    expect(createHaftalikKapanisMock.mock.calls[0]![0]).toEqual({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12",
      departman_id: 3
    });
  });

  it("shows mutabakat 409 without success", async () => {
    createHaftalikKapanisMock.mockRejectedValue(
      new ApiRequestError("Haftalik mutabakat tamamlanmamis.", 409, { code: "STATE_CONFLICT" })
    );
    renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-close-open")).toBeTruthy());
    await fillValidMondayAndOpenConfirm();
    fireEvent.click(screen.getByTestId("hk-close-confirm-dialog-confirm"));
    await waitFor(() => {
      expect(screen.getByTestId("hk-close-confirm-error").textContent).toMatch(
        /mutabakat tamamlanmamis/i
      );
    });
    expect(screen.queryByTestId("hk-close-success")).toBeNull();
  });

  it("shows duplicate 409 without success", async () => {
    createHaftalikKapanisMock.mockRejectedValue(
      new ApiRequestError(
        "Bu sube, hafta ve departman kapsami icin haftalik kapanis zaten olusturulmus.",
        409,
        { code: "STATE_CONFLICT" }
      )
    );
    renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-close-open")).toBeTruthy());
    await fillValidMondayAndOpenConfirm();
    fireEvent.click(screen.getByTestId("hk-close-confirm-dialog-confirm"));
    await waitFor(() => {
      expect(screen.getByTestId("hk-close-confirm-error").textContent).toMatch(/zaten olusturulmus/i);
    });
    expect(screen.queryByTestId("hk-close-success")).toBeNull();
  });

  it("protects double submit so POST runs once", async () => {
    let resolveClose: ((value: unknown) => void) | null = null;
    createHaftalikKapanisMock.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveClose = resolve;
        })
    );
    renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-close-open")).toBeTruthy());
    await fillValidMondayAndOpenConfirm();
    const confirm = screen.getByTestId("hk-close-confirm-dialog-confirm");
    fireEvent.click(confirm);
    fireEvent.click(confirm);
    expect(createHaftalikKapanisMock).toHaveBeenCalledTimes(1);
    resolveClose?.({
      id: 1,
      kapanis_id: 1,
      state: "KAPANDI",
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12",
      personel_sayisi: 1,
      snapshot_satir_sayisi: 1,
      snapshot_satirlari: []
    });
    await waitFor(() => expect(screen.getByTestId("hk-close-success")).toBeTruthy());
  });

  it("keeps existing haftalik kapanis links", async () => {
    renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-revizyon-merkezi-link")).toBeTruthy());
    expect(screen.getByTestId("hk-onay-bekleyenler-link")).toBeTruthy();
    expect(screen.getByTestId("hk-corrections-link")).toBeTruthy();
    expect(screen.getByTestId("hk-revizyon-talebi-ac")).toBeTruthy();
  });

  it("scopes local success/duplicate guard by active_sube_id", async () => {
    const view = renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-close-open")).toBeTruthy());
    await fillValidMondayAndOpenConfirm();
    fireEvent.click(screen.getByTestId("hk-close-confirm-dialog-confirm"));
    await waitFor(() => expect(screen.getByTestId("hk-close-success")).toBeTruthy());
    expect((screen.getByTestId("hk-close-open") as HTMLButtonElement).disabled).toBe(true);
    expect(createHaftalikKapanisMock).toHaveBeenCalledTimes(1);

    authSession.active_sube_id = 2;
    view.rerender(
      <MemoryRouter>
        <HaftalikKapanisPage />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByTestId("hk-close-success")).toBeNull();
    });
    expect(screen.getByTestId("hk-close-active-sube").textContent).toMatch(/Giresun/);
    expect((screen.getByTestId("hk-close-open") as HTMLButtonElement).disabled).toBe(false);

    fireEvent.click(screen.getByTestId("hk-close-open"));
    await waitFor(() => expect(screen.getByTestId("hk-close-confirm-dialog")).toBeTruthy());
    fireEvent.click(screen.getByTestId("hk-close-confirm-dialog-confirm"));
    await waitFor(() => expect(createHaftalikKapanisMock).toHaveBeenCalledTimes(2));

    const secondPayload = createHaftalikKapanisMock.mock.calls[1]![0] as Record<string, unknown>;
    expect(secondPayload).toEqual({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12"
    });
    expect(Object.prototype.hasOwnProperty.call(secondPayload, "sube_id")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(secondPayload, "active_sube_id")).toBe(false);
    await waitFor(() => expect(screen.getByTestId("hk-close-success")).toBeTruthy());
  });

  it("closes idle confirm dialog when active branch changes without POST", async () => {
    const view = renderPage();
    await waitFor(() => expect(screen.getByTestId("hk-close-open")).toBeTruthy());
    await fillValidMondayAndOpenConfirm();
    expect(createHaftalikKapanisMock).not.toHaveBeenCalled();

    authSession.active_sube_id = 2;
    view.rerender(
      <MemoryRouter>
        <HaftalikKapanisPage />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByTestId("hk-close-confirm-dialog")).toBeNull();
    });
    expect(createHaftalikKapanisMock).not.toHaveBeenCalled();
  });
});
