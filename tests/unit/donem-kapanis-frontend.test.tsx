/** @vitest-environment jsdom */
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { DonemKapanisMerkeziPage } from "../../src/features/raporlar/pages/DonemKapanisMerkeziPage";
import type { DonemKapanisPreflightSummary } from "../../src/api/donem-kapanis.api";

const useRoleAccessMock = vi.hoisted(() => vi.fn());
const useDonemKapanisPreflightMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: useRoleAccessMock
}));

vi.mock("../../src/hooks/useDonemKapanisPreflight", () => ({
  useDonemKapanisPreflight: useDonemKapanisPreflightMock
}));

vi.mock("../../src/api/yonetim.api", () => ({
  fetchYonetimSubeleri: vi.fn().mockResolvedValue([{ id: 1, ad: "Merkez" }])
}));

vi.mock("../../src/api/referans.api", () => ({
  fetchDepartmanOptions: vi.fn().mockResolvedValue([{ id: 3, label: "Operasyon" }])
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

const baseSummary: DonemKapanisPreflightSummary = {
  sube: { id: 1, kod: "MRK", ad: "Merkez" },
  yil: 2026,
  ay: 6,
  donem: "2026-06",
  donem_state: "ACIK",
  muhur_state: "ACIK",
  muhur_id: null,
  kapanabilir_mi: false,
  blocker_count: 1,
  warning_count: 1,
  info_count: 0,
  kategori_sayaclari: { etki_adayi: 1, finans: 1 },
  blockers: [
    {
      code: "CANDIDATE_HAZIR_PENDING",
      severity: "BLOCKER",
      domain: "etki_adayi",
      title: "Hazir etki adayi bekliyor",
      message: "Uygulanmayi bekleyen HAZIR etki adayi var.",
      count: 1,
      owner_role: "MUHASEBE",
      action_route: "/puantaj",
      action_permission: "puantaj.bildirim_etki.apply",
      record_ids: [3],
      metadata: {}
    }
  ],
  warnings: [
    {
      code: "FINANCE_SALARY_MISSING",
      severity: "WARNING",
      domain: "finans",
      title: "Eksik maas bilgisi",
      message: "Aktif personelde maas bilgisi eksik.",
      count: 1,
      owner_role: "MUHASEBE",
      action_route: "/personeller",
      action_permission: "personeller.detail.view",
      record_ids: [8],
      metadata: {}
    }
  ],
  infos: [],
  candidate_state_counts: { HAZIR: 1, INCELEME_GEREKLI: 0, UYGULANDI: 0, YOK_SAYILDI: 0 },
  notification_chain_counts: { toplam: 0 },
  puantaj_counts: { toplam_satir: 1, kontrol_bekleyen: 0 },
  finance_readiness: { eksik_maas_sayisi: 1, finans_kayit_sayisi: 0 },
  preflight_hash: "abc123",
  schema_version: "S76_PERIOD_CLOSE_PREFLIGHT_V1",
  generated_at: "2026-07-16T00:00:00+00:00"
};

function makeHookState(overrides: Record<string, unknown> = {}) {
  return {
    summary: baseSummary,
    audits: [],
    isLoading: false,
    isAuditsLoading: false,
    errorMessage: null,
    auditsErrorMessage: null,
    buildParams: vi.fn().mockReturnValue({ sube_id: 1, yil: 2026, ay: 6 }),
    refetch: vi.fn(),
    refetchAudits: vi.fn(),
    ...overrides
  };
}

function renderPage() {
  return render(
    <MemoryRouter>
      <DonemKapanisMerkeziPage />
    </MemoryRouter>
  );
}

describe("DonemKapanisMerkeziPage frontend", () => {
  beforeEach(() => {
    useRoleAccessMock.mockReturnValue({
      hasPermission: (permission: string) =>
        permission === "puantaj.donem_kapanis.view" || permission === "puantaj.muhurle"
    });
    useDonemKapanisPreflightMock.mockReturnValue(makeHookState());
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it("shows seal action only when user has puantaj.muhurle", () => {
    renderPage();
    expect(screen.getByTestId("donem-kapanis-muhurle")).toBeTruthy();
  });

  it("hides seal action without puantaj.muhurle permission", () => {
    useRoleAccessMock.mockReturnValue({
      hasPermission: (permission: string) => permission === "puantaj.donem_kapanis.view"
    });
    renderPage();
    expect(screen.queryByTestId("donem-kapanis-muhurle")).toBeNull();
  });

  it("renders blocker and warning severities with distinct labels", () => {
    renderPage();
    expect(screen.getByTestId("donem-kapanis-issue-CANDIDATE_HAZIR_PENDING")).toBeTruthy();
    expect(screen.getByTestId("donem-kapanis-severity-CANDIDATE_HAZIR_PENDING").textContent).toContain("Engelleyici");
    expect(screen.getByTestId("donem-kapanis-severity-FINANCE_SALARY_MISSING").textContent).toContain("Uyarı");
    expect(screen.getByTestId("donem-kapanis-blockers")).toBeTruthy();
    expect(screen.getByTestId("donem-kapanis-warnings")).toBeTruthy();
  });

  it("disables seal button when blockers exist even with muhur permission", () => {
    renderPage();
    expect((screen.getByTestId("donem-kapanis-muhurle") as HTMLButtonElement).disabled).toBe(true);
  });

  it("enables seal button when warnings exist but no blockers", () => {
    useDonemKapanisPreflightMock.mockReturnValue(
      makeHookState({
        summary: {
          ...baseSummary,
          kapanabilir_mi: true,
          blocker_count: 0,
          blockers: []
        }
      })
    );
    renderPage();
    expect((screen.getByTestId("donem-kapanis-muhurle") as HTMLButtonElement).disabled).toBe(false);
  });

  it("disables seal action when period is already sealed", () => {
    useRoleAccessMock.mockReturnValue({
      hasPermission: () => true
    });
    useDonemKapanisPreflightMock.mockReturnValue(
      makeHookState({
        summary: {
          ...baseSummary,
          donem_state: "MUHURLU",
          muhur_state: "MUHURLENDI",
          muhur_id: 12,
          kapanabilir_mi: false,
          blocker_count: 0,
          warning_count: 0,
          blockers: [],
          warnings: []
        }
      })
    );
    renderPage();
    expect(screen.getByTestId("donem-kapanis-durum-label").textContent).toContain("Dönem mühürlü");
    expect((screen.getByTestId("donem-kapanis-muhurle") as HTMLButtonElement).disabled).toBe(true);
  });
});
