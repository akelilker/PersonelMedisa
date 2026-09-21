import { formatAktifDurumLabel } from "../../../../lib/display/enum-display";
import type { Personel } from "../../../../types/personel";
import { formatDetailValue } from "./personel-dosya-format-utils";

export function PersonelDosyaHero({ personel }: { personel: Personel }) {
  const durumLabel =
    personel.aktif_durum === "PASIF"
      ? formatDetailValue(personel.pasiflik_durumu_etiketi) !== "-"
        ? formatDetailValue(personel.pasiflik_durumu_etiketi)
        : formatAktifDurumLabel(personel.aktif_durum)
      : formatAktifDurumLabel(personel.aktif_durum);
  const fullName = [personel.ad, personel.soyad].filter(Boolean).join(" ");

  return (
    <header className="personel-dosya-hero" data-testid="personel-dosya-hero">
      <div className="personel-dosya-hero-identity">
        <h3 className="personel-dosya-hero-name">{fullName}</h3>
        <div className={`personel-dosya-status${personel.aktif_durum === "PASIF" ? " is-passive" : ""}`}>
          <span className="personel-dosya-status-dot" aria-hidden="true" />
          <span>{durumLabel}</span>
        </div>
      </div>
    </header>
  );
}
