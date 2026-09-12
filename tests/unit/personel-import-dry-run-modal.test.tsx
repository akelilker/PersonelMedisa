// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { cleanup, render, screen, fireEvent, waitFor } from "@testing-library/react";
import { PersonelImportDryRunModal } from "../../src/features/personeller/components/PersonelImportDryRunModal";

vi.mock("../../src/api/personeller.api", () => ({
  downloadPersonelImportTemplateCsv: vi.fn(async () => undefined),
  downloadPersonelImportReferencesCsv: vi.fn(async () => undefined),
  applyPersonelImport: vi.fn(async () => {
    throw new Error("apply should not run in error dry-run case");
  }),
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
    source_sha256: "a".repeat(64),
    manifest_hash: "b".repeat(64),
    schema_version: "personel-import-v1",
    row_count: 1,
    valid_row_count: 0,
    can_apply: false,
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

  it("shows blocking hard-error banner instead of warning text", async () => {
    const { dryRunPersonelImport } = await import("../../src/api/personeller.api");
    (dryRunPersonelImport as any).mockResolvedValueOnce({
      ozet: {
        toplam_satir: 1,
        gecerli_satir: 0,
        hatali_satir: 1,
        warning_sayisi: 2,
        kayit_olusturulacak_aday: 0,
        veritabaninda_mevcut: 0
      },
      satirlar: [
        {
          satir_no: 12,
          sicil_no: "IMP-2",
          ad: "Can",
          soyad: "Kaya",
          tc_kimlik_no_masked: "100******62",
          durum: "HATALI",
          hata_kodlari: ["PERSONEL_IMPORT_REFERANS_BELIRSIZ"],
          uyarilar: ["BELIRSIZ|Şube|Ankara|Medisa Ankara ;; Karyapı Ankara"]
        }
      ],
      source_sha256: "a".repeat(64),
      manifest_hash: "b".repeat(64),
      schema_version: "personel-import-v1",
      row_count: 1,
      valid_row_count: 0,
      can_apply: false,
      yazma: { personel_write: false, salary_write: false, wage_model_assumption: false }
    });

    render(<PersonelImportDryRunModal open onClose={() => undefined} />);
    const file = new File(["tc_kimlik_no;sicil_no\n"], "personel.csv", { type: "text/csv" });
    fireEvent.change(screen.getByTestId("personel-import-file-input"), { target: { files: [file] } });
    fireEvent.click(screen.getByTestId("personel-import-dry-run-run"));

    await waitFor(() => {
      expect(screen.getByTestId("personel-import-dry-run-blocking")).toHaveTextContent(/hatalı kayıt bulundu/);
      expect(screen.queryByTestId("personel-import-dry-run-warnings")).toBeNull();
      expect(screen.queryByText(/Uyarılar aktarımı engellemez/)).toBeNull();
      expect(screen.getByTestId("personel-import-errors-download")).toHaveTextContent("Hata Dosyasını İndir");
      expect(screen.queryByTestId("personel-import-apply-open")).toBeNull();
    });
  });

  it("shows dry-run summary and masked TC errors without commit button", async () => {
    const { dryRunPersonelImport } = await import("../../src/api/personeller.api");

    render(<PersonelImportDryRunModal open onClose={() => undefined} />);

    expect(screen.getByTestId("personel-import-dry-run-title")).toHaveTextContent("Toplu Kayıt Aktarma");
    expect(screen.getByTestId("personel-import-back-kayit")).toBeInTheDocument();
    expect(screen.queryByTestId("personel-import-dry-run-close")).toBeNull();
    expect(screen.getByTestId("personel-import-dry-run-run")).toHaveTextContent("Dosyayı Kontrol Et");
    expect(screen.queryByTestId("personel-import-apply-open")).toBeNull();

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
      expect(screen.getAllByTestId("personel-import-issue-error").length).toBeGreaterThan(0);
      expect(screen.getByText(/Tarih bilgisi geçersiz/)).toBeInTheDocument();
      expect(screen.queryByText(/PERSONEL_IMPORT_GECERSIZ_TARIH/)).toBeNull();
      expect(screen.queryByTestId("personel-import-apply-open")).toBeNull();
    });
  });
});
