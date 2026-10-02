/** @vitest-environment jsdom */
import { describe, expect, it, vi, beforeEach } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

const headerTamamlama = {
  id: 77,
  okundu_mi: false,
  tarih: "2026-09-01",
  sube_id: 1
};

vi.mock("../../src/api/api-client", () => ({
  shouldPreferDemoApi: () => false,
  isApiRequestError: () => false
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({
    logout: vi.fn(),
    session: {
      user: { rol: "BIRIM_AMIRI", ad_soyad: "Amir", sube_ids: [1] },
      active_sube_id: 1
    },
    setActiveSubeId: vi.fn()
  })
}));

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: () => ({
    hasPermission: (perm: string) =>
      perm === "bildirimler.view" ||
      perm === "bildirimler.detail.view" ||
      perm === "attendance.correction.decide",
    uiProfile: "default"
  })
}));

vi.mock("../../src/hooks/useBildirimler", () => ({
  useBildirimlerHeaderPreview: () => ({
    items: [headerTamamlama],
    isLoading: false,
    errorMessage: null,
    reload: vi.fn(),
    markOkundu: vi.fn()
  })
}));

vi.mock("../../src/api/attendance-mobile.api", () => ({
  fetchInboxNotifications: vi.fn().mockResolvedValue({
    items: [
      {
        id: 5,
        kind: "ATTENDANCE_CORRECTION_REQUEST",
        title: "Düzeltme Talebi",
        body: "Personel düzeltme istedi",
        payload: null,
        related_correction_id: 99,
        popup_required: true,
        popup_consumed: false,
        created_at: "2026-01-01T00:00:00Z"
      }
    ]
  }),
  ackInboxPopup: vi.fn()
}));

vi.mock("../../src/data/data-manager", () => ({
  getAppData: () => ({ updatedAt: "2026-01-01T00:00:00Z" }),
  useAppDataRevision: () => 0
}));

vi.mock("../../src/lib/bildirim/header-notification-copy", () => ({
  formatHeaderGunlukTamamlamaCopy: () => ({
    title: "Gunluk Tamamlama",
    subtitle: "Bekleyen kayit"
  }),
  formatHeaderReminderCopy: () => ({ title: "Reminder", subtitle: "Soon" })
}));

import { ShellHeaderActions } from "../../src/components/shell/ShellHeaderActions";

describe("manager header notifications coexistence (behavioral)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows inbox correction and existing tamamlama notifications together", async () => {
    render(
      <MemoryRouter>
        <ShellHeaderActions contextLabel="Test" minimal />
      </MemoryRouter>
    );

    fireEvent.click(screen.getByLabelText("Bildirimleri aç"));

    await waitFor(() => {
      expect(screen.getByText("Düzeltme Talebi")).toBeTruthy();
      expect(screen.getByText("Gunluk Tamamlama")).toBeTruthy();
    });
  });
});
