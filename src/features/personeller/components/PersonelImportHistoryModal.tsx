import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
  downloadPersonelImportEvidenceCsv,
  getPersonelImportRunDetail,
  listPersonelImportRuns,
  type PersonelImportRunDetail,
  type PersonelImportRunSummary
} from "../../../api/personeller.api";
import { ApiRequestError } from "../../../api/api-client";
import { visibleImportError } from "../personel-import-error-messages";
import { AppModal } from "../../../components/modal/AppModal";
import { useAuth } from "../../../state/auth.store";

type PersonelImportHistoryModalProps = {
  open: boolean;
  onClose: () => void;
};

const EMPTY_MESSAGE =
  "Henüz tamamlanmış veya başarısız bir personel import işlemi bulunmuyor.";

function formatDateTime(value: string | null): string {
  if (!value) {
    return "-";
  }
  const normalized = value.replace(" ", "T");
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return date.toLocaleString("tr-TR");
}

function formatDuration(ms: number | null): string {
  if (ms === null || ms === undefined) {
    return "-";
  }
  if (ms < 1000) {
    return `${ms} ms`;
  }
  return `${(ms / 1000).toFixed(1)} sn`;
}

function shortHash(value: string): string {
  if (!value) {
    return "-";
  }
  return value.length <= 12 ? value : `${value.slice(0, 12)}…`;
}

