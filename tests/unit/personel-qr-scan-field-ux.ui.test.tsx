// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { PersonelQrScanPage } from "../../src/features/self-service/pages/PersonelQrScanPage";

const startQrScanner = vi.fn();

vi.mock("../../src/features/self-service/qr/qr-scanner", () => ({
  startQrScanner: (...args: unknown[]) => startQrScanner(...args),
  takePrimedQrCamera: () => null,
  primeQrCamera: () => undefined
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

beforeEach(() => {
  startQrScanner.mockResolvedValue({ stop: vi.fn() });
});

function renderScan(entry = "/self/qr-okut") {
  return render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/self/qr-okut" element={<PersonelQrScanPage />} />
      </Routes>
    </MemoryRouter>
  );
}

describe("PersonelQrScanPage field UX", () => {
  it("idle: shows QR Okut CTA without duplicate page heading", () => {
    renderScan();
    expect(screen.getByTestId("qr-scan-start")).toHaveTextContent("QR Okut");
    expect(screen.getByTestId("qr-scan-cta-zone")).toContainElement(screen.getByTestId("qr-scan-start"));
    expect(screen.queryByRole("heading", { level: 2 })).toBeNull();
    expect(screen.queryByTestId("qr-scan-lead")).toBeNull();
  });

  it("preset event opens the camera without a separate QR button", async () => {
    renderScan("/self/qr-okut?event=GIRIS");
    expect(screen.queryByTestId("qr-scan-start")).toBeNull();
    await waitFor(() => {
      expect(startQrScanner).toHaveBeenCalled();
    });
    expect(screen.queryByTestId("qr-scan-scanning")).toBeNull();
    expect(screen.getByTestId("qr-scan-video-wrap")).toHaveTextContent("Kodu çerçeveye hizalayın");
  });

  it("CTA click starts camera path and enters scanning state", async () => {
    renderScan();
    fireEvent.click(screen.getByTestId("qr-scan-start"));
    await waitFor(() => {
      expect(startQrScanner).toHaveBeenCalledTimes(1);
    });
    expect(screen.queryByTestId("qr-scan-scanning")).toBeNull();
    expect(screen.getByTestId("qr-scan-video-wrap")).toHaveTextContent("Kodu çerçeveye hizalayın");
  });

  it("choose state exposes GİRİŞ / ÇIKIŞ when no preset", async () => {
    startQrScanner.mockImplementation(async ({ onResult }: { onResult: (r: { rawValue: string }) => void }) => {
      queueMicrotask(() => onResult({ rawValue: "token-1" }));
      return { stop: vi.fn() };
    });
    renderScan();
    fireEvent.click(screen.getByTestId("qr-scan-start"));
    await waitFor(() => {
      expect(screen.getByTestId("qr-scan-choose")).toBeInTheDocument();
    });
    expect(screen.getByRole("button", { name: "GİRİŞ" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "ÇIKIŞ" })).toBeInTheDocument();
  });

  it("error state keeps retry CTA reachable", async () => {
    startQrScanner.mockImplementation(async ({ onError }: { onError: (m: string) => void }) => {
      queueMicrotask(() =>
        onError("Telefon Ayarlarınızdan Kamera Erişimine İzin Verin.")
      );
      return { stop: vi.fn() };
    });
    renderScan();
    fireEvent.click(screen.getByTestId("qr-scan-start"));
    await waitFor(() => {
      expect(screen.getByTestId("qr-scan-error")).toBeInTheDocument();
    });
    expect(screen.getByRole("button", { name: "Tekrar dene" })).toBeInTheDocument();
  });
});
