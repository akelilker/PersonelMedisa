import { useState } from "react";
import type { Personel } from "../../../../types/personel";
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
  isActive
}: {
  personel: Personel;
  canManageUcret: boolean;
  isActive: boolean;
}) {
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
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
  } = usePersonelUcretGecmisi({ personel, canViewUcret: true, isActive });

  const showLoading = canFetch && isLoading;
  const showError = canFetch && fetchResolved && !isLoading && Boolean(errorMessage);
  const showEmpty =
    canFetch && fetchResolved && !isLoading && !errorMessage && ucretler.length === 0;
  const showList =
    canFetch && fetchResolved && !isLoading && !errorMessage && ucretler.length > 0;

  function handleOpenCreateModal() {
    clearSubmitError();
    setIsCreateModalOpen(true);
  }

  function handleCancelUcret(ucretId: number) {
    if (!window.confirm(UCRET_IPTAL_ONAY_MESAJI)) {
      return;
    }
    void cancelUcret(ucretId);
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

      {cancelErrorMessage ? (
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
                  onClick={() => handleCancelUcret(item.id as number)}
                  disabled={cancellingUcretId !== null}
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
          onCreate={submitUcret}
          isSubmitting={isSubmitting}
          submitErrorMessage={submitErrorMessage}
        />
      ) : null}
    </section>
  );
}
