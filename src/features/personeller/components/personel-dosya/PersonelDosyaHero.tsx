import { formatAktifDurumLabel } from "../../../../lib/display/enum-display";
import type { Personel } from "../../../../types/personel";
import { isPersonelMaasMissing } from "../../personel-create-utils";
import { DossierField } from "./personel-dosya-dossier";
import { formatDetailValue, formatIsoDateDetail, formatReferenceValue } from "./personel-dosya-format-utils";

export function PersonelDosyaHero({
  personel,
  canViewUcret
}: {
  personel: Personel;
  /** Ücret görme yetkisi olmayan roller maaş eksikliği bilgisini de görmemeli. */
  canViewUcret: boolean;
}) {
  const durumLabel =
    personel.aktif_durum === "PASIF"
      ? formatDetailValue(personel.pasiflik_durumu_etiketi) !== "-"
        ? formatDetailValue(personel.pasiflik_durumu_etiketi)
        : formatAktifDurumLabel(personel.aktif_durum)
      : formatAktifDurumLabel(personel.aktif_durum);
  const sicil = formatDetailValue(personel.sicil_no);
  const departman = formatReferenceValue(personel.departman_adi, personel.departman_id);
  const gorev = formatReferenceValue(personel.gorev_adi, personel.gorev_id);
  const heroSummary = [sicil !== "-" ? `Sicil ${sicil}` : null, departman !== "-" ? departman : null, gorev !== "-" ? gorev : null]
    .filter((part): part is string => part != null)
    .join(" / ");

  return (
    <section className="personel-dosya-hero">
      <div className="personel-dosya-hero-head">
        <div className="personel-dosya-hero-copy">
          <p className="personel-dosya-kicker">Personel kartı</p>
          <h3>
            {personel.ad} {personel.soyad}
          </h3>
          <p className="personel-dosya-sub">{heroSummary || "Kurumsal personel kaydı"}</p>
        </div>
      </div>

      <div className="personel-dosya-hero-grid">
        <DossierField label="Ad" value={personel.ad} />
        <DossierField label="Soyad" value={personel.soyad} />
        <DossierField label="Sicil No" value={formatDetailValue(personel.sicil_no)} />
        <DossierField label="Departman" value={formatReferenceValue(personel.departman_adi, personel.departman_id)} />
        <DossierField label="Bölüm" value={formatReferenceValue(personel.bolum_adi, personel.bolum_id)} />
        <DossierField label="Birim" value={formatReferenceValue(personel.birim_adi, personel.birim_id)} />
        <DossierField label="Unvan" value={formatReferenceValue(personel.gorev_adi, personel.gorev_id)} />
        <DossierField label="Pozisyon" value={formatReferenceValue(personel.pozisyon_adi, personel.pozisyon_id)} />
        <DossierField
          label="Personel Tipi"
          value={formatReferenceValue(personel.personel_tipi_adi, personel.personel_tipi_id)}
        />
        <DossierField
          label="Çalışma Durumu"
          value={durumLabel}
          valueClassName={
            personel.aktif_durum === "PASIF"
              ? "personel-dosya-field-value personel-dosya-field-value--danger"
              : "personel-dosya-field-value"
          }
        />
        <DossierField label="İşe Giriş Tarihi" value={formatIsoDateDetail(personel.ise_giris_tarihi)} />
      </div>

      {canViewUcret && isPersonelMaasMissing(personel.maas_tutari, personel.net_maas_tutari) ? (
        <p className="personel-dosya-maas-alert" data-testid="personel-maas-eksik-uyari">
          Maaş bilgisi eksik.
        </p>
      ) : null}
    </section>
  );
}
