import { formatPersonelAdSoyadDisplay } from "../../../lib/display/ad-soyad-display";
import type { Personel } from "../../../types/personel";

/**
 * Seçili personelin kimlik başlığı (tek sahip): Ad SOYAD + hemen sağında durum noktası.
 * Aktif: yalnız yeşil nokta (görünür metin yok; "Aktif" yalnız hover/erişilebilir ad).
 * Pasif: kırmızı nokta + pasiflik açıklaması. Bilinmeyen: gri nokta + "Durum Bilinmiyor".
 */
type KayitSurecPersonelNameHeadingProps = {
  personel: Personel;
  /** Verilirse durum noktasının hemen sağında kalem (Personeli Düzenle) gösterilir. */
  onEdit?: () => void;
  editDisabled?: boolean;
};

export function KayitSurecPersonelNameHeading({ personel, onEdit, editDisabled = false }: KayitSurecPersonelNameHeadingProps) {
  const isPasif = personel.aktif_durum === "PASIF";
  // Yalnız açıkça AKTIF olan kayıt aktif sayılır; boş/bilinmeyen durum yeşil gösterilmez.
  const isAktif = personel.aktif_durum === "AKTIF";
  const durumLabel = isPasif
    ? personel.pasiflik_durumu_etiketi ?? "Pasif"
    : isAktif
      ? "Aktif"
      : "Durum Bilinmiyor";
  const durumClass = isPasif ? " is-passive" : isAktif ? "" : " is-unknown";
  const displayName = formatPersonelAdSoyadDisplay(personel) || "Personel";

  return (
    <h3 className="surec-person-name-heading" data-testid="kayit-surec-personel-ad-soyad">
      <span>{displayName}</span>
      <span
        className={`surec-person-name-status${durumClass}`}
        role="img"
        aria-label={durumLabel}
        title={durumLabel}
        data-testid="kayit-surec-personel-durum"
      >
        <span className="surec-person-name-status-dot" aria-hidden="true" />
        {isAktif ? null : <span aria-hidden="true">{durumLabel}</span>}
      </span>
      {onEdit ? (
        <button
          type="button"
          className="surec-person-name-edit"
          aria-label="Personeli Düzenle"
          title="Personeli Düzenle"
          data-testid="kayit-surec-personel-duzenle"
          disabled={editDisabled}
          onClick={onEdit}
        >
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
            <path d="M12 20h9" />
            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" />
          </svg>
        </button>
      ) : null}
    </h3>
  );
}
