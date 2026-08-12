import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
  fetchQrPuantajAdaylari,
  postQrPuantajAdayKarar,
  type QrPuantajCandidateItem
} from "../../../api/puantaj.api";
import { AppModal } from "../../../components/modal/AppModal";
import { useRoleAccess } from "../../../hooks/use-role-access";

type Props = {
  personelId: number;
  tarih: string;
  personelLabel?: string;
  onApplied?: () => void;
};

function formatSeconds(seconds: number | undefined): string {
  if (seconds == null || Number.isNaN(seconds)) {
    return "—";
  }
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  return `${h}s ${m}dk`;
}

function newNonce(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return "00000000-0000-4000-8000-000000000000";
}

function anomalyHints(item: QrPuantajCandidateItem): string[] {
  const classification = item.classification ?? "";
  const hints: string[] = [];
  if (classification === "REVIEW_ANOMALY") {
    hints.push("Giriş eksik veya çıkış eksik veya şube uyuşmazlığı olabilir");
  }
  if (classification === "REVIEW_MULTIPLE_INTERVALS") {
    hints.push("Birden fazla interval");
  }
  if (classification === "REVIEW_CROSS_MIDNIGHT") {
    hints.push("Gece yarısını aşan interval");
  }
  if (classification === "REVIEW_MULTIPLE_BRANCHES") {
    hints.push("Şube uyuşmazlığı / birden fazla şube");
  }
  return hints;
}

