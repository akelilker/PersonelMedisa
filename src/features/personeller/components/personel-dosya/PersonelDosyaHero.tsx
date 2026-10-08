import { formatAktifDurumLabel, formatCalisanKapsamiLabel } from "../../../../lib/display/enum-display";
import type { Personel } from "../../../../types/personel";
import { formatDetailValue, formatReferenceValue } from "./personel-dosya-format-utils";

function metaPart(value: string): string | null {
  const trimmed = value.trim();
  if (!trimmed || trimmed === "-") {
    return null;
  }
  return trimmed;
}

export function PersonelDosyaHero({ personel }: { personel: Personel }) {
  const isPassive = personel.aktif_durum === "PASIF";
  // Yalnız açıkça AKTIF olan kayıt aktif sayılır; boş/bilinmeyen durum yeşil gösterilmez.
  const isActive = personel.aktif_durum === "AKTIF";
  const aktifDurumLabel = formatAktifDurumLabel(personel.aktif_durum);
  const durumLabel = isPassive
    ? formatDetailValue(personel.pasiflik_durumu_etiketi) !== "-"
      ? formatDetailValue(personel.pasiflik_durumu_etiketi)
      : aktifDurumLabel
    : aktifDurumLabel !== "-"
      ? aktifDurumLabel
      : "Durum Bilinmiyor";
  const statusClass = isPassive ? " is-passive" : isActive ? "" : " is-unknown";
  const fullName = [personel.ad, personel.soyad].filter(Boolean).join(" ");
  const kapsamLabel = formatCalisanKapsamiLabel(personel.calisan_kapsami ?? "IC_PERSONEL");
  const sicilLabel = formatDetailValue(personel.sicil_no);
  const orgMeta = [
    metaPart(formatReferenceValue(personel.sube_adi, personel.sube_id)),
    metaPart(formatReferenceValue(personel.departman_adi, personel.departman_id)),
    metaPart(formatReferenceValue(personel.gorev_adi, personel.gorev_id))
  ].filter((part): part is string => part != null);

  return (
    <header className="personel-dosya-hero" data-testid="personel-dosya-hero">
      <div className="personel-dosya-hero-identity">
        <h3 className="personel-dosya-hero-name">{fullName}</h3>
        {/* #345 kararı: aktif personelde yalnız yeşil nokta (görünür "Çalışıyor"/pill yok);
            pasif/bilinmeyen durumda nokta + açıklama görünür. Erişilebilir ad her durumda korunur. */}
        <div
          className={`personel-dosya-status${statusClass}`}
          aria-label={durumLabel}
          title={durumLabel}
          data-testid="personel-dosya-hero-status"
        >
          <span className="personel-dosya-status-dot" aria-hidden="true" />
          {isActive ? null : <span className="personel-dosya-status-label">{durumLabel}</span>}
        </div>
      </div>

      <div className="personel-dosya-hero-chips" data-testid="personel-dosya-hero-chips">
        <span className="personel-dosya-hero-chip" data-testid="personel-dosya-hero-sicil">
          <span className="personel-dosya-hero-chip-label">Sicil</span>
          <strong className="personel-dosya-hero-chip-value">{sicilLabel}</strong>
        </span>
        <span className="personel-dosya-hero-chip" data-testid="personel-dosya-hero-kapsam">
          <span className="personel-dosya-hero-chip-label">Kapsam</span>
          <strong className="personel-dosya-hero-chip-value">{kapsamLabel}</strong>
        </span>
      </div>

      {orgMeta.length > 0 ? (
        <p className="personel-dosya-hero-org" data-testid="personel-dosya-hero-org">
          {orgMeta.join(" · ")}
        </p>
      ) : null}
    </header>
  );
}
