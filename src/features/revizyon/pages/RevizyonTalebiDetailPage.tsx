import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { ApiRequestError } from "../../../api/api-client";
import {
  cancelRevizyonCorrection,
  produceRevizyonCorrection
} from "../../../api/revizyon-correction.api";
import {
  approveRevizyonTalebi,
  cancelRevizyonTalebi,
  fetchRevizyonTalebiDetail,
  rejectRevizyonTalebi,
  submitRevizyonTalebi
} from "../../../api/revizyon-talebi.api";
import { FormField } from "../../../components/form/FormField";
import { AppActionDialog } from "../../../components/modal/AppActionDialog";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useAuth } from "../../../state/auth.store";
import type { RevizyonTalebi } from "../../../types/revizyon-talebi";
import {
  formatRevizyonDeger,
  formatRevizyonDurumLabel,
  formatRevizyonTipiLabel,
  revizyonUserMessage
} from "../revizyon-display";

type PendingRevizyonAction =
  | { type: "approve" }
  | { type: "reject" }
  | { type: "cancel-request" }
  | { type: "cancel-correction" }
  | null;

const ACTION_DIALOG_COPY = {
  approve: {
    title: "Revizyon Talebini Onayla",
    description: "Bu revizyon talebi onaylanacaktır. İşleme devam edilsin mi?",
    confirmLabel: "Talebi Onayla",
    submitLabel: "Talep onaylanıyor...",
    destructive: false
  },
  reject: {
    title: "Revizyon Talebini Reddet",
    description: "Bu revizyon talebi reddedilecektir. İşleme devam edilsin mi?",
    confirmLabel: "Talebi Reddet",
    submitLabel: "Talep reddediliyor...",
    destructive: true
  },
  "cancel-request": {
    title: "Revizyon Talebini İptal Et",
    description: "Bu revizyon talebi iptal edilecektir. İşleme devam edilsin mi?",
    confirmLabel: "Talebi İptal Et",
    submitLabel: "Talep iptal ediliyor...",
    destructive: true
  },
  "cancel-correction": {
    title: "Düzeltme Kaydını İptal Et",
    description: "Aktif düzeltme kaydı iptal edilecektir.",
    confirmLabel: "Düzeltme Kaydını İptal Et",
    submitLabel: "Düzeltme kaydı iptal ediliyor...",
    destructive: true
  }
} as const;

