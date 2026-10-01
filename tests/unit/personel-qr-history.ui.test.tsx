// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { PersonelQrHistoryPage } from "../../src/features/self-service/pages/PersonelQrHistoryPage";

vi.mock("../../src/api/qr.api", () => ({
  fetchMeQrHareketleri: vi.fn(async () => ({
    from: "2026-09-01",
    to: "2026-09-30",
    items: [],
    days: []
  }))
}));

afterEach(() => {
  cleanup();
});

describe("PersonelQrHistoryPage calendar product states", () => {
  it("renders month calendar and empty day hint", async () => {
    render(
      <MemoryRouter>
        <PersonelQrHistoryPage />
      </MemoryRouter>
    );

    expect(await screen.findByTestId("personel-qr-history-page")).toBeInTheDocument();
    expect(screen.getByTestId("qr-history-calendar")).toBeInTheDocument();
    expect(screen.getByText("Detay için bir gün seçin.")).toBeInTheDocument();
    expect(screen.queryByRole("heading", { level: 2 })).toBeNull();
  });

  it("shows day detail when a day with events is selected", async () => {
    const { fetchMeQrHareketleri } = await import("../../src/api/qr.api");
    vi.mocked(fetchMeQrHareketleri).mockResolvedValueOnce({
      from: "2026-10-01",
      to: "2026-10-31",
      items: [],
      days: [
        {
          date: "2026-10-19",
          has_events: true,
          giris: {
            id: 1,
            time: "08:12",
            occurred_at: "2026-10-19T05:12:00.000Z",
            status: { kind: "NORMAL", label: "Normal", delta_dakika: 0 }
          },
          cikis: {
            id: 2,
            time: "17:05",
            occurred_at: "2026-10-19T14:05:00.000Z",
            status: { kind: "NORMAL", label: "Normal", delta_dakika: 0 }
          },
          status_lines: ["Normal"]
        }
      ]
    });

    render(
      <MemoryRouter>
        <PersonelQrHistoryPage />
      </MemoryRouter>
    );

    expect(await screen.findByTestId("qr-history-calendar")).toBeInTheDocument();
    const dayButton = screen.getByRole("button", { name: /19/i });
    fireEvent.click(dayButton);
    expect(screen.getByTestId("qr-history-day-detail")).toBeInTheDocument();
    expect(screen.getByText("08:12")).toBeInTheDocument();
    expect(screen.getByText("17:05")).toBeInTheDocument();
  });
});
