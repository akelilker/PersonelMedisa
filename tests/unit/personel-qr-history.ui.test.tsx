// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { PersonelQrHistoryPage } from "../../src/features/self-service/pages/PersonelQrHistoryPage";

vi.mock("../../src/api/qr.api", () => ({
  fetchMeQrHareketleri: vi.fn(async () => ({ items: [] })),
  fetchMeQrAraliklari: vi.fn(async () => ({
    intervals: [],
    anomalies: [],
    summary: {
      complete_interval_count: 0,
      anomaly_count: 0,
      complete_duration_seconds: 0
    }
  }))
}));

afterEach(() => {
  cleanup();
});

describe("PersonelQrHistoryPage empty/list product states", () => {
  it("renders empty states and puantaj expectation note", async () => {
    render(
      <MemoryRouter>
        <PersonelQrHistoryPage />
      </MemoryRouter>
    );

    expect(await screen.findByTestId("personel-qr-history-page")).toBeInTheDocument();
    expect(screen.getByTestId("qr-puantaj-expectation-note")).toBeInTheDocument();
    expect(screen.getByTestId("personel-qr-raw-empty")).toBeInTheDocument();
    expect(screen.getByTestId("personel-qr-intervals-empty")).toBeInTheDocument();
    expect(screen.getByText("Ham QR Kayıtları")).toBeInTheDocument();
    expect(screen.getByText("QR Eşleşmeleri")).toBeInTheDocument();
  });

  it("renders GİRİŞ/ÇIKIŞ badges for raw history items", async () => {
    const { fetchMeQrHareketleri } = await import("../../src/api/qr.api");
    vi.mocked(fetchMeQrHareketleri).mockResolvedValueOnce({
      items: [
        {
          id: 1,
          event_type: "GIRIS",
          occurred_at: "2026-09-19T05:12:00.000Z",
          sube: { id: 1, ad: "Merkez" }
        },
        {
          id: 2,
          event_type: "CIKIS",
          occurred_at: "2026-09-19T14:05:00.000Z",
          sube: { id: 1, ad: "Merkez" }
        }
      ]
    } as never);

    render(
      <MemoryRouter>
        <PersonelQrHistoryPage />
      </MemoryRouter>
    );

    expect(await screen.findByTestId("personel-qr-raw-list")).toBeInTheDocument();
    expect(screen.getByText("GİRİŞ")).toBeInTheDocument();
    expect(screen.getByText("ÇIKIŞ")).toBeInTheDocument();
    expect(screen.queryByTestId("personel-qr-raw-empty")).toBeNull();
  });
});