export function PersonelImportHistoryModal({ open, onClose }: PersonelImportHistoryModalProps) {
  const { session } = useAuth();
  const activeSubeId = session?.active_sube_id ?? null;
  const subeler = session?.sube_list ?? [];
  const [items, setItems] = useState<PersonelImportRunSummary[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [schemaNotReady, setSchemaNotReady] = useState(false);

  const [statusFilter, setStatusFilter] = useState("");
  const [subeFilter, setSubeFilter] = useState<string>("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const [detail, setDetail] = useState<PersonelImportRunDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [evidenceError, setEvidenceError] = useState<string | null>(null);

  const loadList = useCallback(
    async (mode: "replace" | "append", cursor: string | null) => {
      if (mode === "replace") {
        setIsLoading(true);
      } else {
        setIsLoadingMore(true);
      }
      setErrorMessage(null);
      setSchemaNotReady(false);
      try {
        const result = await listPersonelImportRuns({
          cursor,
          limit: 25,
          status: statusFilter || undefined,
          sube_id: subeFilter ? Number(subeFilter) : undefined,
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined
        });
        setItems((prev) => (mode === "replace" ? result.items : [...prev, ...result.items]));
        setNextCursor(result.next_cursor);
      } catch (error) {
        const code = error instanceof ApiRequestError ? error.code ?? null : null;
        if (code === "SCHEMA_NOT_READY") {
          setSchemaNotReady(true);
          setItems([]);
          setNextCursor(null);
          setErrorMessage("Personel import şeması henüz hazır değil.");
        } else {
          setErrorMessage(visibleImportError(error, "Personel import geçmişi yüklenemedi."));
        }
      } finally {
        setIsLoading(false);
        setIsLoadingMore(false);
      }
    },
    [statusFilter, subeFilter, dateFrom, dateTo]
  );

  useEffect(() => {
    if (!open) {
      return;
    }
    setDetail(null);
    setDetailError(null);
    setEvidenceError(null);
    void loadList("replace", null);
  }, [open, loadList]);

  useEffect(() => {
    if (!open) {
      setItems([]);
      setNextCursor(null);
      setDetail(null);
      setErrorMessage(null);
      setSchemaNotReady(false);
    }
  }, [open]);

  async function openDetail(importId: number) {
    setDetailLoading(true);
    setDetailError(null);
    setEvidenceError(null);
    try {
      const result = await getPersonelImportRunDetail(importId);
      setDetail(result);
    } catch (error) {
      setDetail(null);
      setDetailError(visibleImportError(error, "Aktarım detayı yüklenemedi."));
    } finally {
      setDetailLoading(false);
    }
  }

  async function handleEvidenceDownload() {
    if (!detail) {
      return;
    }
    setEvidenceError(null);
    try {
      await downloadPersonelImportEvidenceCsv(detail.import_id);
    } catch (error) {
      setEvidenceError(visibleImportError(error, "Kanıt CSV indirilemedi."));
    }
  }

  if (!open) {
    return null;
  }

  if (detail || detailLoading || detailError) {
    return (
      <AppModal
        title="Import Detayı"
        titleTestId="personel-import-history-detail-title"
        onClose={() => {
          setDetail(null);
          setDetailError(null);
          setEvidenceError(null);
        }}
        className="personel-import-history-modal"
        footer={
          <div className="universal-btn-group modal-footer-actions app-action-dialog-actions">
            {detail ? (
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="personel-import-history-evidence"
                onClick={() => void handleEvidenceDownload()}
              >
                Kanıt CSV indir
              </button>
            ) : null}
            <button
              type="button"
              className="universal-btn-aux"
              data-modal-initial-focus="true"
              data-testid="personel-import-history-detail-back"
              onClick={() => {
                setDetail(null);
                setDetailError(null);
                setEvidenceError(null);
              }}
            >
              Listeye dön
            </button>
          </div>
        }
      >
        {detailLoading ? (
          <p className="workspace-empty-hint" data-testid="personel-import-history-detail-loading">
            Detay yükleniyor...
          </p>
        ) : null}
        {detailError ? (
          <p className="workspace-error" role="alert" data-testid="personel-import-history-detail-error">
            {detailError}
          </p>
        ) : null}
        {evidenceError ? (
          <p className="workspace-error" role="alert">
            {evidenceError}
          </p>
        ) : null}
        {detail ? (
          <div data-testid="personel-import-history-detail">
            <div className="personel-import-history-summary">
              <p>
                <strong>Durum:</strong> {detail.status_label} ({detail.status})
              </p>
              <p>
                <strong>Hazırlayan:</strong> {detail.actor_display_name}
              </p>
              <p>
                <strong>Kapsam:</strong> {detail.scope_summary}
              </p>
              <p>
                <strong>Başlangıç:</strong> {formatDateTime(detail.created_at)}
              </p>
              <p>
                <strong>Tamamlanma:</strong> {formatDateTime(detail.completed_at)}
              </p>
              <p>
                <strong>Başarısızlık:</strong> {formatDateTime(detail.failed_at)}
              </p>
              <p>
                <strong>Süre:</strong> {formatDuration(detail.duration_ms)}
              </p>
              <p>
                <strong>Kaynak SHA:</strong> {detail.source_sha256}
              </p>
              <p>
                <strong>Manifest:</strong> {detail.manifest_hash}
              </p>
              <p>
                <strong>Idempotency:</strong> {detail.idempotency_fingerprint}
              </p>
              <p>
                <strong>Satırlar:</strong> {detail.row_count} / geçerli {detail.valid_row_count} /
                oluşturulan {detail.created_count} / başarısız {detail.failed_row_count}
              </p>
              {detail.failure_code ? (
                <p>
                  <strong>Hata:</strong> {detail.failure_code}
                  {detail.failure_message ? ` — ${detail.failure_message}` : ""}
                </p>
              ) : null}
            </div>

            <div className="personel-import-history-table-wrap">
              <table className="personel-import-history-table" data-testid="personel-import-history-rows">
                <thead>
                  <tr>
                    <th>Satır</th>
                    <th>Sicil</th>
                    <th>Ad Soyad</th>
                    <th>T.C. (maskeli)</th>
                    <th>Durum</th>
                    <th>Personel</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.satirlar.length === 0 ? (
                    <tr>
                      <td colSpan={6}>Satır kaydı yok.</td>
                    </tr>
                  ) : (
                    detail.satirlar.map((row) => (
                      <tr key={`${row.row_number}-${row.sicil_no}`}>
                        <td>{row.row_number}</td>
                        <td>{row.sicil_no || "-"}</td>
                        <td>{row.ad_soyad || row.personel_display_name || "-"}</td>
                        <td>{row.tc_kimlik_no_masked}</td>
                        <td>{row.row_status}</td>
                        <td>
                          {row.personel_id && row.personel_detail_path ? (
                            <Link to={row.personel_detail_path}>#{row.personel_id}</Link>
                          ) : (
                            "-"
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        ) : null}
      </AppModal>
    );
  }

  return (
    <AppModal
      title="Personel Import Geçmişi"
      titleTestId="personel-import-history-title"
      onClose={onClose}
      className="personel-import-history-modal"
      footer={
        <div className="universal-btn-group modal-footer-actions app-action-dialog-actions">
          <button
            type="button"
            className="universal-btn-aux"
            data-modal-initial-focus="true"
            data-testid="personel-import-history-close"
            onClick={onClose}
          >
            Kapat
          </button>
        </div>
      }
    >
      <div className="personel-import-history-filters form-field-grid" data-testid="personel-import-history-filters">
        <label>
          <span>Durum</span>
          <select
            value={statusFilter}
            data-testid="personel-import-history-filter-status"
            onChange={(event) => setStatusFilter(event.target.value)}
          >
            <option value="">Tümü</option>
            <option value="COMPLETED">Tamamlandı</option>
            <option value="BASARISIZ">Başarısız</option>
            <option value="CLAIMED">İşlemde</option>
          </select>
        </label>
        <label>
          <span>Şube</span>
          <select
            value={subeFilter}
            data-testid="personel-import-history-filter-sube"
            onChange={(event) => setSubeFilter(event.target.value)}
          >
            <option value="">
              {activeSubeId ? `Aktif şube (#${activeSubeId})` : "Tümü"}
            </option>
            {subeler.map((sube) => (
              <option key={sube.id} value={String(sube.id)}>
                {sube.ad}
              </option>
            ))}
          </select>
        </label>
        <label>
          <span>Başlangıç</span>
          <input
            type="date"
            value={dateFrom}
            data-testid="personel-import-history-filter-from"
            onChange={(event) => setDateFrom(event.target.value)}
          />
        </label>
        <label>
          <span>Bitiş</span>
          <input
            type="date"
            value={dateTo}
            data-testid="personel-import-history-filter-to"
            onChange={(event) => setDateTo(event.target.value)}
          />
        </label>
        <button
          type="button"
          className="universal-btn-save"
          data-testid="personel-import-history-filter-apply"
          onClick={() => void loadList("replace", null)}
          disabled={isLoading}
        >
          Filtrele
        </button>
      </div>

      {isLoading ? (
        <p className="workspace-empty-hint" data-testid="personel-import-history-loading">
          Import geçmişi yükleniyor...
        </p>
      ) : null}

      {errorMessage ? (
        <div data-testid="personel-import-history-error">
          <p className="workspace-error" role="alert">
            {errorMessage}
          </p>
          {!schemaNotReady ? (
            <button
              type="button"
              className="universal-btn-aux"
              data-testid="personel-import-history-retry"
              onClick={() => void loadList("replace", null)}
            >
              Yeniden dene
            </button>
          ) : null}
        </div>
      ) : null}

      {!isLoading && !errorMessage && items.length === 0 ? (
        <p className="workspace-empty-hint" data-testid="personel-import-history-empty">
          {EMPTY_MESSAGE}
        </p>
      ) : null}

      {!isLoading && !errorMessage && items.length > 0 ? (
        <div className="personel-import-history-table-wrap" data-testid="personel-import-history-list">
          <table className="personel-import-history-table">
            <thead>
              <tr>
                <th>Tarih/saat</th>
                <th>Durum</th>
                <th>Şube/kapsam</th>
                <th>Hazırlayan</th>
                <th>Toplam</th>
                <th>Oluşturulan</th>
                <th>Süre</th>
                <th>Manifest</th>
                <th>Detay</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.import_id} data-testid={`personel-import-history-row-${item.import_id}`}>
                  <td>{formatDateTime(item.created_at)}</td>
                  <td>{item.status_label}</td>
                  <td>{item.scope_summary}</td>
                  <td>{item.actor_display_name}</td>
                  <td>{item.row_count}</td>
                  <td>{item.created_count}</td>
                  <td>{formatDuration(item.duration_ms)}</td>
                  <td title={item.manifest_hash}>{shortHash(item.manifest_hash)}</td>
                  <td>
                    <button
                      type="button"
                      className="universal-btn-aux"
                      data-testid={`personel-import-history-open-${item.import_id}`}
                      onClick={() => void openDetail(item.import_id)}
                    >
                      Detay
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {nextCursor ? (
            <button
              type="button"
              className="universal-btn-aux"
              data-testid="personel-import-history-load-more"
              disabled={isLoadingMore}
              onClick={() => void loadList("append", nextCursor)}
            >
              {isLoadingMore ? "Yükleniyor..." : "Daha fazla"}
            </button>
          ) : null}
        </div>
      ) : null}
    </AppModal>
  );
}