export function QrPuantajAdayiSection({ personelId, tarih, personelLabel, onApplied }: Props) {
  const { hasPermission } = useRoleAccess();
  const canView = hasPermission("puantaj.view");
  const canDecide = hasPermission("puantaj.update");

  const [item, setItem] = useState<QrPuantajCandidateItem | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [applyOpen, setApplyOpen] = useState(false);
  const [keepOpen, setKeepOpen] = useState(false);
  const [reopenOpen, setReopenOpen] = useState(false);
  const [reason, setReason] = useState("");

  const load = useCallback(async () => {
    if (!canView || personelId < 1 || !tarih) {
      setItem(null);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const data = await fetchQrPuantajAdaylari(personelId, { from: tarih, to: tarih });
      const found = (data.items ?? []).find((row) => row.candidate_date === tarih) ?? null;
      setItem(found);
    } catch (e) {
      setError(e instanceof Error ? e.message : "QR puantaj adayı yüklenemedi.");
      setItem(null);
    } finally {
      setLoading(false);
    }
  }, [canView, personelId, tarih]);

  useEffect(() => {
    void load();
  }, [load]);

  if (!canView || personelId < 1 || !tarih) {
    return null;
  }

  const review = item?.review;
  const canApply = Boolean(canDecide && review?.can_apply);
  const canKeep = Boolean(canDecide && review?.can_keep_canonical);
  const canReopen = Boolean(canDecide && review?.can_reopen_review);
  const noRow = item?.comparison_status === "NO_CANONICAL_ROW";
  const revisionRequired =
    item?.comparison_status === "PERIOD_REQUIRES_REVISION" || review?.state === "REVISION_REQUIRED";
  const dependentManualReview =
    review?.blocking_code === "QR_APPLY_DEPENDENT_FIELDS_REQUIRE_MANUAL_REVIEW";
  const anomaly = anomalyHints(item ?? { candidate_date: tarih });

  async function runDecision(action: "APPLY_EXISTING" | "KEEP_CANONICAL" | "REOPEN_REVIEW") {
    if (!item?.candidate_hash) {
      return;
    }
    if (action !== "APPLY_EXISTING" && reason.trim().length < 5) {
      setActionError("Gerekçe en az 5 karakter olmalıdır.");
      return;
    }
    if (action === "APPLY_EXISTING" && reason.trim().length < 5) {
      setReason("QR giriş/çıkış saatlerinin mevcut puantaja uygulanması onaylandı.");
    }
    const gerekce =
      action === "APPLY_EXISTING" && reason.trim().length < 5
        ? "QR giriş/çıkış saatlerinin mevcut puantaja uygulanması onaylandı."
        : reason.trim();

    setBusy(true);
    setActionError(null);
    try {
      await postQrPuantajAdayKarar(personelId, tarih, {
        action,
        candidate_hash: item.candidate_hash,
        request_nonce: newNonce(),
        gerekce
      });
      setApplyOpen(false);
      setKeepOpen(false);
      setReopenOpen(false);
      setReason("");
      await load();
      if (action === "APPLY_EXISTING") {
        onApplied?.();
      }
    } catch (e) {
      setActionError(e instanceof Error ? e.message : "Karar kaydedilemedi.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="puantaj-detail-card" data-testid="qr-puantaj-aday-section">
      <h3>QR Puantaj Adayı</h3>
      {loading ? <p className="puantaj-form-readonly">Yükleniyor...</p> : null}
      {error ? <p className="yonetim-error">{error}</p> : null}
      {!loading && !error && !item ? (
        <p className="puantaj-form-readonly">Bu tarih için QR kanıt adayı yok.</p>
      ) : null}

      {item ? (
        <>
          <div className="form-field-grid">
            <div>
              <div className="form-label">Tarih</div>
              <div className="form-input puantaj-readonly-value">{item.candidate_date}</div>
            </div>
            <div>
              <div className="form-label">QR giriş</div>
              <div className="form-input puantaj-readonly-value">{item.proposed?.giris_saati ?? "—"}</div>
            </div>
            <div>
              <div className="form-label">QR çıkış</div>
              <div className="form-input puantaj-readonly-value">{item.proposed?.cikis_saati ?? "—"}</div>
            </div>
            <div>
              <div className="form-label">QR eşleşme süresi</div>
              <div className="form-input puantaj-readonly-value">
                {formatSeconds(item.qr?.matched_seconds ?? (item.provenance?.qr_matched_seconds as number | undefined))}
              </div>
            </div>
            <div>
              <div className="form-label">Puantaj giriş</div>
              <div className="form-input puantaj-readonly-value">{item.canonical?.giris_saati ?? "—"}</div>
            </div>
            <div>
              <div className="form-label">Puantaj çıkış</div>
              <div className="form-input puantaj-readonly-value">{item.canonical?.cikis_saati ?? "—"}</div>
            </div>
            <div>
              <div className="form-label">Sınıflandırma</div>
              <div className="form-input puantaj-readonly-value">{item.classification ?? "—"}</div>
            </div>
            <div>
              <div className="form-label">Karşılaştırma</div>
              <div className="form-input puantaj-readonly-value">{item.comparison_status ?? "—"}</div>
            </div>
            <div>
              <div className="form-label">Dönem</div>
              <div className="form-input puantaj-readonly-value">{item.period?.state ?? "—"}</div>
            </div>
            <div>
              <div className="form-label">İnceleme</div>
              <div className="form-input puantaj-readonly-value" data-testid="qr-puantaj-aday-review-state">
                {review?.state ?? "—"}
              </div>
            </div>
          </div>

          {review?.state === "CANONICAL_KEPT" ? (
            <p className="puantaj-form-readonly" data-testid="qr-puantaj-aday-kept-note">
              Mevcut puantaj korundu
            </p>
          ) : null}

          {noRow ? (
            <p className="puantaj-form-readonly" data-testid="qr-puantaj-aday-no-row">
              Puantaj kaydı bulunmuyor. QR kanıtı tek başına yeni puantaj oluşturamaz.
            </p>
          ) : null}

          {revisionRequired ? (
            <p className="puantaj-form-readonly" data-testid="qr-puantaj-aday-revision-required">
              Revizyon gerekli
              {hasPermission("revizyon.view") ? (
                <>
                  {" · "}
                  <Link to="/haftalik-kapanis/revizyonlar/yeni">Revizyon merkezine git</Link>
                </>
              ) : null}
            </p>
          ) : null}

          {dependentManualReview ? (
            <p className="puantaj-form-readonly" data-testid="qr-puantaj-aday-dependent-review">
              Bağımlı türetilmiş alanlar dolu. QR saatleri doğrudan uygulanamaz; manuel inceleme gerekir.
            </p>
          ) : null}

          {anomaly.length > 0 ? (
            <ul className="puantaj-alert-list" data-testid="qr-puantaj-aday-anomaly">
              {anomaly.map((hint) => (
                <li key={hint}>{hint}</li>
              ))}
            </ul>
          ) : null}

          {actionError ? <p className="yonetim-error">{actionError}</p> : null}

          <div className="form-actions-row">
            {canApply ? (
              <button
                type="button"
                className="universal-btn"
                data-testid="qr-puantaj-aday-apply"
                disabled={busy}
                onClick={() => {
                  setReason("QR giriş/çıkış saatlerinin mevcut puantaja uygulanması onaylandı.");
                  setApplyOpen(true);
                }}
              >
                QR Saatlerini Uygula
              </button>
            ) : null}
            {canKeep ? (
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="qr-puantaj-aday-keep"
                disabled={busy}
                onClick={() => {
                  setReason("");
                  setKeepOpen(true);
                }}
              >
                Mevcut Puantajı Koru
              </button>
            ) : null}
            {canReopen ? (
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="qr-puantaj-aday-reopen"
                disabled={busy}
                onClick={() => {
                  setReason("");
                  setReopenOpen(true);
                }}
              >
                İncelemeyi Yeniden Aç
              </button>
            ) : null}
          </div>
        </>
      ) : null}

      {applyOpen && item ? (
        <AppModal title="QR Saatlerini Uygula" onClose={() => { if (!busy) setApplyOpen(false); }}>
          <div data-testid="qr-puantaj-aday-apply-modal">
            <p>
              Yalnızca giriş/çıkış saatleri değişecek. Diğer puantaj alanları (gün tipi, hareket, dayanak, hesap
              etkisi, geç/erken vb.) değişmez.
            </p>
            <dl className="puantaj-etki-detail-grid">
              <div className="puantaj-etki-detail-row">
                <dt>Personel</dt>
                <dd>{personelLabel ?? personelId}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Tarih</dt>
                <dd>{tarih}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Mevcut</dt>
                <dd>
                  {item.canonical?.giris_saati ?? "—"} / {item.canonical?.cikis_saati ?? "—"}
                </dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>QR</dt>
                <dd>
                  {item.proposed?.giris_saati ?? "—"} / {item.proposed?.cikis_saati ?? "—"}
                </dd>
              </div>
            </dl>
            <label className="form-label" htmlFor="qr-apply-reason">
              Gerekçe
            </label>
            <textarea
              id="qr-apply-reason"
              className="form-input"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
            />
            <div className="form-actions-row">
              <button
                type="button"
                className="universal-btn"
                disabled={busy}
                onClick={() => void runDecision("APPLY_EXISTING")}
              >
                {busy ? "Uygulanıyor..." : "Onayla ve Uygula"}
              </button>
              <button type="button" className="universal-btn-aux" disabled={busy} onClick={() => setApplyOpen(false)}>
                Vazgeç
              </button>
            </div>
          </div>
        </AppModal>
      ) : null}

      {keepOpen ? (
        <AppModal title="Mevcut Puantajı Koru" onClose={() => { if (!busy) setKeepOpen(false); }}>
          <div data-testid="qr-puantaj-aday-keep-modal">
            <p>QR kanıtı incelendi; mevcut puantaj saatleri korunacak. Puantaj yazılmaz.</p>
            <label className="form-label" htmlFor="qr-keep-reason">
              Gerekçe
            </label>
            <textarea
              id="qr-keep-reason"
              className="form-input"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
            />
            <div className="form-actions-row">
              <button
                type="button"
                className="universal-btn"
                disabled={busy}
                onClick={() => void runDecision("KEEP_CANONICAL")}
              >
                {busy ? "Kaydediliyor..." : "Koru"}
              </button>
              <button type="button" className="universal-btn-aux" disabled={busy} onClick={() => setKeepOpen(false)}>
                Vazgeç
              </button>
            </div>
          </div>
        </AppModal>
      ) : null}

      {reopenOpen ? (
        <AppModal title="İncelemeyi Yeniden Aç" onClose={() => { if (!busy) setReopenOpen(false); }}>
          <div data-testid="qr-puantaj-aday-reopen-modal">
            <p>Aynı QR adayı için koruma kararı kaldırılır; puantaj yazılmaz.</p>
            <label className="form-label" htmlFor="qr-reopen-reason">
              Gerekçe
            </label>
            <textarea
              id="qr-reopen-reason"
              className="form-input"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
            />
            <div className="form-actions-row">
              <button
                type="button"
                className="universal-btn"
                disabled={busy}
                onClick={() => void runDecision("REOPEN_REVIEW")}
              >
                {busy ? "Kaydediliyor..." : "Yeniden Aç"}
              </button>
              <button type="button" className="universal-btn-aux" disabled={busy} onClick={() => setReopenOpen(false)}>
                Vazgeç
              </button>
            </div>
          </div>
        </AppModal>
      ) : null}
    </div>
  );
}
