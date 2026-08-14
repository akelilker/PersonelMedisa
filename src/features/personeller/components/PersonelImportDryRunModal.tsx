import { useMemo, useRef, useState } from "react";
import { AppModal } from "../../../components/modal/AppModal";
import { AppActionDialog } from "../../../components/modal/AppActionDialog";
import {
  applyPersonelImport,
  downloadPersonelImportReferencesCsv,
  downloadPersonelImportTemplateCsv,
  dryRunPersonelImport,
  type PersonelImportApplyResult,
  type PersonelImportDryRunResult
} from "../../../api/personeller.api";
import { downloadReportCsv } from "../../../reports/export-report";
import {
  importErrorMessage,
  visibleImportError
} from "../personel-import-error-messages";

type PersonelImportDryRunModalProps = {
  open: boolean;
  onClose: () => void;
  canApply?: boolean;
  onApplied?: () => void;
};

const INFO_MESSAGE =
  "Bu aşama yalnız doğrulama yapar. Personel, ücret veya bordro kaydı oluşturmaz.";

const REFERENCE_MATCH_MESSAGE =
  "CSV’de şube, departman, görev ve personel tipi değerlerini referans dosyasında göründüğü şekilde yazın. Bu alanlarda tam eşleşme kullanılır.";

const REFERENCE_FRESHNESS_MESSAGE =
  "Referans listesi güncel sistem kayıtlarından hazırlanır. Dosya hazırlandıktan sonra sistem kayıtları değişirse dry-run işlemini yeniden çalıştırın.";

const APPLY_CONFIRM_MESSAGE =
  "Bu işlem yalnız personel ana kayıtlarını oluşturur. Ücret, bordro kapsamı ve SGK statüsü oluşturmaz.";

const CONFIRMATION_TOKEN = "PERSONEL_IMPORT_ONAYLIYORUM";

function createIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return `pir-${crypto.randomUUID()}`;
  }
  return `pir-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

export function PersonelImportDryRunModal({
  open,
  onClose,
  canApply = false,
  onApplied
}: PersonelImportDryRunModalProps) {
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const referencesDownloadGuardRef = useRef(false);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [isRunning, setIsRunning] = useState(false);
  const [isApplying, setIsApplying] = useState(false);
  const [isDownloadingReferences, setIsDownloadingReferences] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [result, setResult] = useState<PersonelImportDryRunResult | null>(null);
  const [applyResult, setApplyResult] = useState<PersonelImportApplyResult | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [confirmText, setConfirmText] = useState("");
  const [idempotencyKey, setIdempotencyKey] = useState<string | null>(null);

  const applyEnabled = useMemo(() => {
    if (!canApply || !selectedFile || !result || applyResult) {
      return false;
    }
    return (
      result.can_apply === true &&
      result.ozet.hatali_satir === 0 &&
      result.manifest_hash.length === 64 &&
      result.source_sha256.length === 64 &&
      result.ozet.toplam_satir > 0
    );
  }, [applyResult, canApply, result, selectedFile]);

  if (!open) {
    return null;
  }

  function resetState() {
    setSelectedFile(null);
    setIsRunning(false);
    setIsApplying(false);
    setIsDownloadingReferences(false);
    referencesDownloadGuardRef.current = false;
    setErrorMessage(null);
    setResult(null);
    setApplyResult(null);
    setConfirmOpen(false);
    setConfirmText("");
    setIdempotencyKey(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
  }

  function handleClose() {
    if (isRunning || isApplying || isDownloadingReferences) {
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
      setErrorMessage(visibleImportError(error, "Şablon indirilemedi."));
    }
  }

  async function handleDownloadReferences() {
    if (isDownloadingReferences || referencesDownloadGuardRef.current || isRunning || isApplying) {
      return;
    }
    referencesDownloadGuardRef.current = true;
    setIsDownloadingReferences(true);
    setErrorMessage(null);
    try {
      await downloadPersonelImportReferencesCsv();
    } catch (error) {
      setErrorMessage(visibleImportError(error, "Referans paketi indirilemedi."));
    } finally {
      setIsDownloadingReferences(false);
      referencesDownloadGuardRef.current = false;
    }
  }

  async function handleDryRun() {
    if (!selectedFile || isRunning || isApplying || isDownloadingReferences) {
      return;
    }
    setIsRunning(true);
    setErrorMessage(null);
    setApplyResult(null);
    setIdempotencyKey(null);
    try {
      const dryRunResult = await dryRunPersonelImport(selectedFile);
      setResult(dryRunResult);
      setIdempotencyKey(createIdempotencyKey());
    } catch (error) {
      setErrorMessage(visibleImportError(error, "Doğrulama tamamlanamadı."));
      setResult(null);
      setIdempotencyKey(null);
    } finally {
      setIsRunning(false);
    }
  }

  async function handleApplyConfirm() {
    if (!selectedFile || !result || !idempotencyKey || isApplying || !applyEnabled) {
      return;
    }
    if (confirmText.trim() !== CONFIRMATION_TOKEN) {
      setErrorMessage("Onay metni PERSONEL_IMPORT_ONAYLIYORUM olmalıdır.");
      return;
    }

    setIsApplying(true);
    setErrorMessage(null);
    try {
      const applied = await applyPersonelImport(selectedFile, {
        manifest_hash: result.manifest_hash,
        source_sha256: result.source_sha256,
        idempotency_key: idempotencyKey,
        confirmation: CONFIRMATION_TOKEN
      });
      setApplyResult(applied);
      setConfirmOpen(false);
      setConfirmText("");
      onApplied?.();
    } catch (error) {
      setErrorMessage(visibleImportError(error, "Personel aktarımı yapılamadı."));
    } finally {
      setIsApplying(false);
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
        hata_kodlari: row.hata_kodlari
          .map((code) => `${code}: ${importErrorMessage(code)}`)
          .join("|")
      }))
    );
  }

  const ozet = result?.ozet;
  const busy = isRunning || isApplying || isDownloadingReferences;

  return (
    <>
      <AppModal
        title="Toplu Personel Hazırlama"
        titleTestId="personel-import-dry-run-title"
        onClose={busy ? undefined : handleClose}
        className="personel-import-dry-run-modal"
        footer={
          <div className="universal-btn-group modal-footer-actions app-action-dialog-actions">
            {applyEnabled ? (
              <button
                type="button"
                className="universal-btn-save"
                data-testid="personel-import-apply-open"
                disabled={busy}
                onClick={() => {
                  setErrorMessage(null);
                  setConfirmText("");
                  setConfirmOpen(true);
                }}
              >
                Personelleri Sisteme Aktar
              </button>
            ) : null}
            <button
              type="button"
              className="universal-btn-aux"
              data-modal-initial-focus="true"
              data-testid="personel-import-dry-run-close"
              disabled={busy}
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
        <p
          className="personel-import-dry-run-info"
          data-testid="personel-import-reference-match-info"
        >
          {REFERENCE_MATCH_MESSAGE}
        </p>
        <p
          className="personel-import-dry-run-info"
          data-testid="personel-import-reference-freshness-info"
        >
          {REFERENCE_FRESHNESS_MESSAGE}
        </p>

        <div className="personel-import-dry-run-actions form-field-grid">
          <button
            type="button"
            className="universal-btn-aux"
            data-testid="personel-import-template-download"
            onClick={() => void handleDownloadTemplate()}
            disabled={busy}
          >
            CSV şablonunu indir
          </button>

          <button
            type="button"
            className="universal-btn-aux"
            data-testid="personel-import-references-download"
            onClick={() => void handleDownloadReferences()}
            disabled={busy}
          >
            {isDownloadingReferences ? "Referanslar indiriliyor..." : "Geçerli Referansları İndir"}
          </button>

          <label className="personel-import-file-label">
            <span>CSV seç</span>
            <input
              ref={fileInputRef}
              type="file"
              accept=".csv,text/csv"
              data-testid="personel-import-file-input"
              disabled={busy}
              onChange={(event) => {
                const file = event.target.files?.[0] ?? null;
                setSelectedFile(file);
                setResult(null);
                setApplyResult(null);
                setIdempotencyKey(null);
                setErrorMessage(null);
              }}
            />
          </label>

          <button
            type="button"
            className="universal-btn-save"
            data-testid="personel-import-dry-run-run"
            disabled={!selectedFile || busy}
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

        {result?.can_apply && !applyResult ? (
          <p
            className="personel-import-ready"
            data-testid="personel-import-ready-banner"
          >
            Personelleri Aktarmaya Hazır
          </p>
        ) : null}

        {applyResult ? (
          <div className="personel-import-apply-success" data-testid="personel-import-apply-success">
            <p>
              Aktarım tamamlandı. Oluşturulan: {applyResult.created_count}
              {applyResult.idempotent_replay ? " (idempotent tekrar)" : ""}
            </p>
            <ul>
              {applyResult.created.map((row) => (
                <li key={`${row.satir_no}-${row.personel_id}`}>
                  #{row.satir_no} — {row.sicil_no} — {row.ad} {row.soyad} — {row.tc_kimlik_no_masked} — ID{" "}
                  {row.personel_id}
                </li>
              ))}
            </ul>
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
                        <td>{row.hata_kodlari.map(importErrorMessage).join(", ")}</td>
                      </tr>
                    ))}
                </tbody>
              </table>
            </div>
          </div>
        ) : null}
      </AppModal>

      <AppActionDialog
        open={confirmOpen}
        title="Personelleri Sisteme Aktar"
        description={APPLY_CONFIRM_MESSAGE}
        confirmLabel={isApplying ? "Aktarılıyor..." : "Onayla ve Aktar"}
        cancelLabel="Vazgeç"
        isSubmitting={isApplying}
        testId="personel-import-apply-dialog"
        errorMessage={errorMessage}
        errorTestId="personel-import-apply-error"
        field={{
          label: "Onay metni",
          value: confirmText,
          onChange: setConfirmText,
          required: true,
          placeholder: CONFIRMATION_TOKEN,
          helpText: `Tam olarak şunu yazın: ${CONFIRMATION_TOKEN}`,
          testId: "personel-import-apply-confirmation"
        }}
        onCancel={() => {
          if (!isApplying) {
            setConfirmOpen(false);
            setConfirmText("");
          }
        }}
        onConfirm={() => void handleApplyConfirm()}
      />
    </>
  );
}
