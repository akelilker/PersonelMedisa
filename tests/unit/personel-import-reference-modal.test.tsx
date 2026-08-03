// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { cleanup, render, screen, fireEvent, waitFor } from "@testing-library/react";
import { PersonelImportDryRunModal } from "../../src/features/personeller/components/PersonelImportDryRunModal";
import { ApiRequestError } from "../../src/api/api-client";

const downloadPersonelImportReferencesCsv = vi.fn(async () => undefined);
const downloadPersonelImportTemplateCsv = vi.fn(async () => undefined);
const dryRunPersonelImport = vi.fn(async () => ({
  ozet: {
    toplam_satir: 1,
    gecerli_satir: 1,
    hatali_satir: 0,
    warning_sayisi: 0,
    kayit_olusturulacak_aday: 1,
    veritabaninda_mevcut: 0
  },
  satirlar: [
    {
      satir_no: 2,
      sicil_no: "IMP-1",
      tc_kimlik_no_masked: "100******46",
      durum: "GECERLI",
      hata_kodlari: [],
      uyarilar: []
    }
  ],
  source_sha256: "a".repeat(64),
  manifest_hash: "b".repeat(64),
  schema_version: "personel-import-v1",
  row_count: 1,
  valid_row_count: 1,
  can_apply: true,
  yazma: {
    personel_write: false,
    salary_write: false,
    wage_model_assumption: false
  }
}));
const applyPersonelImport = vi.fn(async () => {
  throw new Error("apply should not run");
});

vi.mock("../../src/api/personeller.api", () => ({
  downloadPersonelImportReferencesCsv: (...args: unknown[]) =>
    downloadPersonelImportReferencesCsv(...args),
  downloadPersonelImportTemplateCsv: (...args: unknown[]) =>
    downloadPersonelImportTemplateCsv(...args),
  dryRunPersonelImport: (...args: unknown[]) => dryRunPersonelImport(...args),
  applyPersonelImport: (...args: unknown[]) => applyPersonelImport(...args)
}));

describe("PersonelImportDryRunModal reference pack", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
  });

  it("shows exact reference info and downloads without clearing selected CSV/result", async () => {
    render(<PersonelImportDryRunModal open onClose={() => undefined} canApply />);

    expect(screen.getByTestId("personel-import-reference-match-info")).toHaveTextContent(
      "CSV’de şube, departman, görev ve personel tipi değerlerini referans dosyasında göründüğü şekilde yazın"
    );
    expect(screen.getByTestId("personel-import-reference-freshness-info")).toBeInTheDocument();

    const file = new File(["header\n"], "personel.csv", { type: "text/csv" });
    fireEvent.change(screen.getByTestId("personel-import-file-input"), {
      target: { files: [file] }
    });
    fireEvent.click(screen.getByTestId("personel-import-dry-run-run"));
    await waitFor(() => expect(dryRunPersonelImport).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByTestId("personel-import-dry-run-summary")).toBeInTheDocument());

    fireEvent.click(screen.getByTestId("personel-import-references-download"));
    await waitFor(() => expect(downloadPersonelImportReferencesCsv).toHaveBeenCalledTimes(1));

    expect(screen.getByTestId("personel-import-selected-file")).toHaveTextContent("personel.csv");
    expect(screen.getByTestId("personel-import-dry-run-summary")).toBeInTheDocument();
    expect(applyPersonelImport).not.toHaveBeenCalled();
  });

  it("guards double-click and surfaces API errors without demo success", async () => {
    let resolveDownload: (() => void) | null = null;
    downloadPersonelImportReferencesCsv.mockImplementationOnce(
      () =>
        new Promise<void>((resolve) => {
          resolveDownload = resolve;
        })
    );

    render(<PersonelImportDryRunModal open onClose={() => undefined} />);
    fireEvent.click(screen.getByTestId("personel-import-references-download"));
    fireEvent.click(screen.getByTestId("personel-import-references-download"));
    expect(downloadPersonelImportReferencesCsv).toHaveBeenCalledTimes(1);
    resolveDownload?.();
    await waitFor(() =>
      expect(screen.getByTestId("personel-import-references-download")).not.toBeDisabled()
    );

    downloadPersonelImportReferencesCsv.mockRejectedValueOnce(
      new ApiRequestError("Referans paketi hazır değil.", 409)
    );
    fireEvent.click(screen.getByTestId("personel-import-references-download"));
    await waitFor(() =>
      expect(screen.getByTestId("personel-import-dry-run-error")).toHaveTextContent(
        "Referans paketi hazır değil."
      )
    );
  });
});
