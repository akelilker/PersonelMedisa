import { useMemo, useRef, useState } from "react";
import { AppModal } from "../../../components/modal/AppModal";
import { AppActionDialog } from "../../../components/modal/AppActionDialog";
import {
  applyPersonelImport,
  downloadPersonelImportReferencesCsv,
  downloadPersonelImportTemplateCsv,
  dryRunPersonelImport,
  type PersonelImportApplyResult,
  type PersonelImportDryRunResult,
  type PersonelImportDryRunRow
} from "../../../api/personeller.api";
import { downloadReportCsv } from "../../../reports/export-report";
import { formatPersonelImportSatirDurumLabel } from "../../../lib/display/enum-display";
import {
  importErrorMessage,
  visibleImportError
} from "../personel-import-error-messages";

type PersonelImportDryRunModalProps = {
  open: boolean;
  onClose: () => void;
  /** Full flow home (close import + parent kayıt). Defaults to onClose. */
  onHome?: () => void;
  canApply?: boolean;
  onApplied?: () => void;
  onOpenImportHistory?: () => void;
};

const APPLY_CONFIRM_MESSAGE =
  "Bu işlem yalnız personel ana kayıtlarını oluşturur. Ücret, bordro kapsamı ve SGK statüsü oluşturmaz.";

const CONFIRMATION_TOKEN = "PERSONEL_IMPORT_ONAYLIYORUM";


function createIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return `pir-${crypto.randomUUID()}`;
  }
  return `pir-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

type ParsedIssue = {
  kind: "error" | "auto" | "ambiguous";
  field: string;
  input: string;
  message: string;
  candidates: string[];
  canonical?: string;
  code?: string;
};

function parseAutoWarning(text: string): ParsedIssue | null {
  // Görev 'x' → 'Y' olarak eşleştirildi.
  const m = text.match(/^(.+?)\s+'([^']+)'\s+→\s+'([^']+)'\s+olarak eşleştirildi\.?$/);
  if (!m) return null;
  return {
    kind: "auto",
    field: m[1],
    input: m[2],
    canonical: m[3],
    message: text,
    candidates: []
  };
}

function parseAmbiguousWarning(text: string): ParsedIssue | null {
  if (!text.startsWith("BELIRSIZ|")) return null;
  const parts = text.split("|");
  const field = parts[1] || "Alan";
  return {
    kind: "ambiguous",
    field,
    input: parts[2] || "",
    candidates: (parts[3] || "").split(" ;; ").map((s) => s.trim()).filter(Boolean),
    message: field === "Şube"
      ? "Birden fazla şube ile eşleşiyor."
      : "Birden fazla kayıt ile eşleşiyor.",
    code: "PERSONEL_IMPORT_REFERANS_BELIRSIZ"
  };
}

function parseUnknownWarning(text: string): ParsedIssue | null {
  if (!text.startsWith("BULUNAMADI|")) return null;
  const parts = text.split("|");
  const field = parts[1] || "Alan";
  const input = parts[2] || "";
  const fieldLower = field.toLocaleLowerCase("tr-TR");
  const noun =
    fieldLower.includes("görev") ? "görev" :
    fieldLower.includes("şube") ? "şube" :
    fieldLower.includes("departman") ? "departman" :
    fieldLower.includes("bölüm") ? "bölüm" :
    fieldLower.includes("birim") ? "birim" :
    fieldLower.includes("pozisyon") ? "pozisyon" :
    "değer";
  return {
    kind: "error",
    field,
    input,
    candidates: [],
    message: `Bu ${noun} sistemde bulunamadı.`,
    code: "PERSONEL_IMPORT_REFERANS_BULUNAMADI"
  };
}

function issuesForRow(row: PersonelImportDryRunRow): ParsedIssue[] {
  const out: ParsedIssue[] = [];
  for (const u of row.uyarilar) {
    const amb = parseAmbiguousWarning(u);
    if (amb) {
      out.push(amb);
      continue;
    }
    const unk = parseUnknownWarning(u);
    if (unk) {
      out.push(unk);
      continue;
    }
    const auto = parseAutoWarning(u);
    if (auto) {
      out.push(auto);
      continue;
    }
  }
  for (const code of row.hata_kodlari) {
    if (code === "PERSONEL_IMPORT_REFERANS_BELIRSIZ" && out.some((i) => i.kind === "ambiguous")) {
      continue;
    }
    if (code === "PERSONEL_IMPORT_REFERANS_BULUNAMADI" && out.some((i) => i.code === "PERSONEL_IMPORT_REFERANS_BULUNAMADI")) {
      continue;
    }
    out.push({
      kind: "error",
      field: "—",
      input: "—",
      message:
        code === "PERSONEL_IMPORT_REFERANS_BULUNAMADI"
          ? "Bu değer sistemde bulunamadı."
          : code === "PERSONEL_IMPORT_REFERANS_BELIRSIZ"
            ? "Birden fazla kayıt ile eşleşiyor."
            : importErrorMessage(code),
      candidates: [],
      code
    });
  }
  return out;
}

function resultStateFromOzet(ozet: PersonelImportDryRunResult["ozet"] | undefined | null): {
  hardErrorCount: number;
  warningCount: number;
} {
  if (!ozet) {
    return { hardErrorCount: 0, warningCount: 0 };
  }
  const hardErrorCount = Math.max(0, Number(ozet.hatali_satir) || 0);
  const warningCount = Math.max(0, Number(ozet.warning_sayisi) || 0);
  return { hardErrorCount, warningCount };
}

export function PersonelImportDryRunModal({
  open,
  onClose,
  onHome,
  canApply = false,
  onApplied,
  onOpenImportHistory
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
      setErrorMessage(visibleImportError(error, "Yükleme kılavuzu indirilemedi."));
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
      setErrorMessage(visibleImportError(error, "Dosya kontrolü tamamlanamadı."));
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
    const rows: Array<Record<string, string>> = [];
    for (const row of result.satirlar) {
      const issues = issuesForRow(row);
      if (issues.length === 0 && row.hata_kodlari.length === 0) continue;
      const list = issues.length > 0 ? issues : row.hata_kodlari.map((code) => ({
        kind: "error" as const,
        field: "—",
        input: "—",
        message: importErrorMessage(code),
        candidates: [] as string[],
        code
      }));
      for (const issue of list) {
        const adSoyad = [row.ad, row.soyad].filter(Boolean).join(" ").trim();
        rows.push({
          Satir: String(row.satir_no),
          "Ad Soyad": adSoyad || "—",
          Alan: issue.field,
          "Girilen Değer": issue.input,
          Sorun:
            issue.kind === "auto"
              ? `Otomatik eşleşme: ${issue.canonical ?? ""}`
              : issue.message,
          "Önerilen Düzeltme":
            issue.kind === "ambiguous"
              ? `Kullanılabilecek değerler: ${issue.candidates.join("; ")}`
              : issue.kind === "auto"
                ? "Düzeltme gerekmez; kontrol edin."
                : issue.code === "PERSONEL_IMPORT_REFERANS_BULUNAMADI"
                  ? "Yükleme Kılavuzundaki adlardan birini kullanın."
                  : "",
          teknik_kod: issue.code ?? ""
        });
      }
    }
    downloadReportCsv(
      "personel-import-kontrol-hatalar.csv",
      ["Satır", "Ad Soyad", "Alan", "Girilen Değer", "Sorun", "Önerilen Düzeltme", "teknik_kod"],
      rows
    );
  }

  const ozet = result?.ozet;
  const busy = isRunning || isApplying || isDownloadingReferences;
  const footer =
    applyEnabled ? (
      <div className="universal-btn-group modal-footer-actions app-action-dialog-actions">
        <button
          type="button"
          className="universal-btn-save"
          data-testid="personel-import-apply-open"
          data-modal-initial-focus="true"
          disabled={busy}
          onClick={() => {
            setErrorMessage(null);
            setConfirmText("");
            setConfirmOpen(true);
          }}
        >
          Personelleri Sisteme Aktar
        </button>
      </div>
    ) : undefined;

  return (
    <>
      <AppModal
        title="Toplu Kayıt Aktarma"
        titleTestId="personel-import-dry-run-title"
        titleVariant="premium"
        onClose={busy ? undefined : handleClose}
        className="personel-import-dry-run-modal"
        headerStart={
          <button
            type="button"
            className="modal-home-btn"
            onClick={busy ? undefined : () => { resetState(); (onHome ?? onClose)(); }}
            disabled={busy}
            aria-label="Ana sayfaya dön"
            data-testid="personel-import-home"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path
                d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinejoin="round"
              />
            </svg>
          </button>
        }
        footer={footer}
      >
        <div className="personel-import-layout">
        <div className="universal-back-bar personel-import-back-bar">
          <button
            type="button"
            className="universal-back-btn"
            data-testid="personel-import-back-kayit"
            disabled={busy}
            onClick={handleClose}
            aria-label="Kayıt"
          >
            <svg
              className="back-icon-svg"
              xmlns="http://www.w3.org/2000/svg"
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M19 12H5" />
              <path d="m12 19-7-7 7-7" />
            </svg>
            <span className="universal-back-label">Kayıt</span>
          </button>
        </div>

        <div className={ozet ? "personel-import-stage personel-import-stage--has-result" : "personel-import-stage"}>
        <div className="personel-import-dry-run-actions">
          <div className="personel-import-action-row">
            <button
              type="button"
              className="universal-btn-aux"
              data-testid="personel-import-template-download"
              onClick={() => void handleDownloadTemplate()}
              disabled={busy}
            >
              Şablon Dosyası İndir
            </button>
            <button
              type="button"
              className="universal-btn-aux"
              data-testid="personel-import-references-download"
              onClick={() => void handleDownloadReferences()}
              disabled={busy}
            >
              {isDownloadingReferences ? "Kılavuz indiriliyor..." : "Yükleme Kılavuzunu İndir"}
            </button>
            {onOpenImportHistory ? (
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="personeller-import-history-open"
                onClick={onOpenImportHistory}
                disabled={busy}
              >
                Import Geçmişi
              </button>
            ) : null}
          </div>
          <div className="personel-import-action-row">
            <div className="personel-import-file-picker">
              <input
                ref={fileInputRef}
                type="file"
                accept=".csv,text/csv"
                data-testid="personel-import-file-input"
                className="personel-import-file-input-hidden"
                tabIndex={-1}
                aria-hidden="true"
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
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="personel-import-file-pick"
                disabled={busy}
                onClick={() => fileInputRef.current?.click()}
              >
                Dosya Seç
              </button>
            </div>
            <button
              type="button"
              className="universal-btn-save"
              data-testid="personel-import-dry-run-run"
              disabled={!selectedFile || busy}
              onClick={() => void handleDryRun()}
            >
              {isRunning ? "Kontrol ediliyor..." : "Dosyayı Kontrol Et"}
            </button>
          </div>
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
              <span>Toplam Satır</span>
              <strong>{ozet.toplam_satir}</strong>
            </div>
            <div className="personel-import-summary-card personel-import-summary-card--ok">
              <span>Geçerli</span>
              <strong>{ozet.gecerli_satir}</strong>
            </div>
            <div className="personel-import-summary-card personel-import-summary-card--warn">
              <span>Otomatik Eşleştirilen</span>
              <strong>
                {result
                  ? result.satirlar.reduce(
                      (n, row) => n + issuesForRow(row).filter((i) => i.kind === "auto").length,
                      0
                    )
                  : 0}
              </strong>
            </div>
            <div className="personel-import-summary-card personel-import-summary-card--danger">
              <span>Hatalı</span>
              <strong>{ozet.hatali_satir}</strong>
            </div>
            <div className="personel-import-summary-card">
              <span>Yeni Kayıt Adayı</span>
              <strong>{ozet.kayit_olusturulacak_aday}</strong>
            </div>
            <div className="personel-import-summary-card">
              <span>Mevcut Kayıt</span>
              <strong>{ozet.veritabaninda_mevcut}</strong>
            </div>
          </div>
        ) : null}

        {ozet
          ? (() => {
              const { hardErrorCount, warningCount } = resultStateFromOzet(ozet);
              if (hardErrorCount > 0) {
                const msg =
                  hardErrorCount === 1
                    ? "1 hatalı kayıt bulundu. Aktarım için hataları düzeltin."
                    : `${hardErrorCount} hata bulundu. Aktarım için hataları düzeltin.`;
                return (
                  <p
                    className="personel-import-blocking-error"
                    data-testid="personel-import-dry-run-blocking"
                    role="alert"
                  >
                    {msg}
                  </p>
                );
              }
              if (warningCount > 0) {
                return (
                  <p className="personel-import-warning" data-testid="personel-import-dry-run-warnings">
                    {warningCount} uyarı bulundu. Uyarılar aktarımı engellemez; satır detaylarını kontrol edin.
                  </p>
                );
              }
              return null;
            })()
          : null}

        {result?.can_apply && !applyResult ? (
          <p className="personel-import-ready" data-testid="personel-import-ready-banner">
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
                  #{row.satir_no} — {row.sicil_no} — {row.ad} {row.soyad} — {row.tc_kimlik_no_masked}
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        {result
          ? (() => {
              const detailRows = result.satirlar
                .map((row) => ({ row, issues: issuesForRow(row) }))
                .filter((x) => x.issues.length > 0);
              if (detailRows.length === 0) return null;
              return (
                <div className="personel-import-errors" data-testid="personel-import-dry-run-errors">
                  <div className="personel-import-errors-header">
                    <h3>Kontrol sonuçları</h3>
                    {ozet && resultStateFromOzet(ozet).hardErrorCount > 0 ? (
                      <button
                        type="button"
                        className="universal-btn-aux"
                        data-testid="personel-import-errors-download"
                        onClick={handleDownloadErrorsCsv}
                      >
                        Hata Dosyasını İndir
                      </button>
                    ) : ozet && resultStateFromOzet(ozet).warningCount > 0 ? (
                      <button
                        type="button"
                        className="universal-btn-aux"
                        data-testid="personel-import-errors-download"
                        onClick={handleDownloadErrorsCsv}
                      >
                        Kontrol Raporunu İndir
                      </button>
                    ) : null}
                  </div>
                  <div className="personel-import-issue-list">
                    {detailRows.map(({ row, issues }) =>
                      issues.map((issue, idx) => (
                        <article
                          key={`${row.satir_no}-${idx}-${issue.kind}`}
                          className={
                            issue.kind === "auto"
                              ? "personel-import-issue personel-import-issue--auto"
                              : issue.kind === "ambiguous"
                                ? "personel-import-issue personel-import-issue--ambiguous"
                                : "personel-import-issue personel-import-issue--error"
                          }
                          data-testid={
                            issue.kind === "auto"
                              ? "personel-import-issue-auto"
                              : issue.kind === "ambiguous"
                                ? "personel-import-issue-ambiguous"
                                : "personel-import-issue-error"
                          }
                        >
                          <h4>SATIR {row.satir_no}</h4>
                          <p>
                            <strong>Ad Soyad:</strong>{" "}
                            {[row.ad, row.soyad].filter(Boolean).join(" ").trim() || "—"}
                          </p>
                          <p>
                            <strong>Alan:</strong> {issue.field}
                          </p>
                          <p>
                            <strong>Girilen:</strong> {issue.input}
                          </p>
                          {issue.kind === "auto" ? (
                            <p>
                              “{issue.input}” → “{issue.canonical}” olarak eşleştirildi.
                            </p>
                          ) : (
                            <p>
                              <strong>Durum:</strong> {issue.message}
                            </p>
                          )}
                          {issue.kind === "ambiguous" && issue.candidates.length > 0 ? (
                            <div>
                              <strong>Kullanılabilecek değerler:</strong>
                              <ul>
                                {issue.candidates.map((c) => (
                                  <li key={c}>{c}</li>
                                ))}
                              </ul>
                            </div>
                          ) : null}
                          {issue.kind === "error" &&
                          issue.code === "PERSONEL_IMPORT_REFERANS_BULUNAMADI" ? (
                            <p>
                              <strong>Düzeltme:</strong> Yükleme Kılavuzundaki {issue.field === "Görev" ? "görev adlarından" : "adlardan"} birini kullanın.
                            </p>
                          ) : null}
                          <p className="personel-import-issue-meta">
                            Sicil: {row.sicil_no || "—"} · T.C.: {row.tc_kimlik_no_masked} · Durum:{" "}
                            {formatPersonelImportSatirDurumLabel(row.durum)}
                          </p>
                        </article>
                      ))
                    )}
                  </div>
                </div>
              );
            })()
          : null}
        </div>
      </div>
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
