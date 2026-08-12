/** @vitest-environment jsdom */
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { QrPuantajAdayiSection } from "../../src/features/puantaj/components/QrPuantajAdayiSection";

const useRoleAccessMock = vi.hoisted(() => vi.fn());
const fetchQrPuantajAdaylariMock = vi.hoisted(() => vi.fn());
const postQrPuantajAdayKararMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: useRoleAccessMock
}));

vi.mock("../../src/api/puantaj.api", () => ({
  fetchQrPuantajAdaylari: fetchQrPuantajAdaylariMock,
  postQrPuantajAdayKarar: postQrPuantajAdayKararMock
}));

vi.mock("../../src/components/modal/AppModal", () => ({
  AppModal: ({ children, title }: { children: React.ReactNode; title: string }) => (
    <div data-testid="app-modal">
      <h2>{title}</h2>
      {children}
    </div>
  )
}));

function baseItem(overrides: Record<string, unknown> = {}) {
  return {
    candidate_date: "2026-08-12",
    classification: "READY_SINGLE_INTERVAL",
    comparison_status: "DIFFERS_CANONICAL_TIME",
    candidate_hash: "ab".repeat(32),
    proposed: { giris_saati: "08:00", cikis_saati: "17:00" },
    canonical: {
      exists: true,
      puantaj_id: 1,
      giris_saati: "09:00",
      cikis_saati: "18:00",
      state: "ACIK",
      kontrol_durumu: "BEKLIYOR"
    },
    qr: { matched_seconds: 32400 },
    period: { state: "ACIK", canonical_write_open: true },
    review: {
      state: "UNREVIEWED",
      can_apply: true,
      can_keep_canonical: true,
      can_reopen_review: false,
      blocking_code: null
    },
    ...overrides
  };
}

describe("QrPuantajAdayiSection dependent-field block UI", () => {
  beforeEach(() => {
    useRoleAccessMock.mockReturnValue({
      hasPermission: (perm: string) => perm === "puantaj.view" || perm === "puantaj.update"
    });
    fetchQrPuantajAdaylariMock.mockReset();
    postQrPuantajAdayKararMock.mockReset();
  });

  afterEach(() => {
    cleanup();
  });

  it("hides apply and shows Turkish dependent-field review message", async () => {
    fetchQrPuantajAdaylariMock.mockResolvedValue({
      items: [
        baseItem({
          review: {
            state: "UNREVIEWED",
            can_apply: false,
            can_keep_canonical: true,
            can_reopen_review: false,
            blocking_code: "QR_APPLY_DEPENDENT_FIELDS_REQUIRE_MANUAL_REVIEW"
          }
        })
      ]
    });

    render(
      <MemoryRouter>
        <QrPuantajAdayiSection personelId={7} tarih="2026-08-12" />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.getByTestId("qr-puantaj-aday-dependent-review")).toBeTruthy();
    });

    expect(screen.getByTestId("qr-puantaj-aday-dependent-review").textContent).toContain(
      "Manuel puantaj incelemesi gerekir"
    );
    expect(screen.getByTestId("qr-puantaj-aday-dependent-review").textContent).toContain(
      "giriş/çıkış saatlerine bağlı hesaplanmış alanlar"
    );
    expect(screen.queryByTestId("qr-puantaj-aday-apply")).toBeNull();
    expect(screen.getByTestId("qr-puantaj-aday-keep")).toBeTruthy();
  });

  it("keeps apply visible when dependent block is absent", async () => {
    fetchQrPuantajAdaylariMock.mockResolvedValue({ items: [baseItem()] });

    render(
      <MemoryRouter>
        <QrPuantajAdayiSection personelId={7} tarih="2026-08-12" />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.getByTestId("qr-puantaj-aday-apply")).toBeTruthy();
    });
    expect(screen.queryByTestId("qr-puantaj-aday-dependent-review")).toBeNull();
  });
});
