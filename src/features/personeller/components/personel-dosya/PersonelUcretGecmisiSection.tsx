import { useState } from "react";
import type { Personel } from "../../../../types/personel";
import { AppActionDialog } from "../../../../components/modal/AppActionDialog";
import { formatDetailValue } from "./personel-dosya-format-utils";
import {
  UCRET_GUNCEL_YOK_MESAJI,
  UCRET_IPTAL_ONAY_MESAJI,
  UCRET_KAYIT_YOK_MESAJI,
  formatUcretDurumLabel,
  formatUcretGecerlilikAraligi,
  formatUcretKaynakLabel,
  formatUcretOzeti,
  isUcretKaydiIptalEdilebilir
} from "./personel-ucret-utils";
import { PersonelUcretCreateModal } from "./PersonelUcretCreateModal";
import { usePersonelUcretGecmisi } from "./usePersonelUcretGecmisi";

export function PersonelUcretGecmisiSection({
  personel,
  canManageUcret,
  isActive,
  onBusyChange,
  onSalaryMutationSuccess,
  externalBusy = false
}: {
  personel: Personel;
  canManageUcret: boolean;
  isActive: boolean;
  onBusyChange?: (busy: boolean) => void;
  onSalaryMutationSuccess?: (updated: Personel) => void;
  /** Parent wage mutation (e.g. ücret tipi PUT) — disables salary actions. Card default false. */
  externalBusy?: boolean;
}) {
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [pendingCancelUcretId, setPendingCancelUcretId] = useState<number | null>(null);
  const [cancelDialogError, setCancelDialogError] = useState<string | null>(null);
  const {
    ucretler,
    aktifUcret,
    isLoading,
    errorMessage,
    fetchResolved,
    canFetch,
    isSubmitting,
    submitErrorMessage,
    clearSubmitError,
    submitUcret,
    cancellingUcretId,
    cancelErrorMessage,
    cancelUcret
  } = usePersonelUcretGecmisi({
    personel,
    canViewUcret: true,
    isActive,
    onBusyChange,
    onSalaryMutationSuccess
  });

  const wageActionsLocked = externalBusy || isSubmitting || cancellingUcretId !== null;
  const showLoading = canFetch && isLoading;
  const showError = canFetch && fetchResolved && !isLoading && Boolean(errorMessage);
  const showEmpty =
    canFetch && fetchResolved && !isLoading && !errorMessage && ucretler.length === 0;
  const showList =
    canFetch && fetchResolved && !isLoading && !errorMessage && ucretler.length > 0;

  function handleOpenCreateModal() {
    if (wageActionsLocked) {
      return;
    }
    clearSubmitError();
    setIsCreateModalOpen(true);
  }

  function openCancelUcretDialog(ucretId: number) {
    if (wageActionsLocked) {
      return;
    }
    setCancelDialogError(null);
    setPendingCancelUcretId(ucretId);
  }

  function closeCancelUcretDialog() {
    if (cancellingUcretId !== null) {
      return;
    }
    setPendingCancelUcretId(null);
    setCancelDialogError(null);
  }

  async function confirmCancelUcret() {
    if (pendingCancelUcretId == null || cancellingUcretId !== null || externalBusy) {
      return;
    }
    setCancelDialogError(null);
    const ok = await cancelUcret(pendingCancelUcretId);
    if (ok) {
      setPendingCancelUcretId(null);
      return;
    }
    setCancelDialogError("Ücret kaydı iptal edilemedi.");
  }

  async function handleCreate(payload: Parameters<typeof submitUcret>[0]) {
    if (externalBusy) {
      return false;
    }
    return submitUcret(payload);
  }

  return (
    <section
      className="personel-puantaj-summary-card personel-devam-primi-card"
      data-testid="personel-ucret-gecmisi-card"
    >
      <span className="personel-puantaj-summary-kicker">Ücret Geçmişi</span>
      <p className="personel-puantaj-summary-note">
        Ücret dönemleri buradan yönetilir; personel kartındaki maaş alanı yalnızca uyumluluk için
        senkron tutulur.
      </p>

      {canManageUcret ? (
        <button
          type="button"
          className="universal-btn-aux"
          onClick={handleOpenCreateModal}
          disabled={wageActionsLocked}
          data-testid="personel-ucret-yeni-donem"
        >
          Yeni Ücret Dönemi Başlat
        </button>
      ) : null}

      {showLoading ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-ucret-yukleniyor">
          Ücret geçmişi yükleniyor...
        </p>
      ) : null}

      {showError ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-ucret-hata">
          {errorMessage}
        </p>
      ) : null}

      {!showLoading && !showError && fetchResolved ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-ucret-guncel">
          {aktifUcret
            ? `Güncel ücret: ${formatUcretOzeti(aktifUcret)}`
            : UCRET_GUNCEL_YOK_MESAJI}
        </p>
      ) : null}

      {cancelErrorMessage && pendingCancelUcretId == null ? (
        <p className="personel-create-error" role="alert" data-testid="personel-ucret-iptal-hata">
          {cancelErrorMessage}
        </p>
      ) : null}

      {showEmpty ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-ucret-bos">
          {UCRET_KAYIT_YOK_MESAJI}
        </p>
      ) : null}

      {showList ? (
        <ul className="personel-surec-list personel-izin-list" data-testid="personel-ucret-list">
          {ucretler.map((item, index) => (
            <li
              key={item.id ?? `virtual-${index}`}
              className="personel-surec-card"
              data-testid={`personel-ucret-kayit-${item.id ?? `virtual-${index}`}`}
            >
              <span className="personel-surec-card-type">
                {formatUcretOzeti(item)}
                {item.guncel_mi ? " — Güncel" : ""}
              </span>
              <span className="personel-surec-card-dates">{formatUcretGecerlilikAraligi(item)}</span>
              <span className="personel-surec-card-state">
                {formatUcretDurumLabel(item.durum)} / {formatUcretKaynakLabel(item.kaynak)}
              </span>
              {item.aciklama ? (
                <span className="personel-surec-card-desc">{formatDetailValue(item.aciklama)}</span>
              ) : null}
              {canManageUcret && isUcretKaydiIptalEdilebilir(item) && typeof item.id === "number" ? (
                <button
                  type="button"
                  className="universal-btn-cancel"
                  onClick={() => openCancelUcretDialog(item.id as number)}
                  disabled={wageActionsLocked}
                  data-testid={`personel-ucret-iptal-${item.id}`}
                >
                  {cancellingUcretId === item.id ? "İptal ediliyor..." : "İptal Et"}
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      {canManageUcret ? (
        <PersonelUcretCreateModal
          isOpen={isCreateModalOpen}
          onClose={() => setIsCreateModalOpen(false)}
          onCreate={handleCreate}
          isSubmitting={isSubmitting}
          submitErrorMessage={submitErrorMessage}
        />
      ) : null}

      {pendingCancelUcretId != null ? (
        <AppActionDialog
          open
          testId="personel-ucret-action-dialog"
          title="Ücret Kaydını İptal Et"
          description={UCRET_IPTAL_ONAY_MESAJI}
          confirmLabel="İptal Et"
          submitLabel="İptal ediliyor..."
          destructive
          isSubmitting={cancellingUcretId === pendingCancelUcretId}
          errorMessage={cancelDialogError ?? cancelErrorMessage}
          onConfirm={confirmCancelUcret}
          onCancel={closeCancelUcretDialog}
        />
      ) : null}
    </section>
  );
}
