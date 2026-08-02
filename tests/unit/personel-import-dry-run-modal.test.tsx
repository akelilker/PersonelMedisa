// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { cleanup, render, screen, fireEvent, waitFor } from "@testing-library/react";
import { PersonelImportDryRunModal } from "../../src/features/personeller/components/PersonelImportDryRunModal";

vi.mock("../../src/api/personeller.api", () => ({
  downloadPersonelImportTemplateCsv: vi.fn(async () => undefined),
  dryRunPersonelImport: vi.fn(async () => ({
    ozet: {
      toplam_satir: 1,
      gecerli_satir: 0,
      hatali_satir: 1,
      warning_sayisi: 0,
      kayit_olusturulacak_aday: 0,
      veritabaninda_mevcut: 0
    },
    satirlar: [
      {
        satir_no: 2,
        sicil_no: "IMP-1",
        tc_kimlik_no_masked: "100******46",
        durum: "HATALI",
        hata_kodlari: ["PERSONEL_IMPORT_GECERSIZ_TARIH", "=CMD|'/C calc'!A0"],
        uyarilar: []
      }
    ],
    yazma: {
      personel_write: false,
      salary_write: false,
      wage_model_assumption: false
    }
  }))
}));

describe("PersonelImportDryRunModal", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
  });

  it("shows dry-run summary and masked TC errors without commit button", async () => {
    const { dryRunPersonelImport } = await import("../../src/api/personeller.api");

    render(<PersonelImportDryRunModal open onClose={() => undefined} />);

    expect(
      screen.getByText("Bu aşama yalnız doğrulama yapar. Personel, ücret veya bordro kaydı oluşturmaz.")
    ).toBeInTheDocument();
    expect(screen.queryByText(/Sisteme aktar/i)).toBeNull();

    const file = new File(
      [
        "tc_kimlik_no;sicil_no;ad;soyad;dogum_tarihi;dogum_yeri;telefon;kan_grubu;acil_durum_kisi;acil_durum_telefon;ise_giris_tarihi;sube;departman;gorev;personel_tipi\n"
      ],
      "personel.csv",
      { type: "text/csv" }
    );
    const input = screen.getByTestId("personel-import-file-input") as HTMLInputElement;
    fireEvent.change(input, { target: { files: [file] } });

    fireEvent.click(screen.getByTestId("personel-import-dry-run-run"));

    await waitFor(() => {
      expect(dryRunPersonelImport).toHaveBeenCalledTimes(1);
      expect(screen.getByTestId("personel-import-dry-run-summary")).toBeInTheDocument();
      expect(screen.getByText("100******46")).toBeInTheDocument();
      expect(screen.getByText(/PERSONEL_IMPORT_GECERSIZ_TARIH/)).toBeInTheDocument();
    });
  });
});
