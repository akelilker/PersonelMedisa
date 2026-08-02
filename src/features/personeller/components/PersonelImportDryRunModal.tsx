import { useRef, useState } from "react";
import { AppModal } from "../../../components/modal/AppModal";
import {
  downloadPersonelImportTemplateCsv,
  dryRunPersonelImport,
  type PersonelImportDryRunResult
} from "../../../api/personeller.api";
import { downloadReportCsv } from "../../../reports/export-report";
import { ApiRequestError } from "../../../api/api-client";

type PersonelImportDryRunModalProps = {
  open: boolean;
  onClose: () => void;
};

const INFO_MESSAGE =
  "Bu aşama yalnız doğrulama yapar. Personel, ücret veya bordro kaydı oluşturmaz.";

export function PersonelImportDryRunModal({ open, onClose }: PersonelImportDryRunModalProps) {
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [isRunning, setIsRunning] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [result, setResult] = useState<PersonelImportDryRunResult | null>(null);

  if (!open) {
    return null;
  }

  function resetState() {
    setSelectedFile(null);
    setIsRunning(false);
    setErrorMessage(null);
    setResult(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
  }

  function handleClose() {
    if (isRunning) {
      return;
    }
    resetState();
    onClose();
  }

  async function handleDownloadTemplate() {
    setErrorMessage(null);
    try {
      await downloadPersonelImportTemplateCsv();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Şablon indirilemedi.");
    }
  }

  async function handleDryRun() {
    if (!selectedFile || isRunning) {
      return;
    }
    setIsRunning(true);
    setErrorMessage(null);
    try {
      const dryRunResult = await dryRunPersonelImport(selectedFile);
      setResult(dryRunResult);
    } catch (error) {
      if (error instanceof ApiRequestError) {
        setErrorMessage(error.message);
      } else {
        setErrorMessage(error instanceof Error ? error.message : "Dry-run başarısız.");
      }
      setResult(null);
    } finally {
      setIsRunning(false);
    }
  }

  function handleDownloadErrorsCsv() {
    if (!result) {
      return;
    }
    const errorRows = result.satirlar.filter((row) => row.hata_kodlari.length > 0);
    downloadReportCsv(
      "personel-import-dry-run-hatalar.csv",
      ["satir_no", "sicil_no", "tc_kimlik_no_masked", "durum", "hata_kodlari"],
      errorRows.map((row) => ({
        satir_no: row.satir_no,
        sicil_no: row.sicil_no,
        tc_kimlik_no_masked: row.tc_kimlik_no_masked,
        durum: row.durum,
        hata_kodlari: row.hata_kodlari.join("|")
      }))
    );
  }

  const ozet = result?.ozet;

  return (
    <AppModal
      title="Toplu Personel Hazırlama"
      titleTestId="personel-import-dry-run-title"
      onClose={isRunning ? undefined : handleClose}
      className="personel-import-dry-run-modal"
      footer={
        <div className="universal-btn-group modal-footer-actions app-action-dialog-actions">
          <button
            type="button"
            className="universal-btn-aux"
            data-modal-initial-focus="true"
            data-testid="personel-import-dry-run-close"
            disabled={isRunning}
            onClick={handleClose}
          >
            Kapat
          </button>
        </div>
      }
    >
      <p
        className="personel-import-dry-run-info"
        data-testid="personel-import-dry-run-info"
      >
        {INFO_MESSAGE}
      </p>

      <div className="personel-import-dry-run-actions form-field-grid">
        <button
          type="button"
          className="universal-btn-aux"
          data-testid="personel-import-template-download"
          onClick={() => void handleDownloadTemplate()}
          disabled={isRunning}
        >
          CSV şablonunu indir
        </button>

        <label className="personel-import-file-label">
          <span>CSV seç</span>
          <input
            ref={fileInputRef}
            type="file"
            accept=".csv,text/csv"
            data-testid="personel-import-file-input"
            disabled={isRunning}
            onChange={(event) => {
              const file = event.target.files?.[0] ?? null;
              setSelectedFile(file);
              setResult(null);
              setErrorMessage(null);
            }}
          />
        </label>

        <button
          type="button"
          className="universal-btn-save"
          data-testid="personel-import-dry-run-run"
          disabled={!selectedFile || isRunning}
          onClick={() => void handleDryRun()}
        >
          {isRunning ? "Doğrulanıyor..." : "Dry-run çalıştır"}
        </button>
      </div>

      {selectedFile ? (
        <p className="personel-import-selected-file" data-testid="personel-import-selected-file">
          Seçili dosya: {selectedFile.name}
        </p>
      ) : null}

      {errorMessage ? (
        <p className="form-field-error" data-testid="personel-import-dry-run-error" role="alert">
          {errorMessage}
        </p>
      ) : null}

      {ozet ? (
        <div className="personel-import-summary" data-testid="personel-import-dry-run-summary">
          <div className="personel-import-summary-card">
            <span>Toplam</span>
            <strong>{ozet.toplam_satir}</strong>
          </div>
          <div className="personel-import-summary-card">
            <span>Geçerli</span>
            <strong>{ozet.gecerli_satir}</strong>
          </div>
          <div className="personel-import-summary-card">
            <span>Hatalı</span>
            <strong>{ozet.hatali_satir}</strong>
          </div>
          <div className="personel-import-summary-card">
            <span>Aday</span>
            <strong>{ozet.kayit_olusturulacak_aday}</strong>
          </div>
          <div className="personel-import-summary-card">
            <span>Mevcut</span>
            <strong>{ozet.veritabaninda_mevcut}</strong>
          </div>
        </div>
      ) : null}

      {result && result.satirlar.some((row) => row.hata_kodlari.length > 0) ? (
        <div className="personel-import-errors" data-testid="personel-import-dry-run-errors">
          <div className="personel-import-errors-header">
            <h3>Satır hataları</h3>
            <button
              type="button"
              className="universal-btn-aux"
              data-testid="personel-import-errors-download"
              onClick={handleDownloadErrorsCsv}
            >
              Hata CSV’si indir
            </button>
          </div>
          <div className="personel-import-errors-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Satır</th>
                  <th>Sicil</th>
                  <th>T.C. (maskeli)</th>
                  <th>Durum</th>
                  <th>Hata kodları</th>
                </tr>
              </thead>
              <tbody>
                {result.satirlar
                  .filter((row) => row.hata_kodlari.length > 0)
                  .map((row) => (
                    <tr key={`${row.satir_no}-${row.sicil_no}`}>
                      <td>{row.satir_no}</td>
                      <td>{row.sicil_no || "-"}</td>
                      <td>{row.tc_kimlik_no_masked}</td>
                      <td>{row.durum}</td>
                      <td>{row.hata_kodlari.join(", ")}</td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : null}
    </AppModal>
  );
}
