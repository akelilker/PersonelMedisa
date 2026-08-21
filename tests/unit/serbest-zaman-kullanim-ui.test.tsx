/** @vitest-environment jsdom */
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { SerbestZamanTakipPage } from "../../src/features/raporlar/pages/SerbestZamanTakipPage";
import { fetchSerbestZamanDeadlineTakip, postSerbestZamanKullanim } from "../../src/api/serbest-zaman.api";

vi.mock("../../src/api/serbest-zaman.api", () => ({
  fetchSerbestZamanDeadlineTakip: vi.fn(),
  postSerbestZamanKullanim: vi.fn(),
}));

describe("SerbestZamanTakipPage UI", () => {
  beforeEach(() => {
    (fetchSerbestZamanDeadlineTakip as any).mockResolvedValue({
      items: [
        {
          personel_id: 1,
          ad_soyad: "Ahmet Yılmaz",
          sicil_no: "P-101",
          sube_ad: "Merkez",
          allocation_state: "OK",
          olusum_event_id: 100,
          son_kullanim_tarihi: "2026-12-31",
          available_dakika: 120,
          kalan_gun: 30,
          deadline_state: "NORMAL",
          compliance_action: "NONE"
        }
      ],
      summary: {
        yaklasan_lot_sayisi: 0,
        yaklasan_dakika: 0,
        suresi_dolmus_lot_sayisi: 0,
        suresi_dolmus_kullanilmamis_dakika: 0,
        allocation_unresolved_personel_sayisi: 0
      },
      total: 1,
      total_pages: 1,
      page: 1
    });

    (postSerbestZamanKullanim as any).mockResolvedValue({
      id: 999,
      personel_id: 1,
      event_tipi: "SERBEST_ZAMAN_KULLANIM",
      dakika: 60,
      event_tarihi: "2026-10-15",
      islem_anahtari: "test-uuid"
    });
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  function renderPage() {
    return render(
      <MemoryRouter>
        <SerbestZamanTakipPage />
      </MemoryRouter>
    );
  }

  it("renders page and opens Kullanim modal", async () => {
    renderPage();
    await waitFor(() => {
      expect(screen.getByTestId("serbest-zaman-takip-page")).toBeTruthy();
    });

    const openBtn = screen.getByText("Serbest Zaman Kullanımı Ekle");
    fireEvent.click(openBtn);

    await waitFor(() => {
      expect(screen.getByText("Serbest Zaman Kullanımı Ekle", { selector: 'h2' })).toBeTruthy();
    });
    
    const personelIdInput = document.getElementById("kullanim-personel-id") as HTMLInputElement;
    expect(personelIdInput).toBeTruthy();
  });

  it("submits valid form and calls API correctly", async () => {
    renderPage();
    await waitFor(() => {
      expect(screen.getByTestId("serbest-zaman-takip-page")).toBeTruthy();
    });

    // Clear initial load call
    (fetchSerbestZamanDeadlineTakip as any).mockClear();

    const openBtn = screen.getByText("Serbest Zaman Kullanımı Ekle");
    fireEvent.click(openBtn);

    await waitFor(() => {
      const personelIdInput = document.getElementById("kullanim-personel-id") as HTMLInputElement;
    expect(personelIdInput).toBeTruthy();
    });

    const personelIdInput = document.getElementById("kullanim-personel-id") as HTMLInputElement;
    fireEvent.change(personelIdInput, { target: { value: "1" } });
    fireEvent.change(document.getElementById("kullanim-tarih") as HTMLInputElement, { target: { value: "2026-10-15" } });
    fireEvent.change(document.getElementById("kullanim-dakika") as HTMLInputElement, { target: { value: "60" } });
    fireEvent.change(document.getElementById("kullanim-aciklama") as HTMLInputElement, { target: { value: "Test kullanımı" } });

    const submitBtn = screen.getByRole("button", { name: /Kaydet/i });
    fireEvent.click(submitBtn);

    await waitFor(() => {
      expect(postSerbestZamanKullanim).toHaveBeenCalledTimes(1);
    });

    const payload = (postSerbestZamanKullanim as any).mock.calls[0][0];
    expect(payload.personel_id).toBe(1);
    expect(payload.dakika).toBe(60);
    expect(payload.event_tarihi).toBe("2026-10-15");
    expect(payload.aciklama).toBe("Test kullanımı");
    expect(payload.islem_anahtari).toBeTruthy();

    // Modal should close and list should reload
    await waitFor(() => {
      expect(fetchSerbestZamanDeadlineTakip).toHaveBeenCalledTimes(1);
    });
  });

  it("prevents submission with invalid empty dakika/personel", async () => {
    renderPage();
    await waitFor(() => {
      expect(screen.getByTestId("serbest-zaman-takip-page")).toBeTruthy();
    });

    const openBtn = screen.getByText("Serbest Zaman Kullanımı Ekle");
    fireEvent.click(openBtn);

    await waitFor(() => {
      const personelIdInput = document.getElementById("kullanim-personel-id") as HTMLInputElement;
    expect(personelIdInput).toBeTruthy();
    });

    // Submitting without filling required HTML5 fields will trigger form validation,
    // but we can test the component logic by forcing empty values
    const personelIdInput = document.getElementById("kullanim-personel-id") as HTMLInputElement;
    fireEvent.change(personelIdInput, { target: { value: "" } });
    fireEvent.change(document.getElementById("kullanim-dakika") as HTMLInputElement, { target: { value: "" } });

    // Assuming we bypass HTML5 validation or we fire submit on form
    const form = document.getElementById("serbest-zaman-kullanim-form");
    fireEvent.submit(form!);

    await waitFor(() => {
      expect(screen.getByText(/Personel ID ve Dakika alanları zorunludur/i)).toBeTruthy();
    });

    expect(postSerbestZamanKullanim).not.toHaveBeenCalled();
  });
});
