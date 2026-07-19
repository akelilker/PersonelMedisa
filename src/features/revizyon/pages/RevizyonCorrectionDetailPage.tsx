import { useCallback, useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { ApiRequestError } from "../../../api/api-client";
import {
  cancelRevizyonCorrection,
  fetchRevizyonCorrectionDetail
} from "../../../api/revizyon-correction.api";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { RevizyonCorrectionEvent } from "../../../types/revizyon-correction";
import { formatRevizyonDeger, revizyonUserMessage } from "../revizyon-display";

export function RevizyonCorrectionDetailPage() {
  const { correctionId } = useParams();
  const navigate = useNavigate();
  const { hasPermission } = useRoleAccess();
  const canCancel = hasPermission("revizyon.approve");
  const canViewFinance = hasPermission("revizyon.view_finance_effect");

  const [correction, setCorrection] = useState<RevizyonCorrectionEvent | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [isActing, setIsActing] = useState(false);

  const load = useCallback(async () => {
    if (!correctionId) {
      return;
    }
    setIsLoading(true);
    setErrorMessage(null);
    try {
      setCorrection(await fetchRevizyonCorrectionDetail(correctionId));
    } catch (error) {
      const code = error instanceof ApiRequestError ? error.code : undefined;
      setErrorMessage(
        revizyonUserMessage(code, error instanceof Error ? error.message : "Detay yüklenemedi.")
      );
    } finally {
      setIsLoading(false);
    }
  }, [correctionId]);

  useEffect(() => {
    void load();
  }, [load]);

  if (isLoading) {
    return <LoadingState label="Correction detayı yükleniyor..." />;
  }
  if (errorMessage || !correction) {
    return <ErrorState message={errorMessage ?? "Kayıt bulunamadı."} onRetry={() => void load()} />;
  }

  return (
    <section className="states-page" data-testid="revizyon-correction-detay">
      <div className="universal-btn-group" style={{ marginBottom: "1rem" }}>
        <Link className="universal-btn-aux" to="/haftalik-kapanis/revizyonlar?gorunum=corrections">
          Listeye dön
        </Link>
        <button
          type="button"
          className="universal-btn-aux"
          onClick={() =>
            navigate(`/haftalik-kapanis/revizyonlar/${correction.revizyon_talebi_id}`)
          }
        >
          Revizyon talebine git
        </button>
      </div>

      <h2>Correction Detayı</h2>
      <dl className="dossier-grid">
        <div>
          <dt>Correction</dt>
          <dd>{correction.id}</dd>
        </div>
        <div>
          <dt>Personel</dt>
          <dd>{correction.personel_ad_soyad ?? `#${correction.personel_id}`}</dd>
        </div>
        <div>
          <dt>Hafta</dt>
          <dd>
            {correction.hafta_baslangic} → {correction.hafta_bitis}
          </dd>
        </div>
        <div>
          <dt>Etkilenen tarih</dt>
          <dd>{correction.etkilenen_tarih}</dd>
        </div>
        <div>
          <dt>Tip</dt>
          <dd>{correction.correction_tipi}</dd>
        </div>
        <div>
          <dt>Önceki değer</dt>
          <dd>{formatRevizyonDeger(correction.onceki_deger as never)}</dd>
        </div>
        <div>
          <dt>Yeni değer</dt>
          <dd>{formatRevizyonDeger(correction.yeni_deger as never)}</dd>
        </div>
        <div>
          <dt>Delta dakika / gün</dt>
          <dd>
            {correction.delta_dakika} / {correction.delta_gun}
          </dd>
        </div>
        {canViewFinance ? (
          <div>
            <dt>Bordro etkisi</dt>
            <dd>
              {correction.bordro_etki_var_mi ? "Var" : "Yok"}
              {correction.bordro_etki_tipi ? ` (${correction.bordro_etki_tipi})` : ""}
            </dd>
          </div>
        ) : null}
        <div>
          <dt>Durum</dt>
          <dd>
            <span className="personeller-status-badge">
              {correction.iptal_edildi_mi ? "İptal" : "Aktif"}
            </span>
          </dd>
        </div>
        <div>
          <dt>Oluşturma</dt>
          <dd>{new Date(correction.olusturma_zamani).toLocaleString("tr-TR")}</dd>
        </div>
      </dl>

      <p className="form-hint">
        Bu correction kaydı görünürlük amaçlıdır; puantaj/rapor/bordro motorunu otomatik yeniden
        hesaplamaz. Ham snapshot değişmez.
      </p>

      {canCancel && !correction.iptal_edildi_mi ? (
        <button
          type="button"
          className="universal-btn-cancel"
          disabled={isActing}
          onClick={() => {
            if (!window.confirm("Aktif correction iptal edilsin mi?")) {
              return;
            }
            const aciklama = window.prompt("İptal açıklaması (opsiyonel):") ?? "";
            setIsActing(true);
            setActionError(null);
            void (async () => {
              try {
                const next = await cancelRevizyonCorrection(correction.id, {
                  aciklama: aciklama || null
                });
                setCorrection(next);
                setActionMessage("Correction iptal edildi.");
              } catch (error) {
                const code = error instanceof ApiRequestError ? error.code : undefined;
                setActionError(
                  revizyonUserMessage(
                    code,
                    error instanceof Error ? error.message : "İptal başarısız."
                  )
                );
              } finally {
                setIsActing(false);
              }
            })();
          }}
        >
          Correction İptal
        </button>
      ) : null}

      {actionMessage ? <p className="workspace-success">{actionMessage}</p> : null}
      {actionError ? (
        <p className="workspace-error" role="alert">
          {actionError}
        </p>
      ) : null}
    </section>
  );
}