export function RevizyonTalebiDetailPage() {
  const { revizyonId } = useParams();
  const navigate = useNavigate();
  const { session } = useAuth();
  const { hasPermission } = useRoleAccess();
  const canApprove = hasPermission("revizyon.approve");
  const canReject = hasPermission("revizyon.reject");
  const canSubmit = hasPermission("revizyon.submit");
  const canCancel = hasPermission("revizyon.cancel");
  const canViewFinance = hasPermission("revizyon.view_finance_effect");
  const canViewAudit = hasPermission("revizyon.view_audit_history");

  const [talep, setTalep] = useState<RevizyonTalebi | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [kararNotu, setKararNotu] = useState("");
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [isActing, setIsActing] = useState(false);
  const [pendingAction, setPendingAction] = useState<PendingRevizyonAction>(null);
  const [cancelReason, setCancelReason] = useState("");
  const isActingRef = useRef(false);

  const load = useCallback(async () => {
    if (!revizyonId) {
      return;
    }
    setIsLoading(true);
    setErrorMessage(null);
    try {
      setTalep(await fetchRevizyonTalebiDetail(revizyonId));
    } catch (error) {
      const code = error instanceof ApiRequestError ? error.code : undefined;
      setErrorMessage(
        revizyonUserMessage(code, error instanceof Error ? error.message : "Detay yüklenemedi.")
      );
    } finally {
      setIsLoading(false);
    }
  }, [revizyonId]);

  useEffect(() => {
    void load();
  }, [load]);

  async function runAction(
    action: () => Promise<RevizyonTalebi>,
    success: string
  ): Promise<boolean> {
    if (isActingRef.current) {
      return false;
    }
    isActingRef.current = true;
    setIsActing(true);
    setActionError(null);
    setActionMessage(null);
    try {
      const next = await action();
      setTalep(next);
      // Action yaniti audit/enrichment icermeyebilir; detail GET canonical sunumdur.
      if (revizyonId) {
        try {
          setTalep(await fetchRevizyonTalebiDetail(revizyonId));
        } catch {
          /* action basarili; enrichment yenileme opsiyonel */
        }
      }
      setActionMessage(success);
      return true;
    } catch (error) {
      const code = error instanceof ApiRequestError ? error.code : undefined;
      setActionError(
          revizyonUserMessage(code, error instanceof Error ? error.message : "İşlem başarısız.")
      );
      return false;
    } finally {
      isActingRef.current = false;
      setIsActing(false);
    }
  }

  if (isLoading) {
    return <LoadingState label="Revizyon detayı yükleniyor..." />;
  }
  if (errorMessage || !talep) {
    return <ErrorState message={errorMessage ?? "Kayıt bulunamadı."} onRetry={() => void load()} />;
  }

  const isOwner =
    session?.user?.id !== undefined && Number(session.user.id) === talep.talep_eden_kullanici_id;
  const showSubmit = canSubmit && talep.durum === "TASLAK" && (isOwner || canApprove);
  const showCancel =
    canCancel &&
    (talep.durum === "TASLAK" || talep.durum === "ONAY_BEKLIYOR") &&
    (isOwner || canApprove);
  const showApprove = canApprove && talep.durum === "ONAY_BEKLIYOR";
  const showReject = canReject && talep.durum === "ONAY_BEKLIYOR";
  const showProduce =
    canApprove && talep.durum === "ONAYLANDI" && !talep.correction_event_id;
  const showCorrectionLink = Boolean(talep.correction_event_id);
  const dialogCopy = pendingAction ? ACTION_DIALOG_COPY[pendingAction.type] : null;

  function openPendingAction(type: NonNullable<PendingRevizyonAction>["type"]) {
    if (isActingRef.current) {
      return;
    }
    setActionError(null);
    setActionMessage(null);
    if (type === "cancel-correction") {
      setCancelReason("");
    }
    setPendingAction({ type });
  }

  function closePendingAction() {
    if (isActingRef.current) {
      return;
    }
    setPendingAction(null);
    setCancelReason("");
    setActionError(null);
  }

  async function confirmPendingAction() {
    const action = pendingAction;
    const currentTalep = talep;
    if (!action || !currentTalep) {
      return;
    }

    let success = false;
    switch (action.type) {
      case "approve":
        success = await runAction(
          () => approveRevizyonTalebi(currentTalep.id, { karar_notu: kararNotu || null }),
          "Talep onaylandı."
        );
        break;
      case "reject":
        success = await runAction(
          () => rejectRevizyonTalebi(currentTalep.id, { karar_notu: kararNotu }),
          "Talep reddedildi."
        );
        break;
      case "cancel-request":
        success = await runAction(
          () => cancelRevizyonTalebi(currentTalep.id, { karar_notu: kararNotu || null }),
          "Talep iptal edildi."
        );
        break;
      case "cancel-correction":
        if (!currentTalep.correction_event_id) {
          return;
        }
        success = await runAction(async () => {
          await cancelRevizyonCorrection(currentTalep.correction_event_id!, {
            aciklama: cancelReason.trim() || null
          });
          return fetchRevizyonTalebiDetail(currentTalep.id);
        }, "Düzeltme kaydı iptal edildi.");
        break;
    }

    if (success) {
      setPendingAction(null);
      setCancelReason("");
    }
  }

  return (
    <section className="states-page" data-testid="revizyon-talep-detay">
      <div className="universal-btn-group universal-btn-group--mb">
        <Link className="universal-btn-aux" to="/haftalik-kapanis/revizyonlar">
          Listeye dön
        </Link>
      </div>

      <h2>Revizyon Talebi</h2>
      <dl className="dossier-grid">
        <div>
          <dt>Personel</dt>
          <dd>{talep.personel_ad_soyad ?? `#${talep.personel_id}`}</dd>
        </div>
        <div>
          <dt>Sicil</dt>
          <dd>{talep.sicil_no ?? "—"}</dd>
        </div>
        <div>
          <dt>Şube</dt>
          <dd>{talep.sube_adi ?? "—"}</dd>
        </div>
        <div>
          <dt>Departman</dt>
          <dd>{talep.departman_adi ?? "—"}</dd>
        </div>
        <div>
          <dt>Hafta</dt>
          <dd>
            {talep.hafta_baslangic} → {talep.hafta_bitis}
          </dd>
        </div>
        <div>
          <dt>Etkilenen tarih</dt>
          <dd>{talep.etkilenen_tarih}</dd>
        </div>
        <div>
          <dt>Kaynak türü</dt>
          <dd>{talep.kaynak_tipi}</dd>
        </div>
        <div>
          <dt>Revizyon tipi</dt>
          <dd>{formatRevizyonTipiLabel(talep.revizyon_tipi)}</dd>
        </div>
        <div>
          <dt>Durum</dt>
          <dd>
            <span className="personeller-status-badge">{formatRevizyonDurumLabel(talep.durum)}</span>
          </dd>
        </div>
      </dl>

      <h3>Değer ayrımı</h3>
      <dl className="dossier-grid" data-testid="revizyon-deger-ayrimi">
        <div>
          <dt>Kapanıştaki ham değer</dt>
          <dd data-testid="revizyon-ham-deger">{formatRevizyonDeger(talep.onceki_deger)}</dd>
        </div>
        <div>
          <dt>Talep edilen değer</dt>
          <dd data-testid="revizyon-talep-deger">{formatRevizyonDeger(talep.talep_edilen_deger)}</dd>
        </div>
        <div>
          <dt>Aktif düzeltme kaydı sonrası değer</dt>
          <dd data-testid="revizyon-corrected-deger">
            {talep.aktif_correction_var_mi
              ? formatRevizyonDeger(talep.aktif_correction_sonrasi_deger ?? null)
              : "Aktif düzeltme kaydı yok"}
          </dd>
        </div>
      </dl>
      <p className="form-hint" data-testid="revizyon-overlay-uyari">
        Düzeltme kaydı görünürlüğü ile gerçek rapor/bordro etkisi aynı şey değildir. Ham kapanış kaydı
        değişmez.
      </p>

      <dl className="dossier-grid">
        <div>
          <dt>Gerekçe</dt>
          <dd>{talep.gerekce}</dd>
        </div>
        {canViewFinance ? (
          <div data-testid="revizyon-detail-bordro-alani">
            <div>
              <dt>Bordro etkisi</dt>
              <dd>{talep.bordro_etki_var_mi ? "Var" : "Yok"}</dd>
            </div>
            <div>
              <dt>Bordro etki notu</dt>
              <dd>{talep.bordro_etki_notu ?? "—"}</dd>
            </div>
          </div>
        ) : null}
        <div>
          <dt>Talep eden</dt>
          <dd>
            {talep.talep_eden_kullanici_adi ?? "—"} ·{" "}
            {new Date(talep.talep_zamani).toLocaleString("tr-TR")}
          </dd>
        </div>
        <div>
          <dt>Karar veren</dt>
          <dd>
            {talep.karar_veren_kullanici_adi ?? "—"}
            {talep.karar_zamani
              ? ` · ${new Date(talep.karar_zamani).toLocaleString("tr-TR")}`
              : ""}
          </dd>
        </div>
        <div>
          <dt>Karar notu</dt>
          <dd>{talep.karar_notu ?? "—"}</dd>
        </div>
      </dl>

      {canViewAudit && talep.audit_gecmisi && talep.audit_gecmisi.length > 0 ? (
        <>
          <h3>İşlem geçmişi</h3>
          <ul data-testid="revizyon-audit-gecmisi">
            {talep.audit_gecmisi.map((item, index) => (
              <li key={`${item.islem_zamani}-${index}`}>
                {item.aksiyon}: {item.onceki_durum ?? "—"} → {item.sonraki_durum} (
                {item.islem_yapan_kullanici_adi ?? "kullanıcı"} ·{" "}
                {new Date(item.islem_zamani).toLocaleString("tr-TR")})
                {item.aciklama ? ` — ${item.aciklama}` : ""}
              </li>
            ))}
          </ul>
        </>
      ) : null}

      {(showApprove || showReject) && (
        <FormField
          label="Karar notu"
          name="karar_notu"
          value={kararNotu}
          onChange={setKararNotu}
        />
      )}

      <div className="universal-btn-group universal-btn-group--wrap universal-btn-group--gap-2">
        {showSubmit ? (
          <button
            type="button"
            className="universal-btn-save"
            disabled={isActing}
            data-testid="revizyon-onaya-gonder"
            onClick={() =>
              void runAction(() => submitRevizyonTalebi(talep.id), "Talep onaya gönderildi.")
            }
          >
            Onaya Gönder
          </button>
        ) : null}
        {showApprove ? (
          <button
            type="button"
            className="universal-btn-save"
            disabled={isActing}
            data-testid="revizyon-onayla"
            onClick={() => openPendingAction("approve")}
          >
            Onayla
          </button>
        ) : null}
        {showReject ? (
          <button
            type="button"
            className="universal-btn-cancel"
            disabled={isActing}
            data-testid="revizyon-reddet"
            onClick={() => {
              if (!kararNotu.trim()) {
                setActionError("Red için karar notu zorunludur.");
                document.getElementById("karar_notu")?.focus();
                return;
              }
              openPendingAction("reject");
            }}
          >
            Reddet
          </button>
        ) : null}
        {showCancel ? (
          <button
            type="button"
            className="universal-btn-cancel"
            disabled={isActing}
            data-testid="revizyon-talep-iptal"
            onClick={() => openPendingAction("cancel-request")}
          >
            İptal
          </button>
        ) : null}
        {showProduce ? (
          <button
            type="button"
            className="universal-btn-save"
            disabled={isActing}
            data-testid="revizyon-correction-uret"
            onClick={() =>
              void runAction(async () => {
                await produceRevizyonCorrection(talep.id);
                return fetchRevizyonTalebiDetail(talep.id);
              }, "Düzeltme kaydı oluşturuldu.")
            }
          >
            Düzeltme Kaydı Oluştur
          </button>
        ) : null}
        {showCorrectionLink && talep.correction_event_id ? (
          <button
            type="button"
            className="universal-btn-aux"
            data-testid="revizyon-correction-detay-git"
            onClick={() => navigate(`/haftalik-kapanis/corrections/${talep.correction_event_id}`)}
          >
            Düzeltme kaydı detayına git
          </button>
        ) : null}
        {talep.aktif_correction_var_mi && canApprove && talep.correction_event_id ? (
          <button
            type="button"
            className="universal-btn-cancel"
            disabled={isActing}
            data-testid="revizyon-correction-iptal"
            onClick={() => openPendingAction("cancel-correction")}
          >
            Düzeltme Kaydını İptal Et
          </button>
        ) : null}
      </div>

      {pendingAction && dialogCopy ? (
        <AppActionDialog
          open
          title={dialogCopy.title}
          description={dialogCopy.description}
          confirmLabel={dialogCopy.confirmLabel}
          submitLabel={dialogCopy.submitLabel}
          destructive={dialogCopy.destructive}
          isSubmitting={isActing}
          field={
            pendingAction.type === "cancel-correction"
              ? {
                  label: "İptal açıklaması",
                  value: cancelReason,
                  onChange: setCancelReason,
                  placeholder: "İptal nedenini yazabilirsiniz",
                  rows: 4,
                  testId: "revizyon-action-dialog-input"
                }
              : undefined
          }
          errorMessage={actionError}
          errorTestId="revizyon-action-error"
          testId="revizyon-action-dialog"
          onConfirm={confirmPendingAction}
          onCancel={closePendingAction}
        />
      ) : null}

      {actionMessage ? (
        <p className="workspace-success" data-testid="revizyon-action-success">
          {actionMessage}
        </p>
      ) : null}
      {actionError && !pendingAction ? (
        <p className="workspace-error" role="alert" data-testid="revizyon-action-error">
          {actionError}
        </p>
      ) : null}
    </section>
  );
}
