// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { PersonelQrHistorySection } from "../../src/features/personeller/components/personel-dosya/PersonelQrHistorySection";
import type { Personel } from "../../src/types/personel";

const personel = { id: 42 } as Personel;

const sampleRow = {
  personel_id: 42,
  ad_soyad: "Test Personel",
  sicil_no: "T001",
  sube_id: 1,
  sube: "Merkez",
  date_from: "2026-09-19",
  date_to: "2026-09-19",
  first_entry: "2026-09-19T05:12:00.000Z",
  last_exit: "2026-09-19T14:05:00.000Z",
  last_movement: "2026-09-19T14:05:00.000Z",
  last_movement_type: "CIKIS" as const,
  inside: false,
  interval_count: 1,
  missing_entry: false,
  missing_exit: false,
  branch_mismatch: false,
  anomalies: [],
  matched_seconds: 8 * 3600 + 53 * 60,
  source_event_count: 2
};

vi.mock("../../src/api/qr.api", () => ({
  fetchManagerQrAttendance: vi.fn(async () => ({
    from: "2026-08-21",
    to: "2026-09-20",
    items: [sampleRow],
    total: 1,
    limit: 100,
    offset: 0,
    has_next: false,
    algorithm_version: "v1"
  }))
}));

afterEach(() => {
  cleanup();
});

describe("PersonelQrHistorySection mobile card list", () => {
  it("renders desktop table and mobile cards for loaded rows", async () => {
    render(
      <MemoryRouter>
        <PersonelQrHistorySection personel={personel} />
      </MemoryRouter>
    );

    expect(await screen.findByTestId("personel-qr-history")).toBeInTheDocument();
    expect(screen.getByRole("table")).toBeInTheDocument();
    const cards = await screen.findByTestId("personel-qr-history-cards");
    expect(cards).toBeInTheDocument();
    expect(screen.getByTestId("personel-qr-history-card-2026-09-19")).toBeInTheDocument();
    expect(screen.getAllByText("2026-09-19").length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByRole("link", { name: "Günlük puantaj" }).length).toBe(2);
    const card = screen.getByTestId("personel-qr-history-card-2026-09-19");
    expect(card).toHaveTextContent("08:12 · 17:05");
    expect(card).toHaveTextContent("8s 53dk");
    expect(card).toHaveTextContent("Tamamlandı · Yok");
  });
});
