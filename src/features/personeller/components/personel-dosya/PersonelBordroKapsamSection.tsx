import { useState } from "react";
import type { Personel } from "../../../../types/personel";
import { formatDetailValue } from "./personel-dosya-format-utils";
import { PersonelBordroKapsamCreateModal } from "./PersonelBordroKapsamCreateModal";
import { usePersonelBordroKapsam } from "./usePersonelBordroKapsam";

function formatAralik(baslangic: string, bitis: string | null): string {
  return bitis ? `${baslangic} → ${bitis}` : `${baslangic} → (açık)`;
}

function formatNeden(kod: string): string {
  switch (kod) {
    case "DEMO_TEST_VERISI":
      return "Demo / test verisi";
    case "BORDRO_DISI_STATU":
      return "Bordro dışı statü";
    case "HARICI_BORDRO":
      return "Harici bordro";
    case "DIGER_ONAYLI_NEDEN":
      return "Diğer onaylı neden";
    default:
      return kod;
  }
}

export function PersonelBordroKapsamSection({
  personel,
  canManage,
  canApprove,
  isActive
}: {
  personel: Personel;
  canManage: boolean;
  canApprove: boolean;
  isActive: boolean;
}) {
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const {
    kayitlar,
    isLoading,
    errorMessage,
    fetchResolved,
    canFetch,
    isSubmitting,
    submitErrorMessage,
    clearSubmitError,
    dryRunResult,
    setDryRunResult,
    runDryRun,
    submitCreate,
    submitForApproval,
    approve,
    cancel,
    actionErrorMessage
  } = usePersonelBordroKapsam({ personel, canView: true, isActive });

  const showLoading = canFetch && isLoading;
  const showError = canFetch && fetchResolved && !isLoading && Boolean(errorMessage);
  const showEmpty =
    canFetch && fetchResolved && !isLoading && !errorMessage && kayitlar.length === 0;
  const showList =
    canFetch && fetchResolved && !isLoading && !errorMessage && kayitlar.length > 0;

  const aktifOnayliHaric = kayitlar.find(
    (k) => k.state === "ONAYLANDI" && k.durum === "HARIC" && !k.gecerlilik_bitis
  );

  return (
    <section
      className="personel-puantaj-summary-card personel-devam-primi-card"
      data-testid="personel-bordro-kapsam-card"
    >
      <span className="personel-puantaj-summary-kicker">Bordro Kapsam</span>
      <p className="personel-puantaj-summary-note">
        Personelin tarih aralığında maaş/bordro hesaplama kapsamına dahil veya hariç olduğu
        kararlar burada yönetilir. Mevcut snapshot satırları değiştirilmez.
      </p>

      <p className="personel-puantaj-summary-note" data-testid="personel-bordro-kapsam-ozet">
        Güncel durum:{" "}
        {aktifOnayliHaric
          ? `HARİÇ (${formatNeden(aktifOnayliHaric.neden_kodu)})`
          : "DAHİL (varsayılan)"}
      </p>

      {canManage ? (
        <button
          type="button"
          className="universal-btn-aux"
          onClick={() => {
            clearSubmitError();
            setDryRunResult(null);
            setIsCreateModalOpen(true);
          }}
          data-testid="personel-bordro-kapsam-yeni"
        >
          Yeni Kapsam Kararı
        </button>
      ) : null}

      {showLoading ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-bordro-kapsam-yukleniyor">
          Bordro kapsam kayıtları yükleniyor...
        </p>
      ) : null}

      {showError ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-bordro-kapsam-hata">
          {errorMessage}
        </p>
      ) : null}

      {showEmpty ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-bordro-kapsam-bos">
          Kayıtlı kapsam kararı yok; personel varsayılan olarak dahildir.
        </p>
      ) : null}

      {actionErrorMessage ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-bordro-kapsam-aksiyon-hata">
          {actionErrorMessage}
        </p>
      ) : null}

      {showList ? (
        <ul className="personel-ucret-list" data-testid="personel-bordro-kapsam-liste">
          {kayitlar.map((item) => (
            <li key={item.id} data-testid={`personel-bordro-kapsam-row-${item.id}`}>
              <strong>
                {item.durum} / {item.state}
              </strong>
              <span> — {formatNeden(item.neden_kodu)}</span>
              <div>{formatAralik(item.gecerlilik_baslangic, item.gecerlilik_bitis)}</div>
              <div>{formatDetailValue(item.aciklama)}</div>
              {item.onay_zamani ? <div>Onay: {item.onay_zamani}</div> : null}
              <div className="personel-ucret-actions">
                {canManage && item.state === "TASLAK" ? (
                  <button
                    type="button"
                    className="universal-btn-aux"
                    onClick={() => void submitForApproval(item.id)}
                    data-testid={`personel-bordro-kapsam-submit-${item.id}`}
                  >
                    Onaya Gönder
                  </button>
                ) : null}
                {canApprove && (item.state === "TASLAK" || item.state === "ONAY_BEKLIYOR") ? (
                  <button
                    type="button"
                    className="universal-btn-aux"
                    onClick={() => void approve(item.id)}
                    data-testid={`personel-bordro-kapsam-approve-${item.id}`}
                  >
                    Onayla
                  </button>
                ) : null}
                {canManage && item.state !== "IPTAL" ? (
                  <button
                    type="button"
                    className="universal-btn-aux"
                    onClick={() => {
                      const neden = window.prompt("İptal nedeni:");
                      if (neden && neden.trim().length >= 3) {
                        void cancel(item.id, neden.trim());
                      }
                    }}
                    data-testid={`personel-bordro-kapsam-cancel-${item.id}`}
                  >
                    İptal
                  </button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      ) : null}

      {canManage ? (
        <PersonelBordroKapsamCreateModal
          isOpen={isCreateModalOpen}
          onClose={() => setIsCreateModalOpen(false)}
          canApprove={canApprove}
          isSubmitting={isSubmitting}
          submitErrorMessage={submitErrorMessage}
          dryRunResult={dryRunResult}
          onDryRun={runDryRun}
          onCreate={submitCreate}
        />
      ) : null}
    </section>
  );
}
